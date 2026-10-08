<?php

namespace App\Console\Commands;

use App\Services\OutboxDelivery;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class PublishDomainEvents extends Command
{
    protected $signature = 'events:publish {--limit= : Maximum number of events to attempt}';

    protected $description = 'Publish pending domain events to the configured service sink';

    public function handle(OutboxDelivery $delivery): int
    {
        $sink = config('events.sink_url');
        if (! $sink) {
            $this->error('EVENTS_SINK_URL is not configured.');

            return self::FAILURE;
        }

        $limit = (int) ($this->option('limit') ?: config('events.batch_size'));
        $timeout = (int) config('events.timeout');
        $lease = (int) config('events.claim_lease_seconds');
        if ($limit < 1 || $timeout < 1 || $lease <= $timeout) {
            $this->error('Invalid outbox batch, timeout, or claim lease configuration.');

            return self::FAILURE;
        }

        $published = 0;
        $failed = 0;
        $inspected = 0;

        foreach ($delivery->candidates($limit) as $candidate) {
            $inspected++;
            $claim = $delivery->claim($candidate, $lease);
            if (! $claim) {
                continue;
            }
            ['event' => $event, 'token' => $claimToken] = $claim;
            try {
                $request = Http::timeout($timeout)
                    ->acceptJson()
                    ->asJson()
                    ->withoutRedirecting()
                    ->withOptions(['verify' => true]);
                if ($token = config('events.sink_token')) {
                    $request = $request->withToken($token);
                }

                $response = $request->post($sink, [
                    'event_id' => $event->event_id,
                    'event_name' => $event->event_name,
                    'aggregate_id' => $event->aggregate_id,
                    'occurred_at' => $event->occurred_at?->toDateTime()->format('Y-m-d\TH:i:s.u\Z'),
                    'payload' => $event->payload?->getArrayCopy() ?? [],
                ]);

                if ($response->successful()) {
                    if ($delivery->acknowledge($event, $claimToken)) {
                        $published++;
                    } else {
                        $failed++;
                        $this->warn("Event {$event->event_id} could not be acknowledged by this claim.");
                    }

                    continue;
                }

                $status = $response->status();
                $retryable = $status === 429 || $status >= 500;
                $delivery->fail($event, $claimToken, 'http_'.$status, $retryable);
                $failed++;
                $this->warn("Event {$event->event_id} failed: HTTP {$status}.");
            } catch (ConnectionException $exception) {
                $delivery->fail($event, $claimToken, 'connection_error', true);
                $failed++;
                $this->warn("Event {$event->event_id} failed: connection_error.");
            } catch (Throwable $exception) {
                $delivery->fail($event, $claimToken, 'transport_error', true);
                $failed++;
                $this->warn("Event {$event->event_id} failed: transport_error.");
            }
        }

        $this->info("Published: {$published}; failed: {$failed}; inspected: {$inspected}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
