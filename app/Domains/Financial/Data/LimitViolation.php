<?php

namespace App\Domains\Financial\Data;

use App\Domains\Financial\Models\FinancialLimit;
use Carbon\CarbonImmutable;

/**
 * Un límite que la operación evaluada rebasaría (o rebasó).
 */
final class LimitViolation
{
    public function __construct(
        public readonly FinancialLimit $limit,
        public readonly int $observedValue,
        public readonly int $thresholdValue,
        // Ventana UTC [inicio, fin) del periodo evaluado; null en OPERACION.
        public readonly ?CarbonImmutable $periodStart = null,
        public readonly ?CarbonImmutable $periodEnd = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'limit_id' => $this->limit->public_id,
            'limit_name' => $this->limit->name,
            'subject_type' => $this->limit->subject_type->value,
            'subject_id' => $this->limit->subject_id,
            'period' => $this->limit->period->value,
            'metric' => $this->limit->metric->value,
            'action' => $this->limit->action->value,
            'observed_value' => $this->observedValue,
            'threshold_value' => $this->thresholdValue,
            'period_start' => $this->periodStart?->toISOString(),
            'period_end' => $this->periodEnd?->toISOString(),
        ];
    }
}
