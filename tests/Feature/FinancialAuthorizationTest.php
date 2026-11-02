<?php

use App\Contracts\BonusAuthorization;
use App\Models\AcademicProgram;
use App\Models\BusinessMembership;
use App\Models\Campus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\FinancialAuthorizationService;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;
use Tests\Support\SyntheticDomainFixtures as Fixtures;

beforeEach(function () {
    Fixtures::seed($this);
    $this->subject = User::factory()->create();
    $this->subjectId = (string) $this->subject->getKey();
    $this->engine = Fixtures::financial();
});

test('financial infrastructure evaluates all five types but synthetic roles never enter the production catalog', function (string $type) {
    Fixtures::assignment($this->subject, 'fixture_financial_global');
    expect($this->engine->allows($this->subjectId, $type, 'issue'))->toBeTrue()
        ->and(app(BonusAuthorization::class))->toBeInstanceOf(FinancialAuthorizationService::class)
        ->and(app(BonusAuthorization::class)->allows($this->subjectId, $type, 'issue'))->toBeFalse()
        ->and(array_keys(Role::FINANCIAL_ROLE_SCOPES))->toBe(Role::FINANCIAL_ROLES);
})->with(['BECA', 'CAMPANIA', 'BENEFICIO', 'CATEGORIA', 'NEGOCIO']);

test('financial validation rejects unknown operations types and invalid scope tuples', function (string $type, string $operation, ?string $scope, ?string $id) {
    Fixtures::assignment($this->subject, 'fixture_financial_global');
    expect($this->engine->allows($this->subjectId, $type, $operation, $scope, $id))->toBeFalse();
})->with([
    ['UNKNOWN', 'issue', null, null], ['beca', 'issue', null, null], ['BECA', 'transfer', null, null],
    ['BECA', 'issue', 'business', 'A'], ['CAMPANIA', 'issue', 'campaign', 'A'],
    ['CAMPANIA', 'issue', 'business', 'A'], ['BENEFICIO', 'issue', 'academic_program', 'A'],
    ['CATEGORIA', 'issue', 'business', 'A'], ['NEGOCIO', 'issue', 'academic_program', 'A'],
    ['NEGOCIO', 'issue', null, 'A'], ['NEGOCIO', 'issue', 'business', null],
    ['NEGOCIO', 'issue', 'global', 'A'], ['NEGOCIO', 'issue', 'business', ''],
    ['NEGOCIO', 'issue', 'business', "A\nB"],
]);

test('financial decisions reject invalid subjects and never authenticate an OAuth client as a human', function (?string $id) {
    expect($this->engine->allows($id, 'BECA', 'issue'))->toBeFalse();
})->with([null, '', 'missing-user', 'svc_client_credentials_fixture', 'Bearer token-fixture']);

test('financial business rights require an exact valid membership and never inherit global authority', function () {
    $membership = BusinessMembership::create(['user_id' => $this->subjectId, 'business_id' => 'A', 'status' => 'active']);
    BusinessMembership::create(['user_id' => $this->subjectId, 'business_id' => 'B', 'status' => 'active']);
    Fixtures::assignment($this->subject, 'fixture_financial_business', 'business', 'A');
    expect($this->engine->allows($this->subjectId, 'NEGOCIO', 'issue', 'business', 'A'))->toBeTrue()
        ->and($this->engine->allows($this->subjectId, 'NEGOCIO', 'issue', 'business', 'B'))->toBeFalse()
        ->and($this->engine->allows($this->subjectId, 'NEGOCIO', 'issue'))->toBeFalse();
    $membership->transitionTo('suspended');
    expect($this->engine->allows($this->subjectId, 'NEGOCIO', 'issue', 'business', 'A'))->toBeFalse();
    $membership->transitionTo('revoked');
    expect($this->engine->allows($this->subjectId, 'NEGOCIO', 'issue', 'business', 'A'))->toBeFalse();
});

test('financial program rights revalidate canonical catalogs and original campus binding', function (string $case) {
    $campus = Campus::create(['code' => 'A', 'name' => 'Campus A', 'is_active' => true]);
    $program = AcademicProgram::create(['code' => 'P', 'name' => 'Program P', 'campus_id' => (string) $campus->getKey(), 'is_active' => true]);
    $other = AcademicProgram::create(['code' => 'Q', 'name' => 'Program Q', 'campus_id' => (string) $campus->getKey(), 'is_active' => true]);
    Fixtures::assignment($this->subject, 'fixture_financial_program', 'academic_program', (string) $program->getKey(), ['campus_id' => (string) $campus->getKey()]);
    expect($this->engine->allows($this->subjectId, 'BECA', 'issue', 'academic_program', (string) $program->getKey()))->toBeTrue()
        ->and($this->engine->allows($this->subjectId, 'BECA', 'issue', 'academic_program', strtoupper((string) $program->getKey())))->toBeTrue()
        ->and($this->engine->allows($this->subjectId, 'BECA', 'issue', 'academic_program', 'P'))->toBeFalse()
        ->and($this->engine->allows($this->subjectId, 'BECA', 'issue', 'academic_program', (string) $other->getKey()))->toBeFalse();
    match ($case) {
        'inactive program' => $program->update(['is_active' => false]),
        'inactive campus' => $campus->update(['is_active' => false]),
        'missing program' => $program->delete(),
        'missing campus' => $campus->delete(),
        'moved program' => $program->update(['campus_id' => (string) Campus::create(['code' => 'B', 'name' => 'Campus B', 'is_active' => true])->getKey()]),
    };
    expect($this->engine->allows($this->subjectId, 'BECA', 'issue', 'academic_program', (string) $program->getKey()))->toBeFalse();
})->with(['inactive program', 'inactive campus', 'missing program', 'missing campus', 'moved program']);

test('financial grant lifecycle respects current active and exact validity boundaries', function (string $state, bool $allowed) {
    $this->freezeTime();
    $changes = match ($state) {
        'revoked' => ['status' => 'revoked', 'is_current' => false],
        'suspended' => ['status' => 'suspended'], 'pending' => ['status' => 'pending'],
        'expired' => ['ends_at' => new UTCDateTime(now())], 'future' => ['starts_at' => new UTCDateTime(now()->addSecond())],
        'non-current' => ['is_current' => false], 'starts now' => ['starts_at' => new UTCDateTime(now())],
        'ends later' => ['ends_at' => new UTCDateTime(now()->addSecond())], default => [],
    };
    Fixtures::assignment($this->subject, 'fixture_financial_global', changes: $changes);
    expect($this->engine->allows($this->subjectId, 'BENEFICIO', 'issue'))->toBe($allowed);
})->with([['active', true], ['revoked', false], ['suspended', false], ['pending', false], ['expired', false], ['future', false], ['non-current', false], ['starts now', true], ['ends later', true]]);

test('financial authority requires both the closed policy and persistent permission mapping without query writes', function (string $case) {
    Fixtures::assignment($this->subject, 'fixture_financial_global');
    match ($case) {
        'missing mapping' => DB::connection('mongodb')->getCollection('role_permissions')->deleteOne(['role_key' => 'fixture_financial_global', 'permission_key' => 'bonuses.beca.issue']),
        'missing permission' => Permission::where('key', 'bonuses.beca.issue')->delete(),
        'inactive permission' => Permission::where('key', 'bonuses.beca.issue')->first()->update(['active' => false]),
        'wrong domain' => DB::connection('mongodb')->getCollection('permissions')->updateOne(['key' => 'bonuses.beca.issue'], ['$set' => ['domain' => 'business']]),
        'missing role' => Role::where('name', 'fixture_financial_global')->delete(),
        'deleted user' => $this->subject->delete(),
        'pending activation' => $this->subject->update(['account_activation_pending' => true]),
        'initial password' => $this->subject->forceFill(['must_change_password' => true])->save(),
    };
    $db = DB::connection('mongodb')->getDatabase();
    $before = [];
    foreach ($db->listCollectionNames() as $name) {
        $before[$name] = hash('sha256', serialize(iterator_to_array($db->selectCollection($name)->find([], ['sort' => ['_id' => 1]]))));
    }
    expect($this->engine->allows($this->subjectId, 'BECA', 'issue'))->toBeFalse();
    foreach ($before as $name => $hash) {
        expect(hash('sha256', serialize(iterator_to_array($db->selectCollection($name)->find([], ['sort' => ['_id' => 1]])))))->toBe($hash);
    }
})->with(['missing mapping', 'missing permission', 'inactive permission', 'wrong domain', 'missing role', 'deleted user', 'pending activation', 'initial password']);

test('legacy admin owner and memberships alone never grant financial authority', function () {
    $this->subject->assignRole('admin');
    BusinessMembership::create(['user_id' => $this->subjectId, 'business_id' => 'A', 'status' => 'active']);
    RoleAssignment::create(['user_id' => $this->subjectId, 'role_key' => 'business_owner', 'scope_type' => 'business', 'scope_id' => 'A', 'status' => 'active']);
    foreach (FinancialAuthorizationService::BONUS_CAPABILITIES as $type => $capability) {
        DB::connection('mongodb')->getCollection('role_permissions')->insertOne(['role_key' => 'admin', 'permission_key' => $capability]);
        expect($this->engine->allows($this->subjectId, $type, 'issue'))->toBeFalse();
    }
    expect($this->engine->allows($this->subjectId, 'NEGOCIO', 'issue', 'business', 'A'))->toBeFalse();
});
