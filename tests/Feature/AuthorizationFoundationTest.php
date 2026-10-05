<?php

use App\Models\AcademicProgram;
use App\Models\BusinessMembership;
use App\Models\Campus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Database\Seeders\AuthorizationFoundationSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;

beforeEach(function () {
    $this->foundationMigration = require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php');
    $this->foundationMigration->up();
    $this->seed(AuthorizationFoundationSeeder::class);
    $this->subject = User::factory()->create();
});

function foundationAssignment(User $user, array $changes = []): RoleAssignment
{
    return RoleAssignment::create([...[
        'user_id' => $user->getKey(), 'role_key' => 'cashier', 'scope_type' => 'business',
        'scope_id' => 'external-business-1', 'status' => 'active',
    ], ...$changes]);
}

function foundationMembership(User $user, array $changes = []): BusinessMembership
{
    return BusinessMembership::create([...[
        'user_id' => $user->getKey(), 'business_id' => 'external-business-1', 'status' => 'active',
    ], ...$changes]);
}

function foundationAssertDuplicate(Closure $operation): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        expect($exception->getMessage())->toContain('E11000');

        return;
    }
    test()->fail('Expected MongoDB unique index to reject duplicate persistence.');
}

test('permission keys and role permission pairs are unique in storage', function () {
    foundationAssertDuplicate(fn () => Permission::create(Permission::where('key', 'business.manage')->first()->only(['key', 'display_name', 'description', 'domain', 'active'])));
    foundationAssertDuplicate(fn () => RolePermission::create(['role_key' => 'cashier', 'permission_key' => 'business.sales.operate']));
    expect(Permission::count())->toBe(8)->and(RolePermission::count())->toBe(13);
});

test('permission catalog rejects consumer owned capabilities and mismatched domains', function (array $data) {
    expect(fn () => Permission::create([...['display_name' => 'Fixture', 'domain' => 'business'], ...$data]))
        ->toThrow(ValidationException::class);
})->with([
    [['key' => 'rewards.program.manage']],
    [['key' => 'audit.view']],
    [['key' => 'analytics.view']],
    [['key' => 'business.manage', 'domain' => 'institutional']],
]);

test('mappings reject unknown references and unapproved grants', function () {
    expect(fn () => RolePermission::create(['role_key' => 'buyer', 'permission_key' => 'business.manage']))->toThrow(ValidationException::class);
    Permission::where('key', 'business.sales.operate')->delete();
    expect(fn () => RolePermission::create(['role_key' => 'cashier', 'permission_key' => 'business.sales.operate']))->toThrow(ValidationException::class);
});

test('catalog preserves six legacy roles and adds exactly ten isolated foundation roles', function () {
    expect(Role::VALID_ROLES)->toBe(['admin', 'maestro', 'estudiante', 'servicio_cafeteria', 'consejo_estudiantil', 'student_manager'])
        ->and(Role::NEW_ROLES)->toHaveCount(10)
        ->and(Role::count())->toBe(16)
        ->and(Role::where('name', 'inventory_manager')->count())->toBe(1)
        ->and(Role::where('name', 'business_admin')->exists())->toBeFalse()
        ->and(RolePermission::whereIn('role_key', [...Role::VALID_ROLES, 'buyer', 'auditor', 'rewards_admin'])->count())->toBe(0);
});

test('assignment uses normalized canonical user id and contains only structural metadata', function () {
    $assignment = foundationAssignment($this->subject, ['user_id' => new ObjectId((string) $this->subject->getKey())]);
    $persisted = $assignment->fresh();
    expect($persisted->user_id)->toBe((string) $this->subject->getKey())
        ->and($persisted->role_key)->toBe('cashier')->and($persisted->is_current)->toBeTrue()
        ->and($persisted->generation)->toBe(1)->and($persisted->revision)->toBe(1)
        ->and($persisted->assigned_at)->not->toBeNull();
});

test('alternate ObjectId spelling cannot create duplicate canonical subjects', function () {
    foundationAssignment($this->subject, ['user_id' => strtoupper((string) $this->subject->getKey())]);
    foundationAssertDuplicate(fn () => foundationAssignment($this->subject));
    $membership = foundationMembership($this->subject, ['user_id' => strtoupper((string) $this->subject->getKey())]);
    expect($membership->user_id)->toBe((string) $this->subject->getKey());
    foundationAssertDuplicate(fn () => foundationMembership($this->subject));
});

test('existing contextual types remain representable without entering legacy authorization', function (string $scope) {
    $assignment = foundationAssignment($this->subject, ['role_key' => 'estudiante', 'scope_type' => $scope]);
    expect($assignment->scope_type)->toBe($scope)->and($this->subject->fresh()->hasRole('estudiante', $scope, $assignment->scope_id))->toBeFalse();
})->with(['business', 'association', 'service', 'council']);

test('invalid assignment data is rejected', function (array $changes) {
    expect(fn () => foundationAssignment($this->subject, $changes))->toThrow(ValidationException::class);
})->with([
    'invalid status' => [['status' => 'enabled']],
    'unknown role' => [['role_key' => 'business_admin']],
    'invalid scope' => [['scope_type' => 'warehouse']],
    'missing scope id' => [['scope_id' => null]],
    'missing scope type' => [['scope_type' => null]],
    'wrong new role scope' => [['scope_type' => 'service']],
    'missing user' => [['user_id' => 'missing-user']],
    'invalid actor' => [['assigned_by' => 'missing-actor']],
    'invalid id type' => [['scope_id' => ['business']]],
    'empty external id' => [['scope_id' => '   ']],
    'unsafe external id' => [['scope_id' => "business\n1"]],
    'too long id' => [['scope_id' => str_repeat('a', 101)]],
    'invalid dates' => [['starts_at' => '2026-10-05', 'ends_at' => '2026-10-04']],
    'revoked at creation' => [['status' => 'revoked']],
    'suspended at creation' => [['status' => 'suspended']],
]);

test('internal references reject soft deleted users and profile ids', function () {
    $deleted = User::factory()->create();
    $deleted->delete();
    expect(fn () => foundationMembership($deleted))->toThrow(ValidationException::class);
    expect(fn () => foundationAssignment($this->subject, ['user_id' => (string) new ObjectId]))->toThrow(ValidationException::class);
});

test('current assignment uniqueness is enforced even for raw writes and normalized global contexts', function () {
    $assignment = foundationAssignment($this->subject);
    foundationAssertDuplicate(fn () => foundationAssignment($this->subject));
    $raw = DB::connection('mongodb')->getCollection('role_assignments')->findOne(['_id' => new ObjectId((string) $assignment->getKey())]);
    $raw = (array) $raw;
    unset($raw['_id']);
    $raw['generation'] = 100;
    foundationAssertDuplicate(fn () => DB::connection('mongodb')->getCollection('role_assignments')->insertOne($raw));
    foundationAssignment($this->subject, ['role_key' => 'auditor', 'scope_type' => null, 'scope_id' => null]);
    foundationAssertDuplicate(fn () => foundationAssignment($this->subject, ['role_key' => 'auditor', 'scope_type' => null, 'scope_id' => null]));
    foundationAssignment($this->subject, ['scope_id' => 'different-business']);
    expect(RoleAssignment::count())->toBe(3);
});

test('assignment lifecycle preserves revocation and creates a new generation', function () {
    $assignment = foundationAssignment($this->subject, ['status' => 'pending']);
    $assignment->transitionTo('active', $this->subject->getKey(), 'Activate');
    $assignment->transitionTo('suspended', $this->subject->getKey(), 'Suspend');
    $assignment->transitionTo('active', $this->subject->getKey(), 'Resume');
    $assignment->transitionTo('revoked', $this->subject->getKey(), 'Revoke');
    expect($assignment->status)->toBe('revoked')->and($assignment->is_current)->toBeFalse()
        ->and($assignment->revoked_at)->not->toBeNull()->and($assignment->revoked_by)->toBe((string) $this->subject->getKey())
        ->and($assignment->transitions)->toHaveCount(4);
    expect(fn () => $assignment->transitionTo('active'))->toThrow(ValidationException::class);
    expect(fn () => $assignment->delete())->toThrow(ValidationException::class);
    $new = foundationAssignment($this->subject);
    expect($new->generation)->toBe(2)->and($new->revision)->toBe(1)
        ->and(RoleAssignment::count())->toBe(2)->and($assignment->fresh()->status)->toBe('revoked');
});

test('pending cancellation and suspended revocation preserve both record types', function (string $model) {
    $record = $model === 'assignment' ? foundationAssignment($this->subject, ['status' => 'pending']) : foundationMembership($this->subject, ['status' => 'pending']);
    $record->transitionTo('revoked');
    $next = $model === 'assignment' ? foundationAssignment($this->subject) : foundationMembership($this->subject);
    $next->transitionTo('suspended');
    $next->transitionTo('revoked');
    expect($record->fresh()->is_current)->toBeFalse()->and($next->fresh()->is_current)->toBeFalse()
        ->and($next->generation)->toBe(2)->and($next->transitions)->toHaveCount(2);
})->with(['assignment', 'membership']);

test('stale lifecycle writers cannot overwrite a newer transition', function (string $model) {
    $record = $model === 'assignment' ? foundationAssignment($this->subject) : foundationMembership($this->subject);
    $stale = $record->fresh();
    $record->transitionTo('suspended', $this->subject->getKey(), 'Winner');
    expect(fn () => $stale->transitionTo('revoked'))->toThrow(ValidationException::class);
    expect($record->fresh()->status)->toBe('suspended')->and($record->fresh()->revision)->toBe(2)
        ->and($record->fresh()->transitions)->toHaveCount(1);
})->with(['assignment', 'membership']);

test('revision prevents a stale write after suspension and reactivation', function (string $model) {
    $record = $model === 'assignment' ? foundationAssignment($this->subject) : foundationMembership($this->subject);
    $stale = $record->fresh();
    $record->transitionTo('suspended');
    $record->transitionTo('active');
    expect(fn () => $stale->transitionTo('revoked'))->toThrow(ValidationException::class);
    expect($record->fresh()->status)->toBe('active')->and($record->fresh()->revision)->toBe(3)
        ->and($record->fresh()->transitions)->toHaveCount(2);
})->with(['assignment', 'membership']);

test('direct editing cannot bypass lifecycle or mutate identity', function (string $model) {
    $record = $model === 'assignment' ? foundationAssignment($this->subject) : foundationMembership($this->subject);
    $record->status = 'revoked';
    expect(fn () => $record->save())->toThrow(ValidationException::class);
    expect($record->fresh()->status)->toBe('active');
})->with(['assignment', 'membership']);

test('membership is independent from roles and accepts an opaque external business id', function () {
    $membership = foundationMembership($this->subject, ['business_id' => ' e3:business/opaque-42 ']);
    expect($membership->business_id)->toBe('e3:business/opaque-42')->and(RoleAssignment::count())->toBe(0);
    $membership->transitionTo('suspended', $this->subject->getKey(), 'Reason');
    expect($membership->suspended_at)->not->toBeNull()->and($membership->is_current)->toBeTrue();
    $membership->transitionTo('active');
    $membership->transitionTo('revoked');
    $new = foundationMembership($this->subject, ['business_id' => 'e3:business/opaque-42']);
    expect($new->generation)->toBe(2)->and(BusinessMembership::count())->toBe(2)
        ->and($membership->fresh()->transitions)->toHaveCount(3);
});

test('current membership and generation uniqueness are storage guarantees', function () {
    $membership = foundationMembership($this->subject);
    foundationAssertDuplicate(fn () => foundationMembership($this->subject));
    $collection = DB::connection('mongodb')->getCollection('business_memberships');
    $raw = (array) $collection->findOne(['_id' => new ObjectId((string) $membership->getKey())]);
    unset($raw['_id']);
    $raw['is_current'] = false;
    foundationAssertDuplicate(fn () => $collection->insertOne($raw));
    $raw['generation'] = 99;
    $raw['is_current'] = true;
    foundationAssertDuplicate(fn () => $collection->insertOne($raw));
    expect(BusinessMembership::count())->toBe(1);
});

test('membership validates states references and validity', function (array $changes) {
    expect(fn () => foundationMembership($this->subject, $changes))->toThrow(ValidationException::class);
})->with([
    [['status' => 'inactive']], [['business_id' => null]], [['business_id' => 42]],
    [['user_id' => 'missing']], [['starts_at' => '2026-10-05', 'ends_at' => '2026-10-05']],
]);

test('campus and academic program scopes validate internal references and campus consistency', function () {
    $campus = Campus::create(['code' => 'FIX', 'name' => 'Fixture']);
    $other = Campus::create(['code' => 'OTHER', 'name' => 'Other']);
    $program = AcademicProgram::create(['code' => 'P', 'name' => 'Program', 'campus_id' => (string) $campus->getKey()]);
    $campusAssignment = foundationAssignment($this->subject, ['role_key' => 'organization_manager', 'scope_type' => 'campus', 'scope_id' => $campus->getKey()]);
    $programAssignment = foundationAssignment($this->subject, ['role_key' => 'career_coordinator', 'scope_type' => 'academic_program', 'scope_id' => $program->getKey()]);
    expect($campusAssignment->scope_id)->toBe((string) $campus->getKey())->and($programAssignment->campus_id)->toBe((string) $campus->getKey());
    expect(fn () => foundationAssignment($this->subject, ['role_key' => 'career_coordinator', 'scope_type' => 'academic_program', 'scope_id' => $program->getKey(), 'campus_id' => $other->getKey()]))->toThrow(ValidationException::class);
    expect(fn () => foundationAssignment($this->subject, ['role_key' => 'organization_manager', 'scope_type' => 'campus', 'scope_id' => 'missing']))->toThrow(ValidationException::class);
    expect(fn () => foundationAssignment($this->subject, ['role_key' => 'career_coordinator', 'scope_type' => 'academic_program', 'scope_id' => 'missing']))->toThrow(ValidationException::class);
});

test('department is structurally registered but real assignments are deferred', function () {
    expect(Role::FOUNDATION_SCOPE_TYPES)->toContain('department')->and(Permission::where('key', 'academic.department.manage')->exists())->toBeTrue();
    expect(fn () => foundationAssignment($this->subject, ['role_key' => 'department_head', 'scope_type' => 'department', 'scope_id' => 'invented']))->toThrow(ValidationException::class);
    expect(class_exists('App\\Models\\Department'))->toBeFalse();
});

test('bootstrap and migrations are idempotent without changing users or backfilling', function () {
    $this->subject->assignRole(Role::ESTUDIANTE, 'council', 'legacy-1');
    $before = $this->subject->fresh()->getAttributes();
    $this->foundationMigration->up();
    $this->seed(AuthorizationFoundationSeeder::class);
    $this->seed(AuthorizationFoundationSeeder::class);
    expect(Role::count())->toBe(16)->and(Permission::count())->toBe(8)->and(RolePermission::count())->toBe(13)
        ->and(User::count())->toBe(1)->and($this->subject->fresh()->getAttributes())->toEqual($before)
        ->and(RoleAssignment::count())->toBe(0)->and(BusinessMembership::count())->toBe(0);
});

test('legacy role assignment exact lookup and revocation remain unchanged', function () {
    $this->subject->assignRole(Role::ESTUDIANTE);
    $this->subject->assignRole(Role::MAESTRO, 'business', 'legacy-business');
    $this->subject->assignRole(Role::MAESTRO, 'business', 'legacy-business');
    expect($this->subject->hasRole(Role::ESTUDIANTE))->toBeTrue()
        ->and($this->subject->hasRole(Role::MAESTRO, 'business', 'legacy-business'))->toBeTrue()
        ->and($this->subject->hasRole(Role::MAESTRO))->toBeFalse()->and($this->subject->roles)->toHaveCount(2);
    expect($this->subject->revokeRole(Role::MAESTRO, 'business', 'legacy-business'))->toBeTrue()
        ->and($this->subject->hasRole(Role::ESTUDIANTE))->toBeTrue()
        ->and($this->subject->revokeRole(Role::MAESTRO, 'business', 'legacy-business'))->toBeFalse()
        ->and(RoleAssignment::count())->toBe(0)->and(BusinessMembership::count())->toBe(0);
});

test('new catalog roles are unavailable through legacy model and HTTP assignment', function (string $role) {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);
    withConfirmedTestTwoFactor($admin);
    expect(fn () => $this->subject->assignRole($role, 'business', 'fixture'))->toThrow(InvalidArgumentException::class);
    $this->actingAs($admin)->postJson('/roles/assign', [
        'user_id' => (string) $this->subject->getKey(), 'role_name' => $role,
    ])->assertUnprocessable()->assertJsonValidationErrors('role_name');
    expect($this->subject->fresh()->roles)->toBe([])->and(RoleAssignment::count())->toBe(0);
})->with(Role::NEW_ROLES);

test('legacy UI exposes only existing roles and contexts after foundation bootstrap', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);
    withConfirmedTestTwoFactor($admin);
    $this->actingAs($admin)->get('/roles')->assertInertia(fn ($page) => $page
        ->component('Roles/Index')->where('assignableRoles', array_map(fn ($role) => ['name' => $role, 'display_name' => $role], Role::VALID_ROLES))
        ->where('assignableScopes', ['business', 'association', 'service', 'council'])->etc());
});

test('foundation records neither grant nor revoke legacy authorization or required 2fa', function () {
    foundationAssignment($this->subject, ['role_key' => 'admin', 'scope_type' => null, 'scope_id' => null]);
    $membership = foundationMembership($this->subject);
    expect($this->subject->fresh()->hasRole(Role::ADMIN))->toBeFalse()
        ->and($this->subject->fresh()->requiresTwoFactorAuthentication())->toBeFalse();
    $this->actingAs($this->subject)->post('/roles/assign', ['user_id' => (string) $this->subject->getKey(), 'role_name' => Role::ADMIN])->assertForbidden();
    $this->subject->assignRole(Role::MAESTRO, 'business', 'external-business-1');
    $membership->transitionTo('suspended');
    expect($this->subject->fresh()->hasRole(Role::MAESTRO, 'business', 'external-business-1'))->toBeTrue()
        ->and($this->subject->fresh()->requiresTwoFactorAuthentication())->toBeTrue();
});

test('migration rollback preserves existing records and history', function () {
    $assignment = foundationAssignment($this->subject);
    $assignment->transitionTo('revoked');
    $membership = foundationMembership($this->subject);
    $this->foundationMigration->down();
    expect($assignment->fresh()->status)->toBe('revoked')->and($membership->fresh()->status)->toBe('active')
        ->and(User::count())->toBe(1)->and(Permission::count())->toBe(8);
    $this->foundationMigration->up();
});

test('artisan migration flow runs safely and can be repeated without touching legacy roles', function () {
    $this->subject->assignRole(Role::ESTUDIANTE);
    $roles = $this->subject->fresh()->roles;
    $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_05_000100_create_authorization_foundation_indexes.php', '--force' => true])->assertSuccessful();
    $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_05_000100_create_authorization_foundation_indexes.php', '--force' => true])->assertSuccessful();
    expect($this->subject->fresh()->roles)->toBe($roles)
        ->and(RoleAssignment::count())->toBe(0)->and(BusinessMembership::count())->toBe(0);
});
