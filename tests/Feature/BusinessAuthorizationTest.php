<?php

use App\Models\BusinessMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\BusinessAuthorizationService;
use Database\Seeders\AuthorizationFoundationSeeder;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

beforeEach(function () {
    (require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php'))->up();
    $this->seed(AuthorizationFoundationSeeder::class);
    $this->engine = app(BusinessAuthorizationService::class);
    $this->subject = User::factory()->create();
});

function b4Membership(User $user, string $business = 'A', array $changes = []): BusinessMembership
{
    return BusinessMembership::create([...[
        'user_id' => (string) $user->getKey(), 'business_id' => $business, 'status' => 'active',
    ], ...$changes]);
}

function b4Role(User $user, string $role = 'cashier', string $business = 'A', array $changes = []): RoleAssignment
{
    return RoleAssignment::create([...[
        'user_id' => (string) $user->getKey(), 'role_key' => $role,
        'scope_type' => 'business', 'scope_id' => $business, 'status' => 'active',
    ], ...$changes]);
}

test('business capabilities follow the exact persistent canonical matrix', function (string $role, array $grants) {
    b4Membership($this->subject);
    b4Role($this->subject, $role);
    foreach (Permission::CATALOG as $key => [$label, $domain]) {
        expect($this->engine->allows($this->subject, $key, 'business', 'A'))->toBe($domain === 'business' && in_array($key, $grants, true));
    }
    expect($this->engine->businessRoles())->toBe(['business_owner', 'business_manager', 'cashier', 'inventory_manager', 'buyer']);
})->with([
    'owner' => ['business_owner', ['business.manage', 'business.members.manage', 'business.operate', 'business.sales.operate', 'business.inventory.operate']],
    'manager' => ['business_manager', ['business.operate', 'business.sales.operate', 'business.inventory.operate']],
    'cashier' => ['cashier', ['business.sales.operate']],
    'inventory' => ['inventory_manager', ['business.inventory.operate']],
    'buyer' => ['buyer', []],
]);

test('only active current and presently valid membership and assignment authorize', function (string $model, string $state, bool $allow) {
    $this->freezeTime();
    $membership = b4Membership($this->subject);
    $assignment = b4Role($this->subject);
    $record = $model === 'membership' ? $membership : $assignment;
    if (in_array($state, ['pending', 'unknown', 'non-current', 'future', 'expired'], true)) {
        $changes = match ($state) {
            'pending', 'unknown' => ['status' => $state],
            'non-current' => ['is_current' => false],
            'future' => ['starts_at' => new UTCDateTime(now()->addSecond())],
            'expired' => ['ends_at' => new UTCDateTime(now())],
        };
        // Raw invalid/non-current storage fixtures prove fail-closed readers, never bypass production writes.
        DB::connection('mongodb')->getCollection($model === 'membership' ? 'business_memberships' : 'role_assignments')
            ->updateOne(['_id' => new ObjectId((string) $record->getKey())], ['$set' => $changes]);
    } elseif (in_array($state, ['suspended', 'revoked'], true)) {
        $record->transitionTo($state);
    }
    expect($this->engine->allows($this->subject, 'business.sales.operate', 'business', 'A'))->toBe($allow);
})->with(['membership', 'assignment'])->with([
    'active' => ['active', true], 'pending' => ['pending', false], 'unknown' => ['unknown', false],
    'suspended' => ['suspended', false], 'revoked' => ['revoked', false],
    'non-current' => ['non-current', false], 'future' => ['future', false], 'expired' => ['expired', false],
]);

test('effective lifecycle includes the exact start boundary and excludes the exact end', function () {
    $this->freezeTime();
    b4Membership($this->subject, 'A', ['starts_at' => now(), 'ends_at' => now()->addSecond()]);
    b4Role($this->subject, 'cashier', 'A', ['starts_at' => now(), 'ends_at' => now()->addSecond()]);
    expect($this->engine->allows($this->subject, 'business.sales.operate', 'business', 'A'))->toBeTrue();
    $this->travel(1)->seconds();
    expect($this->engine->allows($this->subject, 'business.sales.operate', 'business', 'A'))->toBeFalse();
});

test('identity membership and catalog failures always deny without creating query data', function (string $case) {
    $membership = b4Membership($this->subject);
    $assignment = b4Role($this->subject);
    $subject = $this->subject;
    $capability = 'business.sales.operate';
    $scope = 'business';
    $id = 'A';
    switch ($case) {
        case 'null user': $subject = null;
            break;
        case 'unsaved user': $subject = new User;
            break;
        case 'deleted stale user': $subject->fresh()->delete();
            break;
        case 'missing persisted user': DB::connection('mongodb')->getCollection('users')->deleteOne(['_id' => new ObjectId((string) $subject->getKey())]);
            break;
        case 'pending activation': $subject->fresh()->forceFill(['account_activation_pending' => true])->save();
            break;
        case 'initial password required': $subject->fresh()->forceFill(['must_change_password' => true])->save();
            break;
        case 'unknown capability': $capability = 'bonus.manage';
            break;
        case 'institutional capability': $capability = 'academic.program.coordinate';
            break;
        case 'unknown scope': $scope = 'association';
            break;
        case 'null scope': $scope = null;
            break;
        case 'null id': $id = null;
            break;
        case 'empty id': $id = '';
            break;
        case 'unsafe id': $id = "A\nB";
            break;
        case 'long id': $id = str_repeat('A', 101);
            break;
        case 'missing membership': DB::connection('mongodb')->getCollection('business_memberships')->deleteOne(['_id' => new ObjectId((string) $membership->getKey())]);
            break;
        case 'missing assignment': DB::connection('mongodb')->getCollection('role_assignments')->deleteOne(['_id' => new ObjectId((string) $assignment->getKey())]);
            break;
        case 'missing role': Role::where('name', 'cashier')->delete();
            break;
        case 'missing permission': Permission::where('key', $capability)->delete();
            break;
        case 'inactive permission': Permission::where('key', $capability)->first()->fill(['active' => false])->save();
            break;
        case 'missing mapping': RolePermission::where('role_key', 'cashier')->delete();
            break;
    }
    $before = [Role::count(), Permission::count(), RolePermission::count(), RoleAssignment::count(), BusinessMembership::count()];
    expect($this->engine->allows($subject, $capability, $scope, $id))->toBeFalse()
        ->and([Role::count(), Permission::count(), RolePermission::count(), RoleAssignment::count(), BusinessMembership::count()])->toBe($before);
})->with(['null user', 'unsaved user', 'deleted stale user', 'missing persisted user', 'pending activation', 'initial password required', 'unknown capability',
    'institutional capability', 'unknown scope', 'null scope', 'null id', 'empty id', 'unsafe id', 'long id',
    'missing membership', 'missing assignment', 'missing role', 'missing permission', 'inactive permission', 'missing mapping']);

test('active membership alone and unmapped unknown or legacy roles never grant capabilities', function () {
    b4Membership($this->subject);
    expect($this->engine->allows($this->subject, 'business.manage', 'business', 'A'))->toBeFalse();
    $this->subject->assignRole('admin');
    b4Role($this->subject, 'buyer');
    expect($this->subject->hasRole('admin'))->toBeTrue()
        ->and($this->engine->allows($this->subject, 'business.manage', 'business', 'A'))->toBeFalse();
    DB::connection('mongodb')->getCollection('role_permissions')->insertOne(['role_key' => 'buyer', 'permission_key' => 'business.manage']);
    expect($this->engine->allows($this->subject, 'business.manage', 'business', 'A'))->toBeFalse();
    DB::connection('mongodb')->getCollection('role_assignments')->updateMany(['role_key' => 'buyer'], ['$set' => ['role_key' => 'business_admin']]);
    expect($this->engine->allows($this->subject, 'business.manage', 'business', 'A'))->toBeFalse();
});

test('union stays within one business and suspension overrides all grants immediately', function () {
    $membership = b4Membership($this->subject);
    b4Membership($this->subject, 'B');
    b4Role($this->subject, 'cashier');
    b4Role($this->subject, 'inventory_manager', 'B');
    expect($this->engine->allows($this->subject, 'business.sales.operate', 'business', 'A'))->toBeTrue()
        ->and($this->engine->allows($this->subject, 'business.inventory.operate', 'business', 'A'))->toBeFalse()
        ->and($this->engine->allows($this->subject, 'business.sales.operate', 'business', 'B'))->toBeFalse()
        ->and($this->engine->allows($this->subject, 'business.inventory.operate', 'business', 'B'))->toBeTrue();
    b4Role($this->subject, 'inventory_manager');
    expect($this->engine->allows($this->subject, 'business.inventory.operate', 'business', 'A'))->toBeTrue()
        ->and($this->engine->allows($this->subject, 'business.members.manage', 'business', 'A'))->toBeFalse();
    $membership->transitionTo('suspended');
    expect($this->engine->allows($this->subject, 'business.sales.operate', 'business', 'A'))->toBeFalse()
        ->and($this->engine->allows($this->subject, 'business.inventory.operate', 'business', 'A'))->toBeFalse();
    $membership->transitionTo('active');
    expect($this->engine->allows($this->subject, 'business.sales.operate', 'business', 'A'))->toBeTrue();
});

test('revocation removes only that roles grants while another mapped role can preserve a grant', function () {
    b4Membership($this->subject);
    $cashier = b4Role($this->subject);
    $manager = b4Role($this->subject, 'business_manager');
    $cashier->transitionTo('revoked');
    expect($this->engine->allows($this->subject, 'business.sales.operate', 'business', 'A'))->toBeTrue();
    $manager->transitionTo('revoked');
    expect($this->engine->allows($this->subject, 'business.sales.operate', 'business', 'A'))->toBeFalse()
        ->and(BusinessMembership::first()->status)->toBe('active');
});

test('legacy data never acts as business authority and legacy APIs remain isolated', function () {
    $this->subject->roles = [['name' => 'business_owner', 'scope_type' => 'business', 'scope_id' => 'A'], ['name' => 'admin']];
    $this->subject->save();
    b4Membership($this->subject);
    expect($this->engine->allows($this->subject, 'business.manage', 'business', 'A'))->toBeFalse()
        ->and($this->subject->hasRole('admin'))->toBeFalse();
    $this->subject->assignRole('estudiante');
    $this->subject->assignRole('admin');
    b4Role($this->subject, 'business_owner');
    expect($this->subject->hasRole('admin'))->toBeTrue()->and($this->subject->hasRole('estudiante'))->toBeTrue()
        ->and($this->subject->hasRole('business_owner', 'business', 'A'))->toBeFalse()
        ->and($this->subject->effectiveRoles())->toHaveCount(2)
        ->and($this->engine->allows($this->subject, 'business.manage', 'business', 'A'))->toBeTrue();
});
