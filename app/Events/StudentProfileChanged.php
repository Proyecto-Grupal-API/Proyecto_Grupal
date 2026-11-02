<?php

namespace App\Events;

use App\Contracts\DomainEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class StudentProfileChanged implements DomainEvent
{
    use Dispatchable, SerializesModels;

    public string $id;

    public function __construct(
        public string $studentId,
        public string $operation,
        public array $changedFields,
        public ?string $actorId,
    ) {
        $this->id = (string) Str::uuid();
    }

    public function eventId(): string
    {
        return $this->id;
    }

    public function eventName(): string
    {
        return 'student.profile.changed.v1';
    }

    public function aggregateId(): string
    {
        return $this->studentId;
    }

    public function payload(): array
    {
        return [
            'student_id' => $this->studentId,
            'operation' => $this->operation,
            'changed_fields' => $this->changedFields,
            'actor_id' => $this->actorId,
        ];
    }
}
