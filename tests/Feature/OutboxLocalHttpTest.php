<?php

use App\Events\StudentConsentChanged;
use App\Models\EventOutbox;
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
