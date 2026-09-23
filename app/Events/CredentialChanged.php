<?php

namespace App\Events;

use App\Contracts\DomainEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class CredentialChanged implements DomainEvent
{
    use Dispatchable, SerializesModels;

    public string $id;

    public function __construct(
        public string $credentialId,
        public string $credentialType,
        public string $operation,
        public string $status,
        public ?string $actorId = null,
    ) {
        $this->id = (string) Str::uuid();
    }

    public function eventId(): string { return $this->id; }
    public function eventName(): string { return 'identity.credential.changed.v1'; }
    public function aggregateId(): string { return $this->credentialId; }
    public function payload(): array
    {
        return [
            'credential_id' => $this->credentialId,
            'credential_type' => $this->credentialType,
            'operation' => $this->operation,
            'status' => $this->status,
            'actor_id' => $this->actorId,
        ];
    }
}
