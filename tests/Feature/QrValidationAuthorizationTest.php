<?php

use App\Models\QrValidation;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\IdentityService;

function webQrStudent(): User
{
    $student = User::factory()->create();

    StudentProfile::create([
        'user_id' => (string) $student->getKey(),
        'enrollment_number' => 'WEB-'.str_replace('-', '', (string) $student->getKey()),
        'academic_status' => 'active',
    ]);

    return $student;
}

test('a global admin can validate another student QR and the authenticated actor is recorded', function () {
    $admin = User::factory()->create(['name' => 'Administrador QR']);
    $admin->assignRole(Role::ADMIN);
    $student = webQrStudent();
    $identity = app(IdentityService::class);
    $token = $identity->issueDynamicQrToken($student);

    $this->actingAs($admin)->postJson(route('identity.qr.simulate'), [
        'code' => $identity->buildQrPayload($token),
        'context' => 'biblioteca-central',
        'validator_label' => 'Otro validador',
        'validated_by_user_id' => (string) $student->getKey(),
    ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('result', 'valid')
        ->assertJsonPath('identity.user_id', (string) $student->getKey());

    $validation = QrValidation::where('qr_token_id', (string) $token->token->getKey())->firstOrFail();

    expect($token->token->fresh()->consumed_at)->not->toBeNull()
        ->and($validation->validated_by_user_id)->toBe((string) $admin->getKey())
        ->and($validation->validator_label)->toBe('Administrador QR')
        ->and($validation->validator_label)->not->toBe('Otro validador')
        ->and($validation->context)->toBe('biblioteca-central');
});

test('a student cannot validate another student QR or cause validation side effects', function () {
    $actor = webQrStudent();
    $actor->assignRole(Role::ESTUDIANTE);
    $student = webQrStudent();
    $identity = app(IdentityService::class);
    $token = $identity->issueDynamicQrToken($student);

    $validationCount = QrValidation::count();
    $securityCount = SecurityEvent::whereIn('type', ['qr_validated', 'qr_validation_failed'])->count();

    $this->actingAs($actor)->postJson(route('identity.qr.simulate'), [
        'code' => $identity->buildQrPayload($token),
        'validator_label' => 'Administrador QR',
    ])->assertForbidden();

    expect($token->token->fresh()->consumed_at)->toBeNull()
        ->and(QrValidation::count())->toBe($validationCount)
        ->and(SecurityEvent::whereIn('type', ['qr_validated', 'qr_validation_failed'])->count())->toBe($securityCount);
});

test('a contextual admin role does not grant global web QR validation', function () {
    $actor = User::factory()->create();
    $actor->assignRole(Role::ADMIN, 'service', 'service-1');
    $student = webQrStudent();
    $identity = app(IdentityService::class);
    $token = $identity->issueDynamicQrToken($student);

    $this->actingAs($actor)->postJson(route('identity.qr.simulate'), [
        'code' => $identity->buildQrPayload($token),
        'context' => 'service-1',
    ])->assertForbidden();

    expect($token->token->fresh()->consumed_at)->toBeNull()
        ->and(QrValidation::where('qr_token_id', (string) $token->token->getKey())->exists())->toBeFalse();
});

test('the QR page exposes the policy result without hiding the owner QR and history', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);
    $student = webQrStudent();
    $student->assignRole(Role::ESTUDIANTE);

    $this->actingAs($admin)->get(route('identity.qr.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Security/QrIdentity')
            ->where('canValidate', true)
            ->has('identificationPayload')
            ->has('recentValidations'));

    $this->actingAs($student)->get(route('identity.qr.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Security/QrIdentity')
            ->where('canValidate', false)
            ->has('identificationPayload')
            ->has('recentValidations'));
});
