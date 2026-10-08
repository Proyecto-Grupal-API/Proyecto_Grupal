<?php

namespace App\Listeners;

use App\Contracts\DomainEvent;
use App\Models\EventOutbox;

class StoreDomainEvent
{
    /** Stores integration events in the outbox; audit records are a separate concern. */
    public function handle(DomainEvent $event): void
    {
        EventOutbox::firstOrCreate(['event_id' => $event->eventId()], [
            'event_name' => $event->eventName(),
            'aggregate_id' => $event->aggregateId(),
            'payload' => $event->payload(),
            'occurred_at' => now(),
            'published_at' => null,
        ]);
    }
}
