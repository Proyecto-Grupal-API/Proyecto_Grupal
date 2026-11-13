<?php

use App\Contracts\BonusAuthorization;
use App\Contracts\RewardsAuthorization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Database\Seeders\AuthorizationFoundationSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\SyntheticDomainFixtures as Fixtures;

beforeEach(function () {
    (require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php'))->up();
    $this->seed(AuthorizationFoundationSeeder::class);
});

test('canonical seeding remains sixteen eight thirteen without assignments or passive technical permission records', function () {
    $this->seed(AuthorizationFoundationSeeder::class);
    expect(Role::count())->toBe(16)->and(Permission::count())->toBe(8)
        ->and(RolePermission::count())->toBe(13)->and(RoleAssignment::count())->toBe(0)
        ->and(Permission::whereIn('domain', ['financial', 'rewards'])->count())->toBe(0);
});

test('technical definitions can be persisted in testing without granting any role or creating assignments', function () {
    $user = User::factory()->create();
    foreach (Permission::TECHNICAL_CATALOG as $key => [$label, $domain]) {
        Permission::create(['key' => $key, 'display_name' => $label, 'domain' => $domain, 'active' => false]);
    }
    // 8 foundation permissions + 22 inactive technical definitions (stale since the 15-entry catalog of 2026-11-09/10).
    expect(Permission::count())->toBe(count(Permission::CATALOG) + 22)->and(RolePermission::count())->toBe(13)
        ->and(RoleAssignment::count())->toBe(0)
        ->and(app(BonusAuthorization::class)->allows((string) $user->getKey(), 'BECA', 'issue'))->toBeFalse()
        ->and(app(RewardsAuthorization::class)->allows((string) $user->getKey(), 'audit.view'))->toBeFalse();
});

test('technical capabilities reject a mismatched domain and unknown permission names', function () {
    expect(fn () => Permission::create(['key' => 'audit.view', 'display_name' => 'Fixture', 'domain' => 'business']))->toThrow(ValidationException::class)
        ->and(fn () => Permission::create(['key' => 'universal.allow', 'display_name' => 'Fixture', 'domain' => 'financial']))->toThrow(ValidationException::class)
        ->and(Permission::count())->toBe(8);
});

test('an absent role remains a validation error rather than a policy helper type error', function () {
    expect(fn () => RolePermission::create(['permission_key' => 'business.manage']))->toThrow(ValidationException::class)
        ->and(RolePermission::count())->toBe(13);
});

test('known roles cannot receive unapproved financial or rewards mappings through the model writer', function (string $role, string $key) {
    [$label, $domain] = Permission::TECHNICAL_CATALOG[$key];
    Permission::create(['key' => $key, 'display_name' => $label, 'domain' => $domain, 'active' => true]);
    expect(fn () => RolePermission::create(['role_key' => $role, 'permission_key' => $key]))->toThrow(ValidationException::class)
        ->and(RolePermission::count())->toBe(13)->and(RoleAssignment::count())->toBe(0);
})->with([
    ['admin', 'bonuses.beca.issue'], ['business_owner', 'bonuses.negocio.issue'],
    ['business_manager', 'rewards.catalog.manage'], ['cashier', 'rewards.redemption.deliver'],
    ['rewards_admin', 'rewards.program.manage'], ['auditor', 'audit.view'],
]);

test('synthetic global policies reject misplaced and incomplete stored scope tuples', function (string $domain, string $case) {
    // This test class normally seeds only the approved catalog; add synthetic definitions explicitly.
    foreach (Permission::TECHNICAL_CATALOG as $key => [$label, $owner]) {
        Permission::create(['key' => $key, 'display_name' => $label, 'domain' => $owner, 'active' => true]);
    }
    $subject = User::factory()->create();
    $role = $domain === 'financial' ? 'fixture_financial_global' : 'fixture_rewards_global';
    $key = $domain === 'financial' ? 'bonuses.beca.issue' : 'audit.view';
    Role::create(['name' => $role, 'display_name' => 'Synthetic global fixture']);
    DB::connection('mongodb')->getCollection('role_permissions')->insertOne(['role_key' => $role, 'permission_key' => $key]);
    Fixtures::assignment($subject, $role, $case === 'misplaced' ? 'business' : null, $case === 'misplaced' ? 'A' : null);
    if ($case === 'missing') {
        DB::connection('mongodb')->getCollection('role_assignments')->updateOne(['role_key' => $role], ['$unset' => ['scope_type' => '', 'scope_id' => '']]);
    }
    $allowed = $domain === 'financial'
        ? Fixtures::financial()->allows((string) $subject->getKey(), 'BECA', 'issue')
        : Fixtures::rewards()->allows((string) $subject->getKey(), 'audit.view');
    expect($allowed)->toBeFalse();
})->with(['financial', 'rewards'])->with(['misplaced', 'missing']);
