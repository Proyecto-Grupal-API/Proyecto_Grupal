<?php

use App\Events\StudentConsentChanged;
use App\Models\EventOutbox;
use App\Services\OutboxDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function pendingOutboxEvent(): EventOutbox
{
    StudentConsentChanged::dispatch('student-fixture', 'privacy', 'accepted', 'v1');

    return EventOutbox::sole();
}

beforeEach(function () {
    config(['events.sink_url' => 'https://events.test/v1/events', 'events.claim_lease_seconds' => 60]);
});

it('keeps the v1 envelope and event id stable after a successful 2xx acknowledgment', function () {
    $event = pendingOutboxEvent();
    $eventId = $event->event_id;
    Http::fake(['https://events.test/*' => Http::response([], 204)]);

    $this->artisan('events:publish')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request['event_id'] === $eventId
        && $request['event_name'] === 'student.consent.changed.v1'
        && $request['occurred_at'] === $event->occurred_at->toISOString()
        && $request['payload']['student_id'] === 'student-fixture');
    expect($event->fresh()->event_id)->toBe($eventId)
        ->and($event->fresh()->published_at)->not->toBeNull()
        ->and($event->fresh()->last_error)->toBeNull()
        ->and($event->fresh()->claim_token)->toBeNull()
        ->and($event->fresh()->attempts)->toBeNull();
});

it('classifies non-2xx failures without persisting a response body or secret', function (int $status, int $delay) {
    $event = pendingOutboxEvent();
    config(['events.sink_token' => 'test-secret-not-for-storage']);
    Http::fake(['https://events.test/*' => Http::response(['secret' => 'response-secret'], $status)]);

    $this->artisan('events:publish')->assertFailed();

    $fresh = $event->fresh();
    expect($fresh->published_at)->toBeNull()
        ->and($fresh->attempts)->toBe(1)
        ->and($fresh->last_error)->toBe('http_'.$status)
        ->and($fresh->claim_token)->toBeNull()
        ->and($fresh->next_attempt_at->getTimestamp() - now()->getTimestamp())->toBeGreaterThanOrEqual($delay - 1)
        ->and($fresh->last_error)->not->toContain('secret');
})->with([
    [400, 300], [401, 300], [403, 300], [409, 300], [422, 300],
    [429, 2], [500, 2],
]);

it('retries a failure later with exactly the same event id', function () {
    $event = pendingOutboxEvent();
    $ids = [];
    Http::fake(function ($request) use (&$ids) {
        $ids[] = $request['event_id'];
        return Http::response([], count($ids) === 1 ? 503 : 202);
    });

    $this->artisan('events:publish')->assertFailed();
    expect($event->fresh()->published_at)->toBeNull()->and($event->fresh()->attempts)->toBe(1);
    $this->artisan('events:publish')->assertSuccessful();
    expect($ids)->toHaveCount(1);

    $this->travel(3)->seconds();
    $this->artisan('events:publish')->assertSuccessful();
    expect($ids)->toBe([$event->event_id, $event->event_id])
        ->and($event->fresh()->published_at)->not->toBeNull();
});

it('sanitizes network failures and retries them after backoff', function () {
    $event = pendingOutboxEvent();
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Bearer fixture-secret'));

    $this->artisan('events:publish')->assertFailed();

    expect($event->fresh()->published_at)->toBeNull()
        ->and($event->fresh()->attempts)->toBe(1)
        ->and($event->fresh()->last_error)->toBe('connection_error')
        ->and($event->fresh()->next_attempt_at)->not->toBeNull();
});

it('refuses to publish without a sink or with an unsafe lease configuration', function () {
    $event = pendingOutboxEvent();
    config(['events.sink_url' => null]);
    $this->artisan('events:publish')->assertFailed();
    expect($event->fresh()->claim_token)->toBeNull();

    config(['events.sink_url' => 'https://events.test/v1/events', 'events.claim_lease_seconds' => 5, 'events.timeout' => 10]);
    $this->artisan('events:publish')->assertFailed();
    expect($event->fresh()->claim_token)->toBeNull();
});

it('recovers an expired claim but never lets an old owner acknowledge it', function () {
    $event = pendingOutboxEvent();
    $delivery = app(OutboxDelivery::class);
    $candidate = iterator_to_array($delivery->candidates(1))[0];
    $first = $delivery->claim($candidate, 60);

    expect($first)->not->toBeNull()
        ->and($delivery->claim($candidate, 60))->toBeNull()
        ->and(iterator_count($delivery->candidates(1)))->toBe(0);

    $this->travel(61)->seconds();
    $second = $delivery->claim($candidate, 60);
    expect($second)->not->toBeNull()
        ->and($second['token'])->not->toBe($first['token'])
        ->and($delivery->acknowledge($first['event'], $first['token']))->toBeFalse()
        ->and($delivery->acknowledge($second['event'], $second['token']))->toBeTrue()
        ->and($event->fresh()->published_at)->not->toBeNull();
});

it('enforces event id uniqueness through the versioned MongoDB index migration', function () {
    $migration = require database_path('migrations/2026_09_27_000100_add_event_outbox_delivery_indexes.php');
    $migration->up();
    $event = pendingOutboxEvent();
    $collection = DB::connection('mongodb')->getCollection((new EventOutbox)->getTable());

    expect(collect(iterator_to_array($collection->listIndexes()))->pluck('name')->all())
        ->toContain('outbox_event_id_unique');

    $duplicate = $event->replicate();
    expect(fn () => $duplicate->save())->toThrow(\MongoDB\Driver\Exception\BulkWriteException::class);
    expect(EventOutbox::count())->toBe(1);
});

it('registers the publisher on the Laravel minute scheduler', function () {
    \Illuminate\Support\Facades\Artisan::call('schedule:list');
    expect(\Illuminate\Support\Facades\Artisan::output())->toContain('events:publish');
});
