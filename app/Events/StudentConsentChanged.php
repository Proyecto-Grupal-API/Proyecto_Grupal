<?php

namespace App\Events;

use App\Contracts\DomainEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class StudentConsentChanged implements DomainEvent
{
    use Dispatchable, SerializesModels;

    public string $id;

    public function __construct(
        public string $studentId,
        public string $consentId,
        public string $status,
        public string $version,
        public ?string $actorId = null,
    ) {
        $this->id = (string) Str::uuid();
    }

    public function eventId(): string { return $this->id; }
    public function eventName(): string { return 'student.consent.changed.v1'; }
    public function aggregateId(): string { return $this->studentId; }
    public function payload(): array
    {
        return [
            'student_id' => $this->studentId,
            'consent_id' => $this->consentId,
            'status' => $this->status,
            'version' => $this->version,
            'actor_id' => $this->actorId,
        ];
    }
}
