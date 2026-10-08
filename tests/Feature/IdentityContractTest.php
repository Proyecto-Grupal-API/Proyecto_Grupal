<?php

use App\Models\AcademicProgram;
use App\Models\Campus;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds a safe internal identity contract from a student profile', function () {
    $user = User::factory()->create([
        'name' => 'Ana Estudiante',
        'two_factor_secret' => 'secret-value',
        'two_factor_recovery_codes' => '["recovery-code"]',
    ]);
    $campus = Campus::create(['code' => 'CENTRAL', 'name' => 'Campus Central', 'is_active' => true]);
    $program = AcademicProgram::create([
        'campus_id' => (string) $campus->getKey(),
        'code' => 'ISC',
        'name' => 'Ingeniería de Sistemas',
        'is_active' => true,
    ]);
    StudentProfile::create([
        'user_id' => (string) $user->getKey(),
        'enrollment_number' => 'A2026001',
        'campus_id' => (string) $campus->getKey(),
        'academic_program_id' => (string) $program->getKey(),
        'academic_status' => 'active',
        'personal_email' => 'private@example.test',
        'phone' => '+52 5555555555',
    ]);

    $identity = $user->fresh()->displayIdentity();

    expect($identity)->toBe([
        'user_id' => (string) $user->getKey(),
        'name' => 'Ana Estudiante',
        'student' => [
            'enrollment_number' => 'A2026001',
            'campus' => ['code' => 'CENTRAL', 'name' => 'Campus Central'],
            'academic_program' => ['code' => 'ISC', 'name' => 'Ingeniería de Sistemas'],
            'academic_status' => 'active',
        ],
    ]);
});

it('does not expose user secrets or private contact fields in the identity contract', function () {
    $user = User::factory()->create([
        'password' => 'password',
        'two_factor_secret' => 'secret-value',
        'two_factor_recovery_codes' => '["recovery-code"]',
        'remember_token' => 'remember-token',
    ]);
    StudentProfile::create([
        'user_id' => (string) $user->getKey(),
        'enrollment_number' => 'A2026002',
        'academic_status' => 'active',
        'personal_email' => 'private@example.test',
        'phone' => '+52 5555555555',
    ]);

    $identity = $user->fresh()->displayIdentity();

    expect(json_encode($identity))
        ->not->toContain('password')
        ->not->toContain('secret-value')
        ->not->toContain('recovery-code')
        ->not->toContain('remember-token')
        ->not->toContain('private@example.test')
        ->not->toContain('+52 5555555555');
});
