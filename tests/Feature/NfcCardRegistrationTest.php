<?php

use App\Models\CredentialEvent;
use App\Models\EventOutbox;
use App\Models\NfcCard;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->artisan('migrate')->assertSuccessful();

    $this->nfcAdmin = User::factory()->create();
    $this->nfcAdmin->assignRole(Role::ADMIN);
    $this->nfcStudent = User::factory()->create();
    StudentProfile::create([
        'user_id' => (string) $this->nfcStudent->getKey(),
        'enrollment_number' => 'NFC-STUDENT-1',
        'academic_status' => 'active',
    ]);
});

function registerNfc($test, string $uid, array $extra = [])
{
    return $test->actingAs($test->nfcAdmin)->post('/nfc-cards', array_merge([
        'user_id' => (string) $test->nfcStudent->getKey(),
        'uid' => $uid,
    ], $extra));
}

it('registers a canonical NFC card for a student with actor, initial history and outbox', function () {
    registerNfc($this, ' 04aabbcc ', ['registered_by' => 'spoofed'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('nfc-cards.index'));

    $card = NfcCard::where('uid', '04AABBCC')->firstOrFail();
    $history = CredentialEvent::where('nfc_card_id', (string) $card->getKey())->sole();
    $outbox = EventOutbox::where('aggregate_id', (string) $card->getKey())->sole();

    expect(NfcCard::count())->toBe(1)
        ->and((string) $card->user_id)->toBe((string) $this->nfcStudent->getKey())
        ->and((string) $card->registered_by)->toBe((string) $this->nfcAdmin->getKey())
        ->and($card->registered_at)->not->toBeNull()
        ->and($card->status)->toBe('active')
        ->and($history->event_type)->toBe('registered')
        ->and($history->previous_status)->toBeNull()
        ->and($history->new_status)->toBe('active')
        ->and($history->reason)->not->toBeEmpty()
        ->and((string) $history->performed_by)->toBe((string) $this->nfcAdmin->getKey())
        ->and($outbox->event_name)->toBe('identity.credential.changed.v1')
        ->and($outbox->payload['operation'])->toBe('registered')
        ->and($outbox->payload['actor_id'])->toBe((string) $this->nfcAdmin->getKey());
});

it('lists only users with a student profile on the registration page', function () {
    $nonStudent = User::factory()->create();

    $this->actingAs($this->nfcAdmin)->get('/nfc-cards/create')
        ->assertInertia(fn ($page) => $page
            ->component('NFC/Create')
            ->has('users', 1)
            ->where('users.0.id', (string) $this->nfcStudent->getKey()));

    expect(StudentProfile::where('user_id', (string) $nonStudent->getKey())->exists())->toBeFalse();
});

it('rejects a user without a student profile without creating persistent NFC effects', function () {
    $nonStudent = User::factory()->create();

    registerNfc($this, 'UID-NON-STUDENT', ['user_id' => (string) $nonStudent->getKey()])
        ->assertSessionHasErrors('user_id');

    expect(NfcCard::count())->toBe(0)
        ->and(CredentialEvent::count())->toBe(0)
        ->and(EventOutbox::count())->toBe(0);
});

it('does not allow a non-admin role to register a card through the HTTP route', function () {
    $teacher = User::factory()->create();
    $teacher->assignRole(Role::MAESTRO);

    $this->actingAs($teacher)->post('/nfc-cards', [
        'user_id' => (string) $this->nfcStudent->getKey(),
        'uid' => 'UID-TEACHER',
    ])->assertForbidden();

    expect(NfcCard::count())->toBe(0)
        ->and(CredentialEvent::count())->toBe(0)
        ->and(EventOutbox::count())->toBe(0);
});

it('rejects canonical duplicates before any additional persistent effect', function (string $secondUid) {
    registerNfc($this, '04AABBCC')->assertSessionHasNoErrors();
    registerNfc($this, $secondUid)->assertSessionHasErrors('uid');

    expect(NfcCard::count())->toBe(1)
        ->and(CredentialEvent::count())->toBe(1)
        ->and(EventOutbox::count())->toBe(1);
})->with(['04AABBCC', '04aabbcc', ' 04AABBCC ']);

it('rejects a logical duplicate of a legacy noncanonical UID', function () {
    NfcCard::create([
        'user_id' => (string) $this->nfcStudent->getKey(),
        'uid' => ' 04aabbcc ',
        'registered_by' => (string) $this->nfcAdmin->getKey(),
        'status' => 'active',
        'registered_at' => now(),
    ]);

    registerNfc($this, '04AABBCC')->assertSessionHasErrors('uid');

    expect(NfcCard::count())->toBe(1)
        ->and(CredentialEvent::count())->toBe(0)
        ->and(EventOutbox::count())->toBe(0);
});

it('creates the unique UID index through migrations in the test environment', function () {
    $indexes = iterator_to_array(DB::connection('mongodb')->getCollection('nfc_cards')->listIndexes());
    $byName = [];
    foreach ($indexes as $index) {
        $byName[$index->getName()] = $index;
    }

    expect($byName['uid_1']->isUnique())->toBeTrue();
});

it('rolls back the card when initial history persistence fails', function () {
    CredentialEvent::creating(function (): void {
        throw new RuntimeException('Deliberate NFC history failure');
    });

    try {
        $this->withoutExceptionHandling();
        $failed = false;
        try {
            registerNfc($this, 'UID-HISTORY-FAIL');
        } catch (RuntimeException $exception) {
            $failed = $exception->getMessage() === 'Deliberate NFC history failure';
        }

        expect($failed)->toBeTrue()
            ->and(NfcCard::count())->toBe(0)
            ->and(CredentialEvent::count())->toBe(0)
            ->and(EventOutbox::count())->toBe(0);
    } finally {
        CredentialEvent::flushEventListeners();
    }
});

it('rolls back the card and history when outbox persistence fails', function () {
    EventOutbox::creating(function (): void {
        throw new RuntimeException('Deliberate NFC outbox failure');
    });

    try {
        $this->withoutExceptionHandling();
        $failed = false;
        try {
            registerNfc($this, 'UID-OUTBOX-FAIL');
        } catch (RuntimeException $exception) {
            $failed = $exception->getMessage() === 'Deliberate NFC outbox failure';
        }

        expect($failed)->toBeTrue()
            ->and(NfcCard::count())->toBe(0)
            ->and(CredentialEvent::count())->toBe(0)
            ->and(EventOutbox::count())->toBe(0);
    } finally {
        EventOutbox::flushEventListeners();
    }
});
