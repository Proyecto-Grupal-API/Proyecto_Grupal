<?php

use App\Models\AcademicProgram;
use App\Models\BusinessMembership;
use App\Models\Campus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\InstitutionalAuthorizationService;
use Database\Seeders\AuthorizationFoundationSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

beforeEach(function () {
    (require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php'))->up();
    $this->seed(AuthorizationFoundationSeeder::class);
    $this->engine = app(InstitutionalAuthorizationService::class);
    $this->subject = User::factory()->create();
    $this->campus = Campus::create(['code' => 'A', 'name' => 'Campus A', 'is_active' => true]);
    $this->otherCampus = Campus::create(['code' => 'B', 'name' => 'Campus B', 'is_active' => true]);
    $this->program = AcademicProgram::create(['code' => 'P', 'name' => 'Program A', 'campus_id' => (string) $this->campus->getKey(), 'is_active' => true]);
    $this->otherProgram = AcademicProgram::create(['code' => 'Q', 'name' => 'Program B', 'campus_id' => (string) $this->campus->getKey(), 'is_active' => true]);
});

function institutionalAssignment(User $user, string $role, string $type, string $id, array $changes = []): RoleAssignment
{
    return RoleAssignment::create([...['user_id' => (string) $user->getKey(), 'role_key' => $role,
        'scope_type' => $type, 'scope_id' => $id, 'status' => 'active'], ...$changes]);
}

test('institutional capabilities require the exact canonical persistent matrix and scope', function (string $role, string $type, string $capability) {
    $scope = $type === 'campus' ? $this->campus : $this->program;
    $other = $type === 'campus' ? $this->otherCampus : $this->otherProgram;
    institutionalAssignment($this->subject, $role, $type, (string) $scope->getKey());
    foreach (Permission::CATALOG as $key => $definition) {
        expect($this->engine->allows($this->subject, $key, $type, (string) $scope->getKey()))->toBe($key === $capability);
    }
    expect($this->engine->allows($this->subject, $capability, $type, (string) $other->getKey()))->toBeFalse()
        ->and(BusinessMembership::count())->toBe(0);
})->with([
    ['organization_manager', 'campus', 'organizations.institutional.manage'],
    ['career_coordinator', 'academic_program', 'academic.program.coordinate'],
]);

test('institutional assignment lifecycle is active current and within exact validity boundaries', function (string $state, bool $allow) {
    $this->freezeTime();
    $record = institutionalAssignment($this->subject, 'career_coordinator', 'academic_program', (string) $this->program->getKey(),
        $state === 'pending' ? ['status' => 'pending'] : []);
    if (in_array($state, ['suspended', 'revoked'], true)) {
        $record->transitionTo($state);
    } elseif (in_array($state, ['non-current', 'future', 'expired', 'starts now', 'ends later', 'unknown'], true)) {
        $changes = match ($state) {
            'non-current' => ['is_current' => false], 'unknown' => ['status' => 'unknown'],
            'future' => ['starts_at' => new UTCDateTime(now()->addSecond())],
            'expired' => ['ends_at' => new UTCDateTime(now())],
            'starts now' => ['starts_at' => new UTCDateTime(now())],
            'ends later' => ['ends_at' => new UTCDateTime(now()->addSecond())],
        };
        // Deliberate raw storage fixtures exercise fail-closed reads, not production mutation APIs.
        DB::connection('mongodb')->getCollection('role_assignments')->updateOne(['_id' => new ObjectId((string) $record->getKey())], ['$set' => $changes]);
    }
    expect($this->engine->allows($this->subject, 'academic.program.coordinate', 'academic_program', (string) $this->program->getKey()))->toBe($allow);
})->with([
    ['active', true], ['pending', false], ['suspended', false], ['revoked', false], ['non-current', false],
    ['future', false], ['expired', false], ['starts now', true], ['ends later', true], ['unknown', false],
]);

test('institutional identity catalogs mappings and canonical coherence fail closed without query writes', function (string $case) {
    $record = institutionalAssignment($this->subject, 'career_coordinator', 'academic_program', (string) $this->program->getKey());
    $subject = $this->subject;
    $capability = 'academic.program.coordinate';
    $type = 'academic_program';
    $id = (string) $this->program->getKey();
    switch ($case) {
        case 'null user': $subject = null;
            break;
        case 'unsaved user': $subject = new User;
            break;
        case 'soft deleted stale user': $subject->fresh()->delete();
            break;
        case 'missing persisted user': DB::connection('mongodb')->getCollection('users')->deleteOne(['_id' => new ObjectId((string) $subject->getKey())]);
            break;
        case 'activation pending': $subject->fresh()->forceFill(['account_activation_pending' => true])->save();
            break;
        case 'initial password required': $subject->fresh()->forceFill(['must_change_password' => true])->save();
            break;
        case 'unknown capability': $capability = 'bonus.manage';
            break;
        case 'business capability': $capability = 'business.manage';
            break;
        case 'unknown scope': $type = 'association';
            break;
        case 'null scope': $type = null;
            break;
        case 'null id': $id = null;
            break;
        case 'empty id': $id = '';
            break;
        case 'unsafe id': $id = "A\nB";
            break;
        case 'long id': $id = str_repeat('A', 101);
            break;
        case 'missing scope': $id = (string) new ObjectId;
            break;
        case 'inactive program': $this->program->update(['is_active' => false]);
            break;
        case 'missing program': $this->program->delete();
            break;
        case 'inactive campus': $this->campus->update(['is_active' => false]);
            break;
        case 'missing campus': $this->campus->delete();
            break;
        case 'program reassigned campus': $this->program->update(['campus_id' => (string) $this->otherCampus->getKey()]);
            break;
        case 'missing role': Role::where('name', 'career_coordinator')->delete();
            break;
        case 'missing permission': Permission::where('key', $capability)->delete();
            break;
        case 'inactive permission': Permission::where('key', $capability)->first()->update(['active' => false]);
            break;
        case 'missing mapping': RolePermission::where('role_key', 'career_coordinator')->delete();
            break;
        case 'missing assignment': DB::connection('mongodb')->getCollection('role_assignments')->deleteOne(['_id' => new ObjectId((string) $record->getKey())]);
            break;
    }
    $db = DB::connection('mongodb')->getDatabase();
    $before = [];
    foreach (['users', 'role_assignments', 'roles', 'permissions', 'role_permissions', 'campuses', 'academic_programs', 'business_memberships'] as $name) {
        $before[$name] = hash('sha256', serialize(iterator_to_array($db->selectCollection($name)->find([], ['sort' => ['_id' => 1]]))));
    }
    expect($this->engine->allows($subject, $capability, $type, $id))->toBeFalse();
    foreach ($before as $name => $fingerprint) {
        expect(hash('sha256', serialize(iterator_to_array($db->selectCollection($name)->find([], ['sort' => ['_id' => 1]])))))->toBe($fingerprint);
    }
})->with(['null user', 'unsaved user', 'soft deleted stale user', 'missing persisted user', 'activation pending', 'initial password required',
    'unknown capability', 'business capability', 'unknown scope', 'null scope', 'null id', 'empty id', 'unsafe id', 'long id', 'missing scope',
    'inactive program', 'missing program', 'inactive campus', 'missing campus', 'program reassigned campus', 'missing role', 'missing permission',
    'inactive permission', 'missing mapping', 'missing assignment']);

test('campus scope revalidation rejects inactive missing and noncanonical ids', function (string $case) {
    institutionalAssignment($this->subject, 'organization_manager', 'campus', (string) $this->campus->getKey());
    $id = (string) $this->campus->getKey();
    if ($case === 'inactive') {
        $this->campus->update(['is_active' => false]);
    }
    if ($case === 'missing') {
        $this->campus->delete();
    }
    if ($case === 'name') {
        $id = 'Campus A';
    }
    if ($case === 'code') {
        $id = 'A';
    }
    expect($this->engine->allows($this->subject, 'organizations.institutional.manage', 'campus', $id))->toBeFalse();
})->with(['inactive', 'missing', 'name', 'code']);

test('multiple institutional roles never infer campus program or cross-scope grants', function () {
    institutionalAssignment($this->subject, 'career_coordinator', 'academic_program', (string) $this->program->getKey());
    expect($this->engine->allows($this->subject, 'organizations.institutional.manage', 'campus', (string) $this->campus->getKey()))->toBeFalse();
    institutionalAssignment($this->subject, 'organization_manager', 'campus', (string) $this->campus->getKey());
    expect($this->engine->allows($this->subject, 'academic.program.coordinate', 'academic_program', (string) $this->otherProgram->getKey()))->toBeFalse()
        ->and($this->engine->allows($this->subject, 'academic.program.coordinate', 'academic_program', (string) $this->program->getKey()))->toBeTrue();
    $other = User::factory()->create();
    institutionalAssignment($other, 'organization_manager', 'campus', (string) $this->campus->getKey());
    expect($this->engine->allows($other, 'academic.program.coordinate', 'academic_program', (string) $this->program->getKey()))->toBeFalse();
});

test('department remains unassignable and even forged storage cannot authorize arbitrary departments', function () {
    expect(fn () => institutionalAssignment($this->subject, 'department_head', 'department', 'invented'))
        ->toThrow(ValidationException::class);
    DB::connection('mongodb')->getCollection('role_assignments')->insertOne([
        'user_id' => (string) $this->subject->getKey(), 'role_key' => 'department_head', 'scope_type' => 'department',
        'scope_id' => 'invented', 'status' => 'active', 'is_current' => true, 'generation' => 1,
    ]);
    expect($this->engine->allows($this->subject, 'academic.department.manage', 'department', 'invented'))->toBeFalse();
});

test('persistent matrix rejects forged grants without hardcoded admin or legacy authority', function () {
    $this->subject->forceFill(['roles' => [['name' => 'organization_manager', 'scope_type' => 'campus', 'scope_id' => (string) $this->campus->getKey()]]])->save();
    $this->subject->assignRole('admin');
    expect($this->subject->hasRole('admin'))->toBeTrue()
        ->and($this->engine->allows($this->subject, 'organizations.institutional.manage', 'campus', (string) $this->campus->getKey()))->toBeFalse();
    institutionalAssignment($this->subject, 'organization_manager', 'campus', (string) $this->campus->getKey());
    DB::connection('mongodb')->getCollection('role_permissions')->insertOne(['role_key' => 'organization_manager', 'permission_key' => 'academic.program.coordinate']);
    expect($this->engine->allows($this->subject, 'academic.program.coordinate', 'campus', (string) $this->campus->getKey()))->toBeFalse()
        ->and($this->subject->hasRole('organization_manager', 'campus', (string) $this->campus->getKey()))->toBeFalse();
});

test('institutional canonical mappings and scope creation reuse foundation invariants', function () {
    expect(RolePermission::MAPPINGS['organization_manager'])->toBe(['organizations.institutional.manage'])
        ->and(RolePermission::MAPPINGS['career_coordinator'])->toBe(['academic.program.coordinate'])
        ->and(RolePermission::MAPPINGS['department_head'])->toBe(['academic.department.manage'])
        ->and(Role::VALID_ROLES)->toHaveCount(6);
    expect(fn () => institutionalAssignment($this->subject, 'career_coordinator', 'academic_program', (string) $this->program->getKey(), ['campus_id' => (string) $this->otherCampus->getKey()]))->toThrow(ValidationException::class);
});
