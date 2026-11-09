<?php

namespace App\Enums;

enum NfcCardStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
    case Suspended = 'suspended';
    case Replaced = 'replaced';

    /** Ordinary transitions only; replacement is a separate future operation. */
    public function ordinaryTargets(): array
    {
        return match ($this) {
            self::Active => [self::Blocked, self::Suspended],
            self::Blocked => [self::Active, self::Suspended],
            self::Suspended => [self::Active, self::Blocked],
            self::Replaced => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->ordinaryTargets(), true);
    }
}
