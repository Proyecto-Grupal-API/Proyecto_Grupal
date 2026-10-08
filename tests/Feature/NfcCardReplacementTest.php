<?php

use App\Actions\Nfc\ReplaceNfcCard;
use App\Models\CredentialEvent;
use App\Models\EventOutbox;
use App\Models\NfcCard;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->artisan('migrate')->assertSuccessful();
    $this->replacementAdmin = User::factory()->create();
    $this->replacementAdmin->assignRole(Role::ADMIN);
    withConfirmedTestTwoFactor($this->replacementAdmin);
    $this->replacementStudent = User::factory()->create();
    StudentProfile::create([
        'user_id' => (string) $this->replacementStudent->getKey(),
        'enrollment_number' => 'REPLACE-STUDENT-1',
        'academic_status' => 'active',
    ]);
});

function replacementOld($test, string $status = 'active'): NfcCard
{
    return NfcCard::create([
        'user_id' => (string) $test->replacementStudent->getKey(),
        'uid' => 'OLD-UID',
        'registered_by' => (string) $test->replacementAdmin->getKey(),
        'status' => $status,
        'registered_at' => now()->subDay(),
        'blocked_at' => $status === 'blocked' ? now()->subDay() : null,
        'replaced_at' => $status === 'replaced' ? now()->subHour() : null,
    ]);
}

function replaceHttp($test, NfcCard $old, array $input = [])
{
    return $test->actingAs($test->replacementAdmin)->post(route('nfc-cards.replace', $old), array_merge([
        'uid' => '  aa bb cc  ',
        'reason' => 'Tarjeta física dañada',
    ], $input));
}

it('replaces each allowed state atomically while inheriting the operational state', function (string $status) {
    $old = replacementOld($this, $status);
    $oldBlockedAt = $old->blocked_at?->toDateTimeString();

    replaceHttp($this, $old, ['user_id' => 'spoofed', 'status' => 'active', 'registered_by' => 'spoofed'])
        ->assertRedirect(route('nfc-cards.index'))
        ->assertSessionHasNoErrors();

    $new = NfcCard::where('uid', 'AA BB CC')->sole();
    $old = $old->fresh();
    $oldEvent = CredentialEvent::where('nfc_card_id', (string) $old->getKey())->sole();
    $newEvent = CredentialEvent::where('nfc_card_id', (string) $new->getKey())->sole();
    $oldOutbox = EventOutbox::where('aggregate_id', (string) $old->getKey())->sole();
    $newOutbox = EventOutbox::where('aggregate_id', (string) $new->getKey())->sole();

    expect(NfcCard::count())->toBe(2)
        ->and($old->status)->toBe('replaced')
        ->and($old->replaced_at)->not->toBeNull()
        ->and($old->blocked_at)->toBeNull()
        ->and((string) $old->replaced_by_card_id)->toBe((string) $new->getKey())
        ->and((string) $new->replacement_of_card_id)->toBe((string) $old->getKey())
        ->and((string) $new->user_id)->toBe((string) $this->replacementStudent->getKey())
        ->and((string) $new->registered_by)->toBe((string) $this->replacementAdmin->getKey())
        ->and($new->status)->toBe($status)
        ->and($new->registered_at)->not->toBeNull()
        ->and($new->blocked_at === null)->toBe($status !== 'blocked')
        ->and($oldEvent->event_type)->toBe('replaced')
        ->and($oldEvent->previous_status)->toBe($status)
        ->and($oldEvent->new_status)->toBe('replaced')
        ->and($oldEvent->reason)->toBe('Tarjeta física dañada')
        ->and((string) $oldEvent->performed_by)->toBe((string) $this->replacementAdmin->getKey())
        ->and($newEvent->event_type)->toBe('registered')
        ->and($newEvent->previous_status)->toBeNull()
        ->and($newEvent->new_status)->toBe($status)
        ->and($newEvent->reason)->toBe('Tarjeta física dañada')
        ->and((string) $newEvent->performed_by)->toBe((string) $this->replacementAdmin->getKey())
        ->and($oldOutbox->event_name)->toBe('identity.credential.changed.v1')
        ->and($oldOutbox->payload['credential_id'])->toBe((string) $old->getKey())
        ->and($oldOutbox->payload['credential_type'])->toBe('nfc')
        ->and($oldOutbox->payload['operation'])->toBe('status_changed')
        ->and($oldOutbox->payload['status'])->toBe('replaced')
        ->and($oldOutbox->payload['actor_id'])->toBe((string) $this->replacementAdmin->getKey())
        ->and($newOutbox->payload['operation'])->toBe('registered')
        ->and($newOutbox->payload['credential_id'])->toBe((string) $new->getKey())
        ->and($newOutbox->payload['credential_type'])->toBe('nfc')
        ->and($newOutbox->payload['status'])->toBe($status)
        ->and($newOutbox->payload['actor_id'])->toBe((string) $this->replacementAdmin->getKey());

    if ($status === 'blocked') {
        expect($new->blocked_at->toDateTimeString())->not->toBe($oldBlockedAt);
    }
})->with(['active', 'blocked', 'suspended']);

it('rejects replaced, duplicate and own UID without persistent effects', function () {
    $old = replacementOld($this, 'active');
    NfcCard::create([
        'user_id' => (string) $this->replacementStudent->getKey(),
        'uid' => 'OTHER-UID',
        'registered_by' => (string) $this->replacementAdmin->getKey(),
        'status' => 'active',
        'registered_at' => now(),
    ]);

    replaceHttp($this, $old, ['uid' => ' old-uid '])->assertSessionHasErrors('uid');
    replaceHttp($this, $old, ['uid' => ' other-uid '])->assertSessionHasErrors('uid');
    replaceHttp($this, $old, ['reason' => '   '])->assertSessionHasErrors('reason');
    $old->update(['status' => 'replaced']);
    replaceHttp($this, $old)->assertSessionHasErrors('status');

    expect(NfcCard::count())->toBe(2)
        ->and(CredentialEvent::count())->toBe(0)
        ->and(EventOutbox::count())->toBe(0);
});

it('rejects missing cards, non-admins and an owner without StudentProfile', function () {
    $old = replacementOld($this);
    $regular = User::factory()->create();
    $this->actingAs($regular)->post(route('nfc-cards.replace', $old), [
        'uid' => 'NEW-UID', 'reason' => 'Intento',
    ])->assertForbidden();
    $this->actingAs($this->replacementAdmin)->post('/nfc-cards/000000000000000000000000/replace', [
        'uid' => 'NEW-UID', 'reason' => 'Intento',
    ])->assertNotFound();
    StudentProfile::where('user_id', (string) $this->replacementStudent->getKey())->delete();
    replaceHttp($this, $old)->assertSessionHasErrors('status');

    expect(NfcCard::count())->toBe(1)
        ->and($old->fresh()->status)->toBe('active')
        ->and(CredentialEvent::count())->toBe(0)
        ->and(EventOutbox::count())->toBe(0);
});

it('rejects a missing owner even if an orphaned StudentProfile remains', function (bool $keepProfile) {
    $old = replacementOld($this);
    $ownerId = (string) $this->replacementStudent->getKey();
    $this->replacementStudent->delete();

    if (! $keepProfile) {
        StudentProfile::where('user_id', $ownerId)->delete();
    }

    expect(User::whereKey($ownerId)->exists())->toBeFalse()
        ->and(StudentProfile::where('user_id', $ownerId)->exists())->toBe($keepProfile);

    replaceHttp($this, $old)
        ->assertSessionHasErrors(['status' => 'El propietario de la tarjeta ya no existe.']);

    $unchanged = $old->fresh();
    expect($unchanged->status)->toBe('active')
        ->and($unchanged->replaced_by_card_id)->toBeNull()
        ->and(NfcCard::count())->toBe(1)
        ->and(NfcCard::where('uid', 'AA BB CC')->exists())->toBeFalse()
        ->and(CredentialEvent::count())->toBe(0)
        ->and(EventOutbox::count())->toBe(0);
})->with([[true], [false]]);

it('rejects a stale state and rolls back the newly created card', function () {
    $old = replacementOld($this);
    $stale = NfcCard::findOrFail($old->getKey());
    $old->update(['status' => 'suspended']);

    expect(fn () => app(ReplaceNfcCard::class)->execute(
        $stale, 'NEW-UID', 'Motivo', (string) $this->replacementAdmin->getKey(),
    ))->toThrow(ValidationException::class);

    expect(NfcCard::count())->toBe(1)
        ->and($old->fresh()->status)->toBe('suspended')
        ->and(CredentialEvent::count())->toBe(0)
        ->and(EventOutbox::count())->toBe(0);
});

it('rolls back when creating the new card fails', function () {
    $old = replacementOld($this);
    NfcCard::creating(function (): void {
        throw new RuntimeException('new card failure');
    });
    try {
        expect(fn () => app(ReplaceNfcCard::class)->execute(
            $old, 'NEW-UID', 'Motivo', (string) $this->replacementAdmin->getKey(),
        ))->toThrow(RuntimeException::class);
        expect(NfcCard::count())->toBe(1)
            ->and($old->fresh()->status)->toBe('active')
            ->and(CredentialEvent::count())->toBe(0)
            ->and(EventOutbox::count())->toBe(0);
    } finally {
        NfcCard::flushEventListeners();
    }
});

it('rolls back both cards when history or outbox fails', function (string $model) {
    $old = replacementOld($this);
    $model::creating(function (): void {
        throw new RuntimeException('replacement failure');
    });
    try {
        expect(fn () => app(ReplaceNfcCard::class)->execute(
            $old, 'NEW-UID', 'Motivo', (string) $this->replacementAdmin->getKey(),
        ))->toThrow(RuntimeException::class);
        expect(NfcCard::count())->toBe(1)
            ->and($old->fresh()->status)->toBe('active')
            ->and($old->fresh()->replaced_by_card_id)->toBeNull()
            ->and(CredentialEvent::count())->toBe(0)
            ->and(EventOutbox::count())->toBe(0);
    } finally {
        $model::flushEventListeners();
    }
})->with([[CredentialEvent::class], [EventOutbox::class]]);

it('rolls back the first history and outbox if the second write fails', function (string $model) {
    $old = replacementOld($this);
    $writes = 0;
    $model::creating(function () use (&$writes): void {
        if (++$writes === 2) {
            throw new RuntimeException('second replacement write failure');
        }
    });

    try {
        expect(fn () => app(ReplaceNfcCard::class)->execute(
            $old, 'NEW-UID', 'Motivo', (string) $this->replacementAdmin->getKey(),
        ))->toThrow(RuntimeException::class);
        expect($writes)->toBe(2)
            ->and(NfcCard::count())->toBe(1)
            ->and($old->fresh()->status)->toBe('active')
            ->and(CredentialEvent::count())->toBe(0)
            ->and(EventOutbox::count())->toBe(0);
    } finally {
        $model::flushEventListeners();
    }
})->with([[CredentialEvent::class], [EventOutbox::class]]);

it('creates both replacement indexes with a unique partial predecessor claim', function () {
    $indexes = iterator_to_array(DB::connection('mongodb')->getCollection('nfc_cards')->listIndexes());
    $byName = [];
    foreach ($indexes as $index) {
        $byName[$index->getName()] = $index;
    }

    expect($byName)->toHaveKeys(['uid_1', 'nfc_replacement_of_unique', 'nfc_replaced_by_lookup'])
        ->and($byName['nfc_replacement_of_unique']->isUnique())->toBeTrue();
});

it('rejects a competing successor at the MongoDB unique index', function () {
    $old = replacementOld($this);
    $details = [
        'user_id' => (string) $this->replacementStudent->getKey(),
        'registered_by' => (string) $this->replacementAdmin->getKey(),
        'status' => 'active',
        'registered_at' => now(),
        'replacement_of_card_id' => (string) $old->getKey(),
    ];

    NfcCard::create($details + ['uid' => 'SUCCESSOR-1']);
    $rejected = false;
    try {
        NfcCard::create($details + ['uid' => 'SUCCESSOR-2']);
    } catch (Throwable $failure) {
        $rejected = (int) $failure->getCode() === 11000;
    }

    expect($rejected)->toBeTrue()
        ->and(NfcCard::count())->toBe(2);
});

it('does not allow a second replacement of the same predecessor', function () {
    $old = replacementOld($this);
    replaceHttp($this, $old)->assertSessionHasNoErrors();
    replaceHttp($this, $old, ['uid' => 'SECOND-UID'])->assertSessionHasErrors('status');

    expect(NfcCard::count())->toBe(2)
        ->and(CredentialEvent::count())->toBe(2)
        ->and(EventOutbox::count())->toBe(2);
});
