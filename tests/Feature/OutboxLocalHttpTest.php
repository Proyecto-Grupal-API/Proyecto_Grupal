<?php

use App\Events\CredentialChanged;
use App\Events\StudentConsentChanged;
use App\Models\EventOutbox;
use App\Models\User;
use Symfony\Component\Process\Process;

function withLocalOutboxSink(string $mode, Closure $assertions): void
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException($errorMessage);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);

    $stateFile = tempnam(sys_get_temp_dir(), 'campus-outbox-sink-');
    $process = null;
    try {
        $process = new Process(
            [PHP_BINARY, '-S', $address, base_path('tests/Fixtures/outbox_sink.php')],
            base_path(),
            ['OUTBOX_TEST_SINK_STATE' => $stateFile, 'OUTBOX_TEST_SINK_MODE' => $mode],
        );
        $process->start();

        $ready = false;
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $connection = @stream_socket_client('tcp://'.$address, $errorCode, $errorMessage, 0.05);
            if ($connection !== false) {
                fclose($connection);
                $ready = true;
                break;
            }
            usleep(50000);
        }
        if (! $ready) {
            throw new RuntimeException('Local test sink did not start.');
        }

        config(['events.sink_url' => 'http://'.$address.'/events', 'events.sink_token' => null]);
        $assertions($stateFile);
    } finally {
        if ($process?->isRunning()) {
            $process->stop(1);
        }
        if (is_file($stateFile)) {
            unlink($stateFile);
        }
    }
}

it('publishes a testing event through real localhost HTTP and stores the acknowledgment', function () {
    withLocalOutboxSink('success', function (string $stateFile): void {
        StudentConsentChanged::dispatch('fixture-student', 'privacy', 'accepted', 'v1');
        $event = EventOutbox::sole();

        $this->artisan('events:publish')->assertSuccessful();

        $state = json_decode(file_get_contents($stateFile), true);
        expect($state['requests'])->toHaveCount(1)
            ->and($state['requests'][0]['event_id'])->toBe($event->event_id)
            ->and($state['requests'][0]['event_name'])->toBe('student.consent.changed.v1')
            ->and($state['requests'][0]['payload']['student_id'])->toBe('fixture-student')
            ->and($event->fresh()->published_at)->not->toBeNull();
    });
});

it('retries a lost acknowledgment with the same id while the local sink deduplicates', function () {
    withLocalOutboxSink('lost_ack', function (string $stateFile): void {
        StudentConsentChanged::dispatch('fixture-student', 'privacy', 'accepted', 'v1');
        $event = EventOutbox::sole();

        $this->artisan('events:publish')->assertFailed();
        expect($event->fresh()->published_at)->toBeNull()
            ->and($event->fresh()->attempts)->toBe(1)
            ->and($event->fresh()->last_error)->toBe('http_503');

        $this->travel(3)->seconds();
        $this->artisan('events:publish')->assertSuccessful();

        $state = json_decode(file_get_contents($stateFile), true);
        expect($state['requests'])->toHaveCount(2)
            ->and($state['requests'][0]['event_id'])->toBe($event->event_id)
            ->and($state['requests'][1]['event_id'])->toBe($event->event_id)
            ->and($state['processed'])->toHaveCount(1)
            ->and($event->fresh()->published_at)->not->toBeNull();
    });
});

it('delivers historical and enriched credential payloads unchanged through retries without relying on arrival order', function () {
    withLocalOutboxSink('lost_ack', function (string $stateFile): void {
        $student = User::factory()->create();
        $historicalPayload = ['credential_id' => 'historical-card', 'credential_type' => 'nfc',
            'operation' => 'registered', 'status' => 'active', 'actor_id' => null];
        $historical = EventOutbox::create(['event_id' => 'historical-credential-event',
            'event_name' => 'identity.credential.changed.v1', 'aggregate_id' => 'historical-card',
            'occurred_at' => now()->subDay(), 'payload' => $historicalPayload]);
        CredentialChanged::dispatch('current-card', 'nfc', 'registered', 'active', null,
            studentId: (string) $student->getKey());
        $expected = EventOutbox::all()->mapWithKeys(fn ($event) => [$event->event_id => (array) $event->payload])->all();

        $this->artisan('events:publish')->assertFailed();
        $this->travel(3)->seconds();
        $this->artisan('events:publish')->assertSuccessful();

        $state = json_decode(file_get_contents($stateFile), true);
        expect($state['requests'])->toHaveCount(4)->and($state['processed'])->toHaveCount(2);
        // Validate an unordered batch, including duplicates; never assert a transport ordering contract.
        $received = [];
        foreach (array_reverse($state['requests']) as $request) {
            expect($request['event_name'])->toBe('identity.credential.changed.v1')
                ->and($request['aggregate_id'])->toBe($request['payload']['credential_id'])
                ->and($request['payload'])->toBe($expected[$request['event_id']])
                ->and(array_keys($request))->toBe(['event_id', 'event_name', 'aggregate_id', 'occurred_at', 'payload'])
                ->and(array_keys($request['payload']))->not->toContain('uid', 'credential_uid', 'token', 'secret');
            if (isset($received[$request['event_id']])) {
                expect($request)->toBe($received[$request['event_id']]);
            }
            $received[$request['event_id']] = $request;
        }
        expect((array) $historical->fresh()->payload)->toBe($historicalPayload)
            ->and($received)->toHaveCount(2);
        $new = array_values(array_filter($received, fn ($request) => $request['aggregate_id'] === 'current-card'))[0];
        expect($new['payload']['student_id'])->toBe((string) $student->getKey())
            ->and($new['payload']['old_status'])->toBeNull()
            ->and($new['payload']['reason'])->toBeNull()
            ->and($new['payload']['actor_id'])->toBeNull()
            ->and($new['payload']['new_status'])->toBe($new['payload']['status'])
            ->and(EventOutbox::whereNull('published_at')->count())->toBe(0);
    });
});
