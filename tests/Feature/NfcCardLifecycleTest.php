<?php

use App\Actions\Nfc\ChangeNfcCardStatus;
use App\Enums\NfcCardStatus;
use App\Models\CredentialEvent;
use App\Models\EventOutbox;
use App\Models\NfcCard;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->artisan('migrate')->assertSuccessful();
    $this->nfcLifecycleAdmin = User::factory()->create();
    $this->nfcLifecycleAdmin->assignRole(Role::ADMIN);
    $this->nfcLifecycleOwner = User::factory()->create();
});

function lifecycleCard($test, string $status): NfcCard
{
    return NfcCard::create([
        'user_id' => (string) $test->nfcLifecycleOwner->getKey(),
        'uid' => 'LIFECYCLE-UID',
        'registered_by' => (string) $test->nfcLifecycleAdmin->getKey(),
        'registered_at' => now()->subDay(),
        'status' => $status,
        'blocked_at' => $status === 'blocked' ? now()->subHour() : null,
        'replaced_at' => $status === 'replaced' ? now()->subHour() : null,
    ]);
}

function changeLifecycle($test, NfcCard $card, string $status, string $reason = 'Motivo verificado')
{
    return $test->actingAs($test->nfcLifecycleAdmin)
        ->patch(route('nfc-cards.update-status', $card), ['status' => $status, 'reason' => $reason]);
}

it('applies each permitted ordinary transition with history and outbox', function (string $from, string $to, string $eventType) {
    $card = lifecycleCard($this, $from);
    $registeredAt = $card->registered_at->toDateTimeString();

    changeLifecycle($this, $card, $to)->assertSessionHasNoErrors()
        ->assertRedirect(route('nfc-cards.index'));

    $fresh = $card->fresh();
    $event = CredentialEvent::sole();
    $outbox = EventOutbox::sole();

    expect($fresh->status)->toBe($to)
        ->and($fresh->registered_at->toDateTimeString())->toBe($registeredAt)
        ->and($fresh->blocked_at === null)->toBe($to !== 'blocked')
        ->and($fresh->replaced_at)->toBeNull()
        ->and($event->event_type)->toBe($eventType)
        ->and($event->previous_status)->toBe($from)
        ->and($event->new_status)->toBe($to)
        ->and($event->reason)->toBe('Motivo verificado')
        ->and((string) $event->performed_by)->toBe((string) $this->nfcLifecycleAdmin->getKey())
        ->and($event->created_at)->not->toBeNull()
        ->and($outbox->event_name)->toBe('identity.credential.changed.v1')
        ->and($outbox->payload['operation'])->toBe('status_changed')
        ->and($outbox->payload['status'])->toBe($to)
        ->and($outbox->payload['actor_id'])->toBe((string) $this->nfcLifecycleAdmin->getKey());
})->with([
    ['active', 'blocked', 'blocked'],
    ['active', 'suspended', 'suspended'],
    ['blocked', 'active', 'reactivated'],
    ['blocked', 'suspended', 'suspended'],
    ['suspended', 'active', 'reactivated'],
    ['suspended', 'blocked', 'blocked'],
]);

it('rejects invalid ordinary transitions without state, timestamp, history or outbox changes', function (string $from, string $to) {
    $card = lifecycleCard($this, $from);
    $before = $card->toArray();

    changeLifecycle($this, $card, $to)->assertSessionHasErrors('status');

    $after = $card->fresh();
    expect($after->status)->toBe($before['status'])
        ->and($after->blocked_at?->toDateTimeString())->toBe($card->blocked_at?->toDateTimeString())
        ->and($after->replaced_at?->toDateTimeString())->toBe($card->replaced_at?->toDateTimeString())
        ->and(CredentialEvent::count())->toBe(0)
        ->and(EventOutbox::count())->toBe(0);
})->with([
    ['active', 'active'],
    ['blocked', 'blocked'],
    ['suspended', 'suspended'],
    ['replaced', 'active'],
    ['replaced', 'blocked'],
    ['replaced', 'suspended'],
    ['replaced', 'replaced'],
    ['active', 'replaced'],
    ['blocked', 'replaced'],
    ['suspended', 'replaced'],
]);

it('records loss structurally as blocked with reason, actor and outbox', function (string $from) {
    $card = lifecycleCard($this, $from);

    $this->actingAs($this->nfcLifecycleAdmin)
        ->patch(route('nfc-cards.report-lost', $card), ['reason' => '  Tarjeta extraviada  '])
        ->assertSessionHasNoErrors();

    $event = CredentialEvent::sole();
    $outbox = EventOutbox::sole();
    expect($card->fresh()->status)->toBe('blocked')
        ->and($card->fresh()->blocked_at)->not->toBeNull()
        ->and($event->event_type)->toBe('lost')
        ->and($event->reason)->toBe('Tarjeta extraviada')
        ->and($event->previous_status)->toBe($from)
        ->and($event->new_status)->toBe('blocked')
        ->and((string) $event->performed_by)->toBe((string) $this->nfcLifecycleAdmin->getKey())
        ->and($outbox->payload['operation'])->toBe('lost')
        ->and($outbox->payload['status'])->toBe('blocked');
})->with(['active', 'suspended']);

it('rejects loss for a blocked or replaced card without persistent effects', function (string $status) {
    $card = lifecycleCard($this, $status);
    $replacedAt = $card->replaced_at?->toDateTimeString();
    $blockedAt = $card->blocked_at?->toDateTimeString();

    $this->actingAs($this->nfcLifecycleAdmin)
        ->patch(route('nfc-cards.report-lost', $card), ['reason' => 'Perdida'])
        ->assertSessionHasErrors('status');

    expect($card->fresh()->status)->toBe($status)
        ->and($card->fresh()->replaced_at?->toDateTimeString())->toBe($replacedAt)
        ->and($card->fresh()->blocked_at?->toDateTimeString())->toBe($blockedAt)
        ->and(CredentialEvent::count())->toBe(0)
        ->and(EventOutbox::count())->toBe(0);
})->with(['blocked', 'replaced']);

it('rejects a loss report with a whitespace-only reason', function () {
    $card = lifecycleCard($this, 'active');

    $this->actingAs($this->nfcLifecycleAdmin)
        ->patch(route('nfc-cards.report-lost', $card), ['reason' => '  '])
        ->assertSessionHasErrors('reason');

    expect($card->fresh()->status)->toBe('active')
        ->and(CredentialEvent::count())->toBe(0)
        ->and(EventOutbox::count())->toBe(0);
});

it('rejects an ordinary change with a whitespace-only reason', function () {
    $card = lifecycleCard($this, 'active');

    changeLifecycle($this, $card, 'blocked', '  ')->assertSessionHasErrors('reason');

    expect($card->fresh()->status)->toBe('active')
        ->and(CredentialEvent::count())->toBe(0)
        ->and(EventOutbox::count())->toBe(0);
});

it('denies non-admin HTTP lifecycle changes and loss reports', function () {
    $card = lifecycleCard($this, 'active');
    $regular = User::factory()->create();

    $this->actingAs($regular)->patch(route('nfc-cards.update-status', $card), [
        'status' => 'blocked', 'reason' => 'Intento',
    ])->assertForbidden();
    $this->actingAs($this->nfcLifecycleOwner)->patch(route('nfc-cards.report-lost', $card), [
        'reason' => 'Intento',
    ])->assertForbidden();

    expect($card->fresh()->status)->toBe('active')
        ->and(CredentialEvent::count())->toBe(0)
        ->and(EventOutbox::count())->toBe(0);
});

it('exposes lifecycle controls only to admins through the index props', function () {
    $card = lifecycleCard($this, 'active');

    $this->actingAs($this->nfcLifecycleOwner)->get(route('nfc-cards.index'))
        ->assertInertia(fn ($page) => $page->where('canManage', false));

    $this->actingAs($this->nfcLifecycleAdmin)->get(route('nfc-cards.index'))
        ->assertInertia(fn ($page) => $page
            ->where('canManage', true)
            ->where('availableTransitions.'.(string) $card->getKey(), ['blocked', 'suspended']));
});

it('rolls back an ordinary change when history persistence fails', function () {
    $card = lifecycleCard($this, 'active');
    CredentialEvent::creating(function (): void {
        throw new RuntimeException('Deliberate lifecycle history failure');
    });

    try {
        $this->withoutExceptionHandling();
        $failed = false;
        try {
            changeLifecycle($this, $card, 'blocked');
        } catch (RuntimeException $exception) {
            $failed = $exception->getMessage() === 'Deliberate lifecycle history failure';
        }

        expect($failed)->toBeTrue()
            ->and($card->fresh()->status)->toBe('active')
            ->and($card->fresh()->blocked_at)->toBeNull()
            ->and(CredentialEvent::count())->toBe(0)
            ->and(EventOutbox::count())->toBe(0);
    } finally {
        CredentialEvent::flushEventListeners();
    }
});

it('rolls back an ordinary change and history when outbox persistence fails', function () {
    $card = lifecycleCard($this, 'active');
    EventOutbox::creating(function (): void {
        throw new RuntimeException('Deliberate lifecycle outbox failure');
    });

    try {
        $this->withoutExceptionHandling();
        $failed = false;
        try {
            changeLifecycle($this, $card, 'suspended');
        } catch (RuntimeException $exception) {
            $failed = $exception->getMessage() === 'Deliberate lifecycle outbox failure';
        }

        expect($failed)->toBeTrue()
            ->and($card->fresh()->status)->toBe('active')
            ->and(CredentialEvent::count())->toBe(0)
            ->and(EventOutbox::count())->toBe(0);
    } finally {
        EventOutbox::flushEventListeners();
    }
});

it('rolls back a loss report when the outbox fails', function () {
    $card = lifecycleCard($this, 'active');
    EventOutbox::creating(function (): void {
        throw new RuntimeException('Deliberate loss outbox failure');
    });

    try {
        $this->withoutExceptionHandling();
        $failed = false;
        try {
            $this->actingAs($this->nfcLifecycleAdmin)
                ->patch(route('nfc-cards.report-lost', $card), ['reason' => 'Extraviada']);
        } catch (RuntimeException $exception) {
            $failed = $exception->getMessage() === 'Deliberate loss outbox failure';
        }

        expect($failed)->toBeTrue()
            ->and($card->fresh()->status)->toBe('active')
            ->and($card->fresh()->blocked_at)->toBeNull()
            ->and(CredentialEvent::count())->toBe(0)
            ->and(EventOutbox::count())->toBe(0);
    } finally {
        EventOutbox::flushEventListeners();
    }
});

it('rolls back a loss report when history persistence fails', function () {
    $card = lifecycleCard($this, 'suspended');
    CredentialEvent::creating(function (): void {
        throw new RuntimeException('Deliberate loss history failure');
    });

    try {
        $this->withoutExceptionHandling();
        $failed = false;
        try {
            $this->actingAs($this->nfcLifecycleAdmin)
                ->patch(route('nfc-cards.report-lost', $card), ['reason' => 'Extraviada']);
        } catch (RuntimeException $exception) {
            $failed = $exception->getMessage() === 'Deliberate loss history failure';
        }

        expect($failed)->toBeTrue()
            ->and($card->fresh()->status)->toBe('suspended')
            ->and($card->fresh()->blocked_at)->toBeNull()
            ->and(CredentialEvent::count())->toBe(0)
            ->and(EventOutbox::count())->toBe(0);
    } finally {
        CredentialEvent::flushEventListeners();
    }
});

it('rejects a stale state without recording a misleading event', function () {
    $card = lifecycleCard($this, 'active');
    $stale = NfcCard::findOrFail($card->getKey());
    $card->update(['status' => 'suspended']);

    $conflict = false;
    try {
        app(ChangeNfcCardStatus::class)->execute(
            $stale, NfcCardStatus::Blocked, 'Motivo', (string) $this->nfcLifecycleAdmin->getKey(),
        );
    } catch (ValidationException $exception) {
        $conflict = $exception->errors()['status'][0] === 'El estado de la tarjeta cambió; vuelve a cargarla.';
    }

    expect($conflict)->toBeTrue()
        ->and($card->fresh()->status)->toBe('suspended')
        ->and(CredentialEvent::count())->toBe(0)
        ->and(EventOutbox::count())->toBe(0);
});
