<?php

use App\Contracts\BonusAuthorization;
use App\Contracts\FinancialGrantAuthority;
use App\Models\AcademicProgram;
use App\Models\BusinessMembership;
use App\Models\Campus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\FinancialRoleGrantService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Tests\Support\FinancialPolicyFixtures as Fixtures;

beforeEach(function () {
    Fixtures::seed($this);
    $this->authority = User::factory()->create();
    $this->subject = User::factory()->create();
    $this->writer = Fixtures::writer($this->authority);
});

test('seven versioned branches work with test-only designation and preserve audit on revocation', function (string $role, string $bonus, ?string $scope) {
    $id = $scope === 'business' ? 'business-A' : null;
    if ($scope === 'business') {
        BusinessMembership::create(['user_id' => (string) $this->subject->getKey(), 'business_id' => $id, 'status' => 'active']);
    }
    if ($scope === 'academic_program') {
        $campus = Campus::create(['code' => 'A', 'name' => 'Campus fixture', 'is_active' => true]);
        $program = AcademicProgram::create(['code' => 'P', 'name' => 'Program fixture', 'campus_id' => (string) $campus->getKey(), 'is_active' => true]);
        $id = (string) $program->getKey();
    }
    $record = $this->writer->grant($this->authority, $this->subject, $role, $scope, $id, 'Explicit synthetic test decision');
    expect(app(BonusAuthorization::class)->allows((string) $this->subject->getKey(), $bonus, 'issue', $scope, $id))->toBeTrue()
        ->and($record->assigned_by)->toBe((string) $this->authority->getKey())
        ->and($record->origin)->toBe('financial_role_management')->and($record->generation)->toBe(1)
        ->and(SecurityEvent::where('type', 'financial.role.granted')->first()->metadata['authority_reference'])->toBe('synthetic-testing-designation-not-institutional');
    expect($this->writer->revoke($this->authority, $this->subject, $role, $scope, $id, 'Synthetic revocation'))->toBeTrue();
    $record->refresh();
    expect($record->status)->toBe('revoked')->and($record->is_current)->toBeFalse()
        ->and($record->transitions[0]['actor_id'])->toBe((string) $this->authority->getKey())
        ->and($record->transitions[0]['reason'])->toBe('Synthetic revocation')
        ->and(SecurityEvent::whereIn('type', ['financial.role.granted', 'financial.role.revoked'])->count())->toBe(2)
        ->and(app(BonusAuthorization::class)->allows((string) $this->subject->getKey(), $bonus, 'issue', $scope, $id))->toBeFalse();
})->with([
    ['bonus_beneficio_issuer', 'BENEFICIO', null], ['bonus_categoria_issuer', 'CATEGORIA', null],
    ['bonus_negocio_issuer', 'NEGOCIO', null], ['bonus_negocio_business_issuer', 'NEGOCIO', 'business'],
    ['bonus_beca_issuer', 'BECA', null], ['bonus_beca_program_issuer', 'BECA', 'academic_program'],
    ['bonus_campania_issuer', 'CAMPANIA', null],
]);

test('financial rights require the exact persisted mapping active permission and lifecycle', function (string $case) {
    $record = $this->writer->grant($this->authority, $this->subject, 'bonus_beneficio_issuer', null, null, 'Synthetic decision');
    match ($case) {
        'missing mapping' => RolePermission::where('role_key', $record->role_key)->delete(),
        'wrong mapping' => DB::connection('mongodb')->getCollection('role_permissions')->updateOne(['role_key' => $record->role_key], ['$set' => ['permission_key' => 'bonuses.beca.issue']]),
        'inactive permission' => Permission::where('key', 'bonuses.beneficio.issue')->first()->update(['active' => false]),
        'expired' => $this->travel(1)->minutes(),
        'future' => DB::connection('mongodb')->getCollection('role_assignments')->updateOne(['_id' => new ObjectId((string) $record->getKey())], ['$set' => ['starts_at' => new UTCDateTime(now()->addDay())]]),
    };
    if ($case === 'expired') {
        DB::connection('mongodb')->getCollection('role_assignments')->updateOne(['_id' => new ObjectId((string) $record->getKey())], ['$set' => ['ends_at' => new UTCDateTime(now())]]);
    }
    expect(app(BonusAuthorization::class)->allows((string) $this->subject->getKey(), 'BENEFICIO', 'issue'))->toBeFalse()
        ->and(app(BonusAuthorization::class)->allows((string) $this->subject->getKey(), 'CATEGORIA', 'issue'))->toBeFalse();
})->with(['missing mapping', 'wrong mapping', 'inactive permission', 'expired', 'future']);

test('unaccredited actors including admin cannot grant revoke or self-assign financial roles', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    foreach (['grant', 'revoke'] as $operation) {
        expect(fn () => $this->writer->$operation($admin, $this->subject, 'bonus_beneficio_issuer', null, null, 'Not a designation'))->toThrow(AuthorizationException::class);
    }
    expect(fn () => $this->writer->grant($this->authority, $this->authority, 'bonus_beneficio_issuer', null, null, 'Self'))->toThrow(AuthorizationException::class)
        ->and(RoleAssignment::whereIn('role_key', Role::FINANCIAL_ROLES)->count())->toBe(0);
});

test('invalid roles scopes subjects membership and validity cannot create financial grants', function (string $case) {
    $role = $case === 'unknown role' ? 'admin' : 'bonus_beneficio_issuer';
    $type = $case === 'wrong scope' ? 'business' : null;
    $id = $type ? 'A' : null;
    if ($case === 'missing membership') {
        $role = 'bonus_negocio_business_issuer';
        $type = 'business';
        $id = 'A';
    }
    if ($case === 'deleted subject') {
        $this->subject->delete();
    }
    expect(fn () => $this->writer->grant($this->authority, $this->subject, $role, $type, $id, 'Synthetic decision',
        $case === 'inverted window' ? now()->addDays(2) : null,
        in_array($case, ['expired window', 'inverted window']) ? now()->addDay()->subDays($case === 'expired window' ? 2 : 0) : null
    ))->toThrow($case === 'inverted window' || $case === 'expired window' ? ValidationException::class : AuthorizationException::class)
        ->and(RoleAssignment::count())->toBe(0);
})->with(['unknown role', 'wrong scope', 'missing membership', 'deleted subject', 'expired window', 'inverted window']);

test('production remains locked even with a manually supplied synthetic authority and no authority binding exists', function () {
    expect(app()->bound(FinancialGrantAuthority::class))->toBeFalse()
        ->and(fn () => app(FinancialRoleGrantService::class))->toThrow(BindingResolutionException::class);
    app()->instance('env', 'local');
    try {
        expect(fn () => $this->writer->grant($this->authority, $this->subject, 'bonus_beneficio_issuer', null, null, 'Not approved'))->toThrow(AuthorizationException::class);
    } finally {
        app()->instance('env', 'testing');
    }
    expect(RoleAssignment::count())->toBe(0);
});

test('financial audit failure rolls back assignment creation without an orphan grant', function () {
    SecurityEvent::creating(function ($event) {
        if ($event->type === 'financial.role.granted') {
            throw new RuntimeException('Synthetic audit failure');
        }
    });
    expect(fn () => $this->writer->grant($this->authority, $this->subject, 'bonus_beneficio_issuer', null, null, 'Synthetic decision'))->toThrow(RuntimeException::class)
        ->and(RoleAssignment::count())->toBe(0)->and(SecurityEvent::where('type', 'financial.role.granted')->count())->toBe(0);
});

test('financial audit failure rolls back revocation and existing generations cannot be silently replaced', function () {
    $record = $this->writer->grant($this->authority, $this->subject, 'bonus_beneficio_issuer', null, null, 'Synthetic decision');
    expect(fn () => $this->writer->grant($this->authority, $this->subject, 'bonus_beneficio_issuer', null, null, 'Attempted replacement'))->toThrow(ValidationException::class);
    SecurityEvent::creating(function ($event) {
        if ($event->type === 'financial.role.revoked') {
            throw new RuntimeException('Synthetic revocation audit failure');
        }
    });
    expect(fn () => $this->writer->revoke($this->authority, $this->subject, 'bonus_beneficio_issuer', null, null, 'Synthetic revocation'))->toThrow(RuntimeException::class);
    $record->refresh();
    expect($record->status)->toBe('active')->and($record->revision)->toBe(1)
        ->and(RoleAssignment::count())->toBe(1)->and(SecurityEvent::where('type', 'financial.role.revoked')->count())->toBe(0);
});

test('testing environment alone cannot unlock grants against development configuration', function () {
    config(['database.connections.mongodb.database' => 'campus_virtual']);
    try {
        expect(fn () => $this->writer->grant($this->authority, $this->subject, 'bonus_beneficio_issuer', null, null, 'Invalid target'))->toThrow(AuthorizationException::class);
    } finally {
        config(['database.connections.mongodb.database' => 'campus_virtual_testing']);
    }
    expect(RoleAssignment::count())->toBe(0);
});

test('institutional catalogs and business boundaries constrain real technical financial grants', function (string $case) {
    $campus = Campus::create(['code' => 'A', 'name' => 'Campus fixture', 'is_active' => true]);
    $program = AcademicProgram::create(['code' => 'P', 'name' => 'Program fixture', 'campus_id' => (string) $campus->getKey(), 'is_active' => true]);
    $other = AcademicProgram::create(['code' => 'Q', 'name' => 'Other fixture', 'campus_id' => (string) $campus->getKey(), 'is_active' => true]);
    $record = $this->writer->grant($this->authority, $this->subject, 'bonus_beca_program_issuer', 'academic_program', (string) $program->getKey(), 'Synthetic program designation');
    expect(app(BonusAuthorization::class)->allows((string) $this->subject->getKey(), 'BECA', 'issue', 'academic_program', (string) $other->getKey()))->toBeFalse()
        ->and(app(BonusAuthorization::class)->allows((string) $this->subject->getKey(), 'BECA', 'issue'))->toBeFalse();
    if ($case === 'campus') {
        $campus->update(['is_active' => false]);
    } else {
        $program->update(['is_active' => false]);
    }
    expect(app(BonusAuthorization::class)->allows((string) $this->subject->getKey(), 'BECA', 'issue', 'academic_program', (string) $program->getKey()))->toBeFalse()
        ->and($this->writer->revoke($this->authority, $this->subject, 'bonus_beca_program_issuer', 'academic_program', (string) $program->getKey(), 'Retire inactive context'))->toBeTrue();
})->with(['campus', 'program']);
