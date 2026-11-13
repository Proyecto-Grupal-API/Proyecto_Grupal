<?php

use App\Actions\Students\ChangeStudentAcademicStatus;
use App\Models\AcademicStatusHistory;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

function statusValue(mixed $value): mixed { return $value instanceof BackedEnum ? $value->value : $value; }

// Rúbrica 3.3 (bajas lógicas): E1 no borra estudiantes; cambia su condición académica con historial auditable.
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(); $this->admin->assignRole('admin');
    $this->student = User::factory()->create();
    $this->profile = StudentProfile::create(['user_id' => (string) $this->student->getKey(),
        'enrollment_number' => 'BAJA-'.substr((string) $this->student->getKey(), -8), 'academic_status' => 'active']);
});

test('an administrator deactivates a student logically with an auditable history and no deletion', function () {
    $result = app(ChangeStudentAcademicStatus::class)->execute($this->profile, 'inactive', 'Baja voluntaria sintética', $this->admin);
    expect($result->academic_status->value)->toBe('inactive')
        ->and(StudentProfile::whereKey($this->profile->getKey())->exists())->toBeTrue()
        ->and(User::whereKey($this->student->getKey())->exists())->toBeTrue();
    $history = AcademicStatusHistory::where('student_profile_id', (string) $this->profile->getKey())->get();
    expect($history)->toHaveCount(1)
        ->and(statusValue($history[0]->from_status))->toBe('active')->and(statusValue($history[0]->to_status))->toBe('inactive')
        ->and($history[0]->reason)->toBe('Baja voluntaria sintética')
        ->and($history[0]->changed_by)->toBe((string) $this->admin->getKey());
});

test('repeating the same status is idempotent and reactivation is also recorded', function () {
    $action = app(ChangeStudentAcademicStatus::class);
    $action->execute($this->profile, 'inactive', 'Baja', $this->admin);
    $action->execute($this->profile->fresh(), 'inactive', 'Baja repetida', $this->admin);
    expect(AcademicStatusHistory::where('student_profile_id', (string) $this->profile->getKey())->count())->toBe(1);
    $action->execute($this->profile->fresh(), 'active', 'Reingreso', $this->admin);
    expect($this->profile->fresh()->academic_status->value)->toBe('active')
        ->and(AcademicStatusHistory::where('student_profile_id', (string) $this->profile->getKey())->count())->toBe(2);
});

test('an unknown status is rejected without changing the profile or its history', function () {
    expect(fn () => app(ChangeStudentAcademicStatus::class)->execute($this->profile, 'deleted', 'Inválido', $this->admin))
        ->toThrow(ValidationException::class);
    expect($this->profile->fresh()->academic_status->value)->toBe('active')
        ->and(AcademicStatusHistory::count())->toBe(0);
});

test('only student managers may change the academic status', function () {
    expect(Gate::forUser($this->admin)->allows('updateAcademicStatus', $this->profile))->toBeTrue()
        ->and(Gate::forUser($this->student)->allows('updateAcademicStatus', $this->profile))->toBeFalse();
});
