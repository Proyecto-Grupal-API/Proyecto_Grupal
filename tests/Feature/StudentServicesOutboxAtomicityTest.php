<?php

use App\Models\CommunicationPreference;
use App\Models\Consent;
use App\Models\EventOutbox;
use App\Models\StudentProfile;
use App\Models\User;

function studentForOutboxAtomicity(): User
{
    $user = User::factory()->create();
    StudentProfile::create([
        'user_id' => (string) $user->getKey(),
        'enrollment_number' => 'OUTBOX-'.(string) $user->getKey(),
        'academic_status' => 'active',
    ]);

    return $user;
}

it('commits consent acceptance and revocation with their outbox events', function () {
    $user = studentForOutboxAtomicity();
    $accepted = $this->actingAs($user)->postJson('/student-services/consents', ['consent_id' => 'terms', 'consent_version' => 'v1'])->assertCreated()->json('data');
    expect(EventOutbox::where('event_name', 'student.consent.changed.v1')->count())->toBe(1);

    $this->actingAs($user)->deleteJson('/student-services/consents/terms', ['consent_record_id' => $accepted['acceptance_id']])->assertOk();
    expect(EventOutbox::where('event_name', 'student.consent.changed.v1')->count())->toBe(2);
});

it('rolls back consent acceptance when outbox persistence fails', function () {
    $user = studentForOutboxAtomicity();
    EventOutbox::creating(fn () => throw new RuntimeException('fixture outbox failure'));
    try {
        $this->actingAs($user)->postJson('/student-services/consents', ['consent_id' => 'terms', 'consent_version' => 'v1'])->assertInternalServerError();
        expect(Consent::where('user_id', (string) $user->getKey())->count())->toBe(0)
            ->and(EventOutbox::count())->toBe(0);
    } finally {
        EventOutbox::flushEventListeners();
    }
});

it('rolls back consent revocation when outbox persistence fails', function () {
    $user = studentForOutboxAtomicity();
    $accepted = $this->actingAs($user)->postJson('/student-services/consents', ['consent_id' => 'terms', 'consent_version' => 'v1'])->assertCreated()->json('data');
    EventOutbox::creating(fn () => throw new RuntimeException('fixture outbox failure'));
    try {
        $this->actingAs($user)->deleteJson('/student-services/consents/terms', ['consent_record_id' => $accepted['acceptance_id']])->assertInternalServerError();
        expect(Consent::where('user_id', (string) $user->getKey())->count())->toBe(1)
            ->and(EventOutbox::count())->toBe(1);
    } finally {
        EventOutbox::flushEventListeners();
    }
});

it('commits a preference change with its outbox event', function () {
    $user = studentForOutboxAtomicity();
    $this->actingAs($user)->patchJson('/student-services/preferences', ['email' => true])->assertOk();
    expect(CommunicationPreference::where('user_id', (string) $user->getKey())->count())->toBe(1)
        ->and(EventOutbox::where('event_name', 'student.profile.changed.v1')->count())->toBe(1);
});

it('rolls back preference creation when outbox persistence fails', function () {
    $user = studentForOutboxAtomicity();
    EventOutbox::creating(fn () => throw new RuntimeException('fixture outbox failure'));
    try {
        $this->actingAs($user)->patchJson('/student-services/preferences', ['email' => true])->assertInternalServerError();
        expect(CommunicationPreference::where('user_id', (string) $user->getKey())->count())->toBe(0);
    } finally {
        EventOutbox::flushEventListeners();
    }

});

it('rolls back preference update when outbox persistence fails', function () {
    $user = studentForOutboxAtomicity();
    $this->actingAs($user)->patchJson('/student-services/preferences', ['email' => true])->assertOk();
    EventOutbox::creating(fn () => throw new RuntimeException('fixture outbox failure'));
    try {
        $this->actingAs($user)->patchJson('/student-services/preferences', ['email' => false])->assertInternalServerError();
        expect(CommunicationPreference::where('user_id', (string) $user->getKey())->first()->email)->toBeTrue();
    } finally {
        EventOutbox::flushEventListeners();
    }
});

it('rolls back feature consent changes if their existing outbox event cannot persist', function (string $operation) {
    $user = studentForOutboxAtomicity();
    $acceptance = null;
    if ($operation === 'revoke') {
        $acceptance = $this->actingAs($user)->postJson('/student-services/consents', ['consent_id' => 'credential_terms', 'consent_version' => '2026.1'])->assertCreated()->json('data.acceptance_id');
    }
    $before = Consent::count();
    $eventsBefore = EventOutbox::count();
    EventOutbox::creating(fn () => throw new RuntimeException('fixture outbox failure'));
    try {
        if ($operation === 'accept') {
            $this->actingAs($user)->postJson('/student-services/consents', ['consent_id' => 'credential_terms', 'consent_version' => '2026.1'])->assertInternalServerError();
        } else {
            $this->actingAs($user)->deleteJson('/student-services/consents/credential_terms', ['consent_record_id' => $acceptance])->assertInternalServerError();
            expect(Consent::where('revokes_consent_id', $acceptance)->exists())->toBeFalse();
        }
        expect(Consent::count())->toBe($before)->and(EventOutbox::count())->toBe($eventsBefore);
    } finally {
        EventOutbox::flushEventListeners();
    }
})->with(['accept', 'revoke']);
