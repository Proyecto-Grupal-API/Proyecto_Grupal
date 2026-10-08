<?php

use App\Models\BusinessMembership;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\BusinessAuthorizationService;
use App\Services\BusinessRoleAdministrationService;
use Database\Seeders\AuthorizationFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    (require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php'))->up();
    $this->seed(AuthorizationFoundationSeeder::class);
    $this->actor = User::factory()->create();
    $this->target = User::factory()->create();
    foreach ([$this->actor, $this->target] as $user) {
        BusinessMembership::create(['user_id' => (string) $user->getKey(), 'business_id' => 'A', 'status' => 'active']);
    }
    $this->administration = app(BusinessRoleAdministrationService::class);
    $this->authorization = app(BusinessAuthorizationService::class);
});

function b4ActorRole(User $actor, string $role): RoleAssignment
{
    return RoleAssignment::create(['user_id' => (string) $actor->getKey(), 'role_key' => $role,
        'scope_type' => 'business', 'scope_id' => 'A', 'status' => 'active']);
}

test('owner and manager administration follow the explicit subordinate matrix', function (string $actorRole, string $targetRole, bool $allow, string $operation) {
    b4ActorRole($this->actor, $actorRole);
    $record = $operation === 'revoke' ? b4ActorRole($this->target, $targetRole) : null;
    if (! $allow) {
        $before = RoleAssignment::count();
        expect(fn () => $this->administration->$operation($this->actor, $this->target, 'A', $targetRole))->toThrow(AuthorizationException::class);
        expect(RoleAssignment::count())->toBe($before);
        if ($record) {
            expect($record->fresh()->status)->toBe('active');
        }
    } elseif ($operation === 'assign') {
        $result = $this->administration->assign($this->actor, $this->target, 'A', $targetRole);
        expect($result->role_key)->toBe($targetRole)->and($result->scope_id)->toBe('A')
            ->and($result->assigned_by)->toBe((string) $this->actor->getKey());
    } else {
        expect($this->administration->revoke($this->actor, $this->target, 'A', $targetRole))->toBeTrue()
            ->and($record->fresh()->status)->toBe('revoked');
    }
})->with([
    ['business_owner', 'business_manager', true], ['business_owner', 'cashier', true],
    ['business_owner', 'inventory_manager', true], ['business_owner', 'buyer', true], ['business_owner', 'business_owner', false],
    ['business_manager', 'inventory_manager', true], ['business_manager', 'buyer', true],
    ['business_manager', 'cashier', false], ['business_manager', 'business_manager', false], ['business_manager', 'business_owner', false],
])->with(['assign', 'revoke']);

test('neither owner nor manager may administer across businesses', function (string $role, string $operation) {
    b4ActorRole($this->actor, $role);
    // Membership in B must not allow the actor to reuse an administrative role from A.
    BusinessMembership::create(['user_id' => (string) $this->actor->getKey(), 'business_id' => 'B', 'status' => 'active']);
    BusinessMembership::create(['user_id' => (string) $this->target->getKey(), 'business_id' => 'B', 'status' => 'active']);
    $target = RoleAssignment::create(['user_id' => (string) $this->target->getKey(), 'role_key' => 'buyer',
        'scope_type' => 'business', 'scope_id' => 'B', 'status' => 'active']);
    expect(fn () => $this->administration->$operation($this->actor, $this->target, 'B', 'buyer'))->toThrow(AuthorizationException::class);
    expect($target->fresh()->status)->toBe('active')->and(RoleAssignment::count())->toBe(2);
})->with(['business_owner', 'business_manager'])->with(['assign', 'revoke']);

test('invalid actor target membership role and lifecycle cannot produce assignments', function (string $case) {
    $grant = b4ActorRole($this->actor, 'business_owner');
    $actor = $this->actor;
    $target = $this->target;
    $id = 'A';
    $role = 'cashier';
    switch ($case) {
        case 'missing actor': $actor = null;
            break;
        case 'deleted actor': $actor->fresh()->delete();
            break;
        case 'missing target': $target = null;
            break;
        case 'deleted target': $target->fresh()->delete();
            break;
        case 'actor suspended membership': BusinessMembership::where('user_id', (string) $actor->getKey())->first()->transitionTo('suspended');
            break;
        case 'actor suspended role': $grant->transitionTo('suspended');
            break;
        case 'actor revoked role': $grant->transitionTo('revoked');
            break;
        case 'target revoked membership': BusinessMembership::where('user_id', (string) $target->getKey())->first()->transitionTo('revoked');
            break;
        case 'target suspended membership': BusinessMembership::where('user_id', (string) $target->getKey())->first()->transitionTo('suspended');
            break;
        case 'unknown role': $role = 'business_admin';
            break;
        case 'legacy role': $role = 'admin';
            break;
        case 'missing business': $id = null;
            break;
        case 'legacy only actor': $grant->transitionTo('revoked');
            $actor->roles = [['name' => 'business_owner', 'scope_type' => 'business', 'scope_id' => 'A']];
            $actor->save();
            break;
        case 'global admin': $grant->transitionTo('revoked');
            $actor->assignRole('admin');
            break;
    }
    $before = RoleAssignment::count();
    expect(fn () => $this->administration->assign($actor, $target, $id, $role))->toThrow(AuthorizationException::class);
    expect(RoleAssignment::count())->toBe($before);
})->with(['missing actor', 'deleted actor', 'missing target', 'deleted target', 'actor suspended membership',
    'actor suspended role', 'actor revoked role', 'target revoked membership', 'target suspended membership',
    'unknown role', 'legacy role', 'missing business', 'legacy only actor', 'global admin']);

test('assign revoke regrant preserves documents history generation and uniqueness', function () {
    b4ActorRole($this->actor, 'business_owner');
    $db = DB::connection('mongodb')->getDatabase();
    $before = serialize($db->selectCollection('users')->find()->toArray());
    $first = $this->administration->assign($this->actor, $this->target, 'A', 'cashier');
    $again = $this->administration->assign($this->actor, $this->target, 'A', 'cashier');
    expect((string) $again->getKey())->toBe((string) $first->getKey())
        ->and($first->generation)->toBe(1)->and($first->revision)->toBe(1)
        ->and($this->authorization->allows($this->target, 'business.sales.operate', 'business', 'A'))->toBeTrue();
    expect($this->administration->revoke($this->actor, $this->target, 'A', 'cashier'))->toBeTrue()
        ->and($this->administration->revoke($this->actor, $this->target, 'A', 'cashier'))->toBeFalse()
        ->and($this->authorization->allows($this->target, 'business.sales.operate', 'business', 'A'))->toBeFalse();
    $first->refresh();
    expect($first->status)->toBe('revoked')->and($first->is_current)->toBeFalse()
        ->and($first->revision)->toBe(2)->and($first->transitions)->toHaveCount(1)
        ->and($first->revoked_by)->toBe((string) $this->actor->getKey());
    $next = $this->administration->assign($this->actor, $this->target, 'A', 'cashier');
    expect($next->generation)->toBe(2)->and($next->revision)->toBe(1)
        ->and(RoleAssignment::where('user_id', (string) $this->target->getKey())->where('is_current', true)->count())->toBe(1)
        ->and($this->authorization->allows($this->target, 'business.sales.operate', 'business', 'A'))->toBeTrue()
        ->and(serialize($db->selectCollection('users')->find()->toArray()) === $before)->toBeTrue()
        ->and(BusinessMembership::count())->toBe(2);
    $duplicateRejected = false;
    try {
        b4ActorRole($this->target, 'cashier');
    } catch (Throwable $exception) {
        expect($exception->getMessage())->toContain('E11000')->toContain('current_identity_unique');
        $duplicateRejected = true;
    }
    expect($duplicateRejected)->toBeTrue();
    expect(RoleAssignment::where('user_id', (string) $this->target->getKey())->count())->toBe(2);
});

test('idempotent assign never reactivates suspended roles and revocation can clean up a suspended membership', function () {
    b4ActorRole($this->actor, 'business_owner');
    $record = $this->administration->assign($this->actor, $this->target, 'A', 'cashier');
    $record->transitionTo('suspended');
    $same = $this->administration->assign($this->actor, $this->target, 'A', 'cashier');
    expect($same->status)->toBe('suspended')->and((string) $same->getKey())->toBe((string) $record->getKey());
    BusinessMembership::where('user_id', (string) $this->target->getKey())->first()->transitionTo('suspended');
    expect($this->administration->revoke($this->actor, $this->target, 'A', 'cashier'))->toBeTrue()
        ->and($record->fresh()->status)->toBe('revoked');
});
