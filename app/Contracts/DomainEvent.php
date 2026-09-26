<?php

namespace App\Contracts;

interface DomainEvent
{
    public function eventId(): string;
    public function eventName(): string;
    public function aggregateId(): string;
    public function payload(): array;
}
