<?php

use App\Enums\StudentStatus;
use App\Models\AcademicStatusHistory;
use App\Models\CommunicationPreference;
use App\Models\Consent;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function studentWithStatus(string $status = 'active'): array
{
    $user = User::factory()->create();
    $profile = StudentProfile::create(['user_id' => (string) $user->getKey(), 'enrollment_number' => 'M'.str_replace('-', '', (string) $user->getKey()), 'academic_status' => $status, 'current_semester' => 3]);
    AcademicStatusHistory::create(['student_profile_id' => (string) $profile->getKey(), 'from_status' => null, 'to_status' => $status, 'reason' => 'Registro inicial', 'changed_at' => now()]);
    return [$user, $profile];
}

it('returns each persisted academic status instead of a hardcoded status', function (string $status) {
    [, $profile] = studentWithStatus($status);
    $service = app(\App\Services\StudentStatusService::class);
    expect($service->forUserId((string) $profile->user_id)['status'])->toBe($status);
})->with(['active', 'graduated', 'suspended', 'restricted']);

it('changes status with an actor, reason, and a persistent history entry', function () {
    [$manager] = studentWithStatus();
    $manager->assignRole('student_manager');
    expect($manager->fresh()->hasRole('student_manager'))->toBeTrue();
    [, $profile] = studentWithStatus();

    $this->actingAs($manager)->patchJson("/student-services/students/{$profile->getKey()}/status", ['status' => 'suspended', 'reason' => 'Expediente en revisión'])
        ->assertOk()->assertJsonPath('data.status', 'suspended');

    expect($profile->fresh()->academic_status)->toBe(StudentStatus::Suspended);
    $history = AcademicStatusHistory::where('student_profile_id', (string) $profile->getKey())->latest('changed_at')->first();
    expect($history->reason)->toBe('Expediente en revisión')->and((string) $history->changed_by)->toBe((string) $manager->getKey());
});

it('does not create duplicate academic history when status is unchanged', function () {
    [$manager] = studentWithStatus(); $manager->assignRole('student_manager');
    expect($manager->fresh()->hasRole('student_manager'))->toBeTrue();
    [, $profile] = studentWithStatus();
    $before = AcademicStatusHistory::where('student_profile_id', (string) $profile->getKey())->count();
    $this->actingAs($manager)->patchJson("/student-services/students/{$profile->getKey()}/status", ['status' => 'active', 'reason' => 'Sin cambio'])->assertOk();
    expect(AcademicStatusHistory::where('student_profile_id', (string) $profile->getKey())->count())->toBe($before);
});

it('forbids a normal student from changing any academic status', function () {
    [$student, $profile] = studentWithStatus();
    $this->actingAs($student)->patchJson("/student-services/students/{$profile->getKey()}/status", ['status' => 'suspended', 'reason' => 'Intento propio'])->assertForbidden();
});

it('persists versioned consent acceptance and revocation history', function () {
    [$user] = studentWithStatus();
    $accepted = $this->actingAs($user)->postJson('/student-services/consents', ['consent_id' => 'terms', 'consent_version' => 'v1'])->assertCreated()->json('data');
    $this->actingAs($user)->deleteJson('/student-services/consents/terms', ['consent_record_id' => $accepted['acceptance_id']])->assertOk()->assertJsonPath('data.status', 'revoked');
    $revocation = Consent::where('revokes_consent_id', $accepted['acceptance_id'])->first();
    expect($revocation)->not->toBeNull()->and($revocation->version)->toBe('v1')->and((string) $revocation->actor_id)->toBe((string) $user->getKey());
    $this->actingAs($user)->deleteJson('/student-services/consents/terms', ['consent_record_id' => $accepted['acceptance_id']])->assertUnprocessable();
    $acceptedV2 = $this->actingAs($user)->postJson('/student-services/consents', ['consent_id' => 'terms', 'consent_version' => 'v2'])->assertCreated()->json('data');
    expect($acceptedV2['acceptance_id'])->not->toBe($accepted['acceptance_id']);
    expect(Consent::where('user_id', (string) $user->getKey())->where('type', 'terms')->count())->toBe(3);
});

it('does not create preferences during an initial GET and returns persisted values after PATCH', function () {
    [$user, $profile] = studentWithStatus();
    expect(CommunicationPreference::where('user_id', (string) $user->getKey())->count())->toBe(0);
    $this->actingAs($user)->getJson("/student-services/students/{$profile->getKey()}/preferences")->assertOk()->assertJsonPath('data.preferences.email', false);
    expect(CommunicationPreference::where('user_id', (string) $user->getKey())->count())->toBe(0);
    $this->actingAs($user)->patchJson('/student-services/preferences', ['email' => true, 'push' => false, 'sms' => true])->assertOk();
    $this->actingAs($user)->getJson("/student-services/students/{$profile->getKey()}/preferences")->assertOk()->assertJsonPath('data.preferences.email', true)->assertJsonPath('data.preferences.push', false)->assertJsonPath('data.preferences.sms', true);
});

it('creates initial academic history when a profile is added to an existing user', function () {
    $student = User::factory()->create();
    $actor = User::factory()->create();
    app(\App\Actions\Students\UpsertStudentProfile::class)->execute([
        'name' => $student->name,
        'email' => $student->email,
        'enrollment_number' => 'INITIAL-001',
        'academic_status' => 'active',
    ], $student, $actor);
    $profile = StudentProfile::where('user_id', (string) $student->getKey())->firstOrFail();
    $history = AcademicStatusHistory::where('student_profile_id', (string) $profile->getKey())->first();
    expect($history)->not->toBeNull()->and($history->from_status)->toBeNull()->and($history->to_status)->toBe(StudentStatus::Active)->and((string) $history->changed_by)->toBe((string) $actor->getKey());
});

it('persists all communication preferences and rejects cross-student updates', function () {
    [$user] = studentWithStatus(); [, $other] = studentWithStatus();
    $this->actingAs($user)->patchJson('/student-services/preferences', ['email' => true, 'push' => false, 'sms' => true])->assertOk()->assertJsonPath('data.preferences.sms', true);
    $stored = CommunicationPreference::where('user_id', (string) $user->getKey())->first();
    expect($stored->email)->toBeTrue()->and($stored->push)->toBeFalse()->and($stored->sms)->toBeTrue();
    $this->actingAs($user)->patchJson("/student-services/students/{$other->getKey()}/preferences", ['email' => false])->assertForbidden();
});

it('allows a student to read only their own consent data', function () {
    [$user, $profile] = studentWithStatus();
    [, $other] = studentWithStatus();
    $this->actingAs($user)->getJson("/student-services/students/{$profile->getKey()}/consents")->assertOk()->assertJsonPath('data.student_id', (string) $profile->getKey());
    $this->actingAs($user)->getJson("/student-services/students/{$other->getKey()}/consents")->assertForbidden();
});

it('requires authentication and validates preference values', function () {
    [, $profile] = studentWithStatus();
    $this->patchJson('/student-services/preferences', ['email' => true])->assertUnauthorized();
    [$user] = studentWithStatus();
    $this->actingAs($user)->patchJson('/student-services/preferences', ['email' => 'not-a-boolean'])->assertUnprocessable();
    $this->actingAs($user)->patchJson('/student-services/preferences', ['push' => 123])->assertUnprocessable();
    $this->actingAs($user)->patchJson('/student-services/preferences', ['sms' => null])->assertUnprocessable();
});
