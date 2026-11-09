<?php

use App\Contracts\RewardsAuthorization;
use App\Models\BusinessMembership;
use App\Models\Permission;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\RewardsAuthorizationService;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;
use Tests\Support\SyntheticDomainFixtures as Fixtures;

beforeEach(function () {
    Fixtures::seed($this);
    $this->subject = User::factory()->create();
    $this->subjectId = (string) $this->subject->getKey();
    $this->engine = Fixtures::rewards();
});

test('rewards infrastructure evaluates recognized rights with a synthetic policy not a production mapping', function (string $capability, ?string $scope) {
    $id = $scope === 'business' ? 'A' : null;
    if ($scope) {
        BusinessMembership::create(['user_id' => $this->subjectId, 'business_id' => 'A', 'status' => 'active']);
    }
    Fixtures::assignment($this->subject, $scope ? 'fixture_rewards_business' : 'fixture_rewards_global', $scope, $id);
    expect($this->engine->allows($this->subjectId, $capability, $scope, $id))->toBeTrue()
        ->and(app(RewardsAuthorization::class))->toBeInstanceOf(RewardsAuthorizationService::class)
        ->and(app(RewardsAuthorization::class)->allows($this->subjectId, $capability, $scope, $id))->toBeFalse();
})->with([
    ['rewards.program.manage', null], ['rewards.catalog.manage', null], ['rewards.config.approve', null],
    ['audit.view', null], ['analytics.view', null], ['rewards.catalog.manage', 'business'],
    ['rewards.redemption.deliver', 'business'], ['analytics.view', 'business'],
]);

test('rewards rejects capabilities from other domains and incompatible contexts', function (string $capability, ?string $scope, ?string $id) {
    Fixtures::assignment($this->subject, 'fixture_rewards_global');
    expect($this->engine->allows($this->subjectId, $capability, $scope, $id))->toBeFalse();
})->with([
    ['business.manage', null, null], ['bonuses.beca.issue', null, null], ['academic.program.coordinate', null, null],
    ['unknown.permission', null, null], ['audit.write', null, null], ['analytics.write', null, null],
    ['audit.view', 'business', 'A'], ['rewards.program.manage', 'business', 'A'],
    ['rewards.config.approve', 'business', 'A'], ['rewards.redemption.deliver', null, null],
    ['analytics.view', 'campus', 'A'], ['analytics.view', null, 'A'], ['analytics.view', 'business', null],
    ['analytics.view', 'business', ''], ['analytics.view', 'platform', 'A'],
]);

test('rewards business rights stay exact and require a presently active membership', function () {
    $membership = BusinessMembership::create(['user_id' => $this->subjectId, 'business_id' => 'A', 'status' => 'active']);
    BusinessMembership::create(['user_id' => $this->subjectId, 'business_id' => 'B', 'status' => 'active']);
    Fixtures::assignment($this->subject, 'fixture_rewards_business', 'business', 'A');
    Fixtures::assignment($this->subject, 'fixture_rewards_global');
    expect($this->engine->allows($this->subjectId, 'analytics.view', 'business', 'A'))->toBeTrue()
        ->and($this->engine->allows($this->subjectId, 'analytics.view', 'business', 'B'))->toBeFalse();
    $membership->transitionTo('suspended');
    expect($this->engine->allows($this->subjectId, 'analytics.view', 'business', 'A'))->toBeFalse();
    $membership->transitionTo('revoked');
    expect($this->engine->allows($this->subjectId, 'analytics.view', 'business', 'A'))->toBeFalse();
});

test('rewards respects assignment lifecycle rather than cached or role name authority', function (string $state, bool $allowed) {
    $this->freezeTime();
    $changes = match ($state) {
        'revoked' => ['status' => 'revoked', 'is_current' => false], 'suspended' => ['status' => 'suspended'],
        'pending' => ['status' => 'pending'], 'expired' => ['ends_at' => new UTCDateTime(now())],
        'future' => ['starts_at' => new UTCDateTime(now()->addSecond())], 'non-current' => ['is_current' => false],
        'starts now' => ['starts_at' => new UTCDateTime(now())], 'ends later' => ['ends_at' => new UTCDateTime(now()->addSecond())], default => [],
    };
    Fixtures::assignment($this->subject, 'fixture_rewards_global', changes: $changes);
    expect($this->engine->allows($this->subjectId, 'audit.view'))->toBe($allowed)
        ->and($this->engine->allows($this->subjectId, 'audit.write'))->toBeFalse();
})->with([['active', true], ['revoked', false], ['suspended', false], ['pending', false], ['expired', false], ['future', false], ['non-current', false], ['starts now', true], ['ends later', true]]);

test('rewards checks permission domain mapping and eligible subject on every decision', function (string $case) {
    Fixtures::assignment($this->subject, 'fixture_rewards_global');
    match ($case) {
        'missing mapping' => DB::connection('mongodb')->getCollection('role_permissions')->deleteOne(['role_key' => 'fixture_rewards_global', 'permission_key' => 'audit.view']),
        'foreign mapping' => DB::connection('mongodb')->getCollection('role_permissions')->updateOne(['role_key' => 'fixture_rewards_global', 'permission_key' => 'audit.view'], ['$set' => ['permission_key' => 'business.manage']]),
        'wrong domain' => DB::connection('mongodb')->getCollection('permissions')->updateOne(['key' => 'audit.view'], ['$set' => ['domain' => 'business']]),
        'inactive permission' => Permission::where('key', 'audit.view')->first()->update(['active' => false]),
        'deleted subject' => $this->subject->delete(), 'pending subject' => $this->subject->update(['account_activation_pending' => true]),
    };
    expect($this->engine->allows($this->subjectId, 'audit.view'))->toBeFalse();
})->with(['missing mapping', 'foreign mapping', 'wrong domain', 'inactive permission', 'deleted subject', 'pending subject']);

test('global and business role names have no implicit rewards authority even with forged storage mappings', function () {
    $this->subject->assignRole('admin');
    RoleAssignment::create(['user_id' => $this->subjectId, 'role_key' => 'rewards_admin', 'status' => 'active']);
    RoleAssignment::create(['user_id' => $this->subjectId, 'role_key' => 'auditor', 'status' => 'active']);
    foreach (['admin', 'rewards_admin', 'auditor'] as $role) {
        DB::connection('mongodb')->getCollection('role_permissions')->insertOne(['role_key' => $role, 'permission_key' => 'audit.view']);
    }
    expect($this->engine->allows($this->subjectId, 'audit.view'))->toBeFalse()
        ->and(app(RewardsAuthorization::class)->allows($this->subjectId, 'audit.view'))->toBeFalse()
        ->and($this->engine->allows('svc_client_credentials_fixture', 'audit.view'))->toBeFalse();
});
