<?php

namespace App\Console\Commands;

use App\Models\EventOutbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class PublishDomainEvents extends Command
{
    protected $signature = 'events:publish {--limit= : Maximum number of events to attempt}';
    protected $description = 'Publish pending domain events to the configured service sink';

    public function handle(): int
    {
        $sink = config('events.sink_url');
        if (! $sink) {
            $this->error('EVENTS_SINK_URL is not configured.');
            return self::FAILURE;
        }

        $limit = (int) ($this->option('limit') ?: config('events.batch_size'));
        $events = EventOutbox::whereNull('published_at')->orderBy('occurred_at')->limit($limit)->get();
        $published = 0;
        $failed = 0;

        foreach ($events as $event) {
            try {
                $request = Http::timeout(config('events.timeout'))
                    ->acceptJson()
                    ->asJson();
                if ($token = config('events.sink_token')) {
                    $request = $request->withToken($token);
                }

                $response = $request->post($sink, [
                    'event_id' => $event->event_id,
                    'event_name' => $event->event_name,
                    'aggregate_id' => $event->aggregate_id,
                    'occurred_at' => $event->occurred_at?->toISOString(),
                    'payload' => $event->payload?->getArrayCopy() ?? [],
                ]);

                if ($response->successful()) {
                    $event->forceFill(['published_at' => now(), 'last_error' => null])->save();
                    $published++;
                    continue;
                }

                throw new \RuntimeException('Sink returned HTTP '.$response->status());
            } catch (Throwable $exception) {
                $event->forceFill([
                    'attempts' => ((int) ($event->attempts ?? 0)) + 1,
                    'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                ])->save();
                $failed++;
                $this->warn("Event {$event->event_id} failed: {$exception->getMessage()}");
            }
        }

        $this->info("Published: {$published}; failed: {$failed}; inspected: {$events->count()}.");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
