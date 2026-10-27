<?php

namespace App\Domains\Financial\Enums;

enum AlertStatus: string
{
    case ABIERTA = 'ABIERTA';
    case EN_REVISION = 'EN_REVISION';
    case RESUELTA = 'RESUELTA';
    case DESCARTADA = 'DESCARTADA';

    /**
     * Transiciones permitidas. RESUELTA y DESCARTADA son finales.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::ABIERTA => [
                self::EN_REVISION,
                self::RESUELTA,
                self::DESCARTADA,
            ],
            self::EN_REVISION => [
                self::RESUELTA,
                self::DESCARTADA,
            ],
            self::RESUELTA, self::DESCARTADA => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
