<?php

use App\Actions\Students\UpsertStudentProfile;
use App\Enums\StudentStatus;
use App\Models\AcademicStatusHistory;
use App\Models\CommunicationPreference;
use App\Models\Consent;
use App\Models\EventOutbox;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\StudentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function studentWithStatus(string $status = 'active'): array
{
    $user = User::factory()->create();
    $profile = StudentProfile::create(['user_id' => (string) $user->getKey(), 'enrollment_number' => 'M'.str_replace('-', '', (string) $user->getKey()), 'academic_status' => $status, 'current_semester' => 3]);
    AcademicStatusHistory::create(['student_profile_id' => (string) $profile->getKey(), 'from_status' => null, 'to_status' => $status, 'reason' => 'Registro inicial', 'changed_at' => now()]);

    return [$user, $profile];
}

it('shares student profile availability on the dashboard for contextual navigation', function () {
    [$student] = studentWithStatus();

    $this->actingAs($student)->get('/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.hasStudentProfile', true));

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    withConfirmedTestTwoFactor($admin);

    $this->actingAs($admin)->get('/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.hasStudentProfile', false));
});

it('returns each persisted academic status instead of a hardcoded status', function (string $status) {
    [, $profile] = studentWithStatus($status);
    $service = app(StudentStatusService::class);
    expect($service->forUserId((string) $profile->user_id)['status'])->toBe($status);
})->with(['active', 'graduated', 'suspended', 'restricted']);

it('changes status with an actor, reason, and a persistent history entry', function () {
    [$manager] = studentWithStatus();
    $manager->assignRole('student_manager');
    withConfirmedTestTwoFactor($manager);
    expect($manager->fresh()->hasRole('student_manager'))->toBeTrue();
    [, $profile] = studentWithStatus();

    $this->actingAs($manager)->patchJson("/student-services/students/{$profile->getKey()}/status", ['status' => 'suspended', 'reason' => 'Expediente en revisión'])
        ->assertOk()->assertJsonPath('data.status', 'suspended');

    expect($profile->fresh()->academic_status)->toBe(StudentStatus::Suspended);
    $history = AcademicStatusHistory::where('student_profile_id', (string) $profile->getKey())->latest('changed_at')->first();
    expect($history->reason)->toBe('Expediente en revisión')->and((string) $history->changed_by)->toBe((string) $manager->getKey());
});

it('does not create duplicate academic history when status is unchanged', function () {
    [$manager] = studentWithStatus();
    $manager->assignRole('student_manager');
    withConfirmedTestTwoFactor($manager);
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
    app(UpsertStudentProfile::class)->execute([
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
    [$user] = studentWithStatus();
    [, $other] = studentWithStatus();
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

it('keeps feature terms independent from general consent and from each other with unchanged events', function () {
    [$user, $profile] = studentWithStatus();
    $this->actingAs($user)->postJson('/student-services/consents', ['consent_id' => 'terms', 'consent_version' => 'v1'])->assertCreated();
    $items = $this->getJson("/student-services/students/{$profile->getKey()}/consents")->assertOk()->json('data.items');
    expect(collect($items)->keyBy('id')['profile_terms']['status'])->toBe('pending')
        ->and(collect($items)->keyBy('id')['credential_terms']['status'])->toBe('pending');

    $profileTerm = $this->postJson('/student-services/consents', ['consent_id' => 'profile_terms', 'consent_version' => '2026.1'])->assertCreated()->assertJsonPath('data.id', 'profile_terms')->json('data');
    $items = $this->getJson("/student-services/students/{$profile->getKey()}/consents")->assertOk()->json('data.items');
    expect(collect($items)->keyBy('id')['credential_terms']['status'])->toBe('pending');
    $credentialTerm = $this->postJson('/student-services/consents', ['consent_id' => 'credential_terms', 'consent_version' => '2026.1'])->assertCreated()->json('data');
    $this->deleteJson('/student-services/consents/profile_terms', ['consent_record_id' => $profileTerm['acceptance_id']])->assertOk()->assertJsonPath('data.status', 'revoked');
    $items = collect($this->getJson("/student-services/students/{$profile->getKey()}/consents")->assertOk()->json('data.items'))->keyBy('id');
    expect($items['credential_terms']['status'])->toBe('accepted')
        ->and($items['credential_terms']['acceptance_id'])->toBe($credentialTerm['acceptance_id'])
        ->and($items['terms']['status'])->toBe('accepted')
        ->and(Consent::where('user_id', (string) $user->getKey())->count())->toBe(4);
    $events = EventOutbox::where('event_name', 'student.consent.changed.v1')->get();
    expect($events)->toHaveCount(4);
    $payload = $events->first(fn ($event) => $event->payload['consent_id'] === 'profile_terms' && $event->payload['status'] === 'revoked')->payload;
    expect(array_keys((array) $payload))->toBe(['student_id', 'consent_id', 'status', 'version', 'actor_id'])
        ->and($payload['student_id'])->toBe((string) $user->getKey())
        ->and($payload['actor_id'])->toBe((string) $user->getKey())
        ->and($payload['version'])->toBe('2026.1');
});

it('requires the current feature version without losing old acceptances or changing another feature', function () {
    [$user, $profile] = studentWithStatus();
    $this->actingAs($user)->postJson('/student-services/consents', ['consent_id' => 'profile_terms', 'consent_version' => '2026.1'])->assertCreated();
    $this->postJson('/student-services/consents', ['consent_id' => 'credential_terms', 'consent_version' => '2026.1'])->assertCreated();
    config()->set('student_services.consents.profile_terms.version', '2026.2');
    $items = collect($this->getJson("/student-services/students/{$profile->getKey()}/consents")->assertOk()->json('data.items'))->keyBy('id');
    expect($items['profile_terms']['version'])->toBe('2026.2')
        ->and($items['profile_terms']['status'])->toBe('pending')
        ->and($items['profile_terms']['acceptance_id'])->toBeNull()
        ->and($items['credential_terms']['status'])->toBe('accepted');
    $this->postJson('/student-services/consents', ['consent_id' => 'profile_terms', 'consent_version' => '2026.1'])->assertUnprocessable();
    $this->postJson('/student-services/consents', ['consent_id' => 'profile_terms'])->assertUnprocessable();
    $this->postJson('/student-services/consents', ['consent_id' => 'unknown_feature', 'consent_version' => '2026.2'])->assertUnprocessable();
    $new = $this->postJson('/student-services/consents', ['consent_id' => 'profile_terms', 'consent_version' => '2026.2'])->assertCreated()->json('data');
    $this->deleteJson('/student-services/consents/profile_terms', ['consent_record_id' => $new['acceptance_id']])->assertOk()->assertJsonPath('data.status', 'revoked');
    expect(Consent::where('user_id', (string) $user->getKey())->where('type', 'profile_terms')->where('version', '2026.1')->where('status', 'accepted')->count())->toBe(1)
        ->and(Consent::where('user_id', (string) $user->getKey())->count())->toBe(4);
    $this->getJson("/student-services/students/{$profile->getKey()}/consents")->assertOk()->assertJsonPath('data.items.3.status', 'revoked');
});

it('denies all cross-user consent operations even to a student manager and rejects cross-key revocation', function () {
    [$owner, $profile] = studentWithStatus();
    [$manager] = studentWithStatus();
    $manager->assignRole('student_manager');
    withConfirmedTestTwoFactor($manager);
    $accepted = $this->actingAs($owner)->postJson('/student-services/consents', ['consent_id' => 'profile_terms', 'consent_version' => '2026.1'])->assertCreated()->json('data');
    $this->deleteJson('/student-services/consents/credential_terms', ['consent_record_id' => $accepted['acceptance_id']])->assertUnprocessable();
    $this->actingAs($manager)->getJson("/api/v1/students/{$profile->getKey()}/consents")->assertForbidden();
    $this->postJson("/api/v1/students/{$owner->getKey()}/consents", ['consent_id' => 'credential_terms', 'consent_version' => '2026.1'])->assertForbidden();
    $this->deleteJson("/api/v1/students/{$profile->getKey()}/consents/profile_terms", ['consent_record_id' => $accepted['acceptance_id']])->assertForbidden();
    $this->deleteJson('/student-services/consents/profile_terms', ['consent_record_id' => $accepted['acceptance_id']])->assertUnprocessable();
    expect(Consent::where('user_id', (string) $owner->getKey())->count())->toBe(1)
        ->and(Consent::where('revokes_consent_id', $accepted['acceptance_id'])->exists())->toBeFalse();
});

it('shows separately named feature terms with their current versions in the existing consent UI', function () {
    [$user] = studentWithStatus();
    $this->actingAs($user)->get('/student-services')->assertOk()->assertInertia(fn ($page) => $page
        ->component('StudentServices/Index')
        ->where('consents.3.id', 'profile_terms')
        ->where('consents.3.name', 'Términos del perfil estudiantil')
        ->where('consents.3.version', '2026.1')
        ->where('consents.4.id', 'credential_terms')
        ->where('consents.4.name', 'Términos de credenciales QR y NFC')
        ->where('consents.4.status', 'pending'));
});
