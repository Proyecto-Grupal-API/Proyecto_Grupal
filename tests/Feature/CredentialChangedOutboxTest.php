<?php

use App\Events\CredentialChanged;
use App\Models\EventOutbox;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('keeps the original constructor and payload meanings without fabricating missing context', function () {
    $event = new CredentialChanged('card-fixture', 'nfc', 'registered', 'active');

    expect($event->eventName())->toBe('identity.credential.changed.v1')
        ->and($event->aggregateId())->toBe('card-fixture')
        ->and($event->payload())->toBe([
            'credential_id' => 'card-fixture',
            'credential_type' => 'nfc',
            'operation' => 'registered',
            'status' => 'active',
            'actor_id' => null,
            'student_id' => null,
            'old_status' => null,
            'new_status' => 'active',
            'reason' => null,
        ]);
});

it('stores the same logical event once and delivers its captured context on retry without owner lookups', function () {
    $owner = User::factory()->create();
    $studentId = (string) $owner->getKey();
    $event = new CredentialChanged('card-fixture', 'nfc', 'status_changed', 'blocked', null,
        studentId: $studentId, oldStatus: 'suspended', reason: 'Motivo registrado',
    );
    event($event);
    $stored = EventOutbox::sole();
    $occurredAt = $stored->occurred_at->toISOString();
    event($event);
    $owner->forceDelete();

    expect(EventOutbox::count())->toBe(1);
    $requests = [];
    config(['events.sink_url' => 'https://events.test/events']);
    Http::fake(function ($request) use (&$requests) {
        $requests[] = $request->data();

        return Http::response([], count($requests) === 1 ? 503 : 202);
    });
    $this->artisan('events:publish')->assertFailed();
    $this->travel(3)->seconds();
    $this->artisan('events:publish')->assertSuccessful();

    expect($requests)->toHaveCount(2)
        ->and($requests[0])->toBe($requests[1])
        ->and(array_keys($requests[0]))->toBe(['event_id', 'event_name', 'aggregate_id', 'occurred_at', 'payload'])
        ->and($requests[0]['event_id'])->toBe($event->eventId())
        ->and($requests[0]['aggregate_id'])->toBe('card-fixture')
        ->and($requests[0]['event_name'])->toBe('identity.credential.changed.v1')
        ->and($requests[0]['occurred_at'])->toBe($occurredAt)
        ->and($requests[0]['payload'])->toBe($event->payload())
        ->and($requests[0]['payload']['student_id'])->toBe($studentId)
        ->and($requests[0]['payload']['actor_id'])->toBeNull()
        ->and(EventOutbox::count())->toBe(1)
        ->and($stored->fresh()->published_at)->not->toBeNull();
});

it('does not enrich or rewrite an old stored v1 payload when publishing alongside new events', function () {
    $payload = ['credential_id' => 'old-card', 'credential_type' => 'nfc', 'operation' => 'registered', 'status' => 'active', 'actor_id' => null];
    $old = EventOutbox::create([
        'event_id' => 'old-event-fixture', 'event_name' => 'identity.credential.changed.v1',
        'aggregate_id' => 'old-card', 'occurred_at' => now()->subDay(), 'payload' => $payload,
    ]);
    CredentialChanged::dispatch('new-card', 'nfc', 'registered', 'active', null, studentId: 'student-fixture');
    config(['events.sink_url' => 'https://events.test/events']);
    Http::fake(['*' => Http::response([], 200)]);

    $this->artisan('events:publish')->assertSuccessful();

    expect((array) $old->fresh()->payload)->toBe($payload)
        ->and(EventOutbox::count())->toBe(2);
    Http::assertSent(fn ($request) => $request['event_id'] === 'old-event-fixture' && $request['payload'] === $payload);
});

it('rolls back an already stored credential event with its surrounding transaction', function () {
    expect(function () {
        DB::connection('mongodb')->transaction(function () {
            CredentialChanged::dispatch('rolled-back-card', 'nfc', 'registered', 'active', null, studentId: 'student-fixture');
            expect(EventOutbox::count())->toBe(1);
            throw new RuntimeException('rollback fixture');
        });
    })->toThrow(RuntimeException::class, 'rollback fixture');

    expect(EventOutbox::count())->toBe(0);
});
