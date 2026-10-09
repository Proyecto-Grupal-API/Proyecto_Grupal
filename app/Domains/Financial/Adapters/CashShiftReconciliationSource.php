<?php

namespace App\Domains\Financial\Adapters;

use App\Domains\Financial\Contracts\CashReconciliationSource;
use App\Domains\Financial\Data\CashDifference;
use App\Domains\Financial\Enums\CashShiftStatus;
use App\Domains\Financial\Models\CashShift;
use App\Domains\Financial\Services\CashShiftService;
use Carbon\CarbonImmutable;

class CashShiftReconciliationSource implements CashReconciliationSource
{
    public function isIntegrated(): bool
    {
        return (bool) config('financial.cash.reconciliation_enabled', false);
    }
    public function differences(CarbonImmutable $windowStart, CarbonImmutable $windowEnd, ?array $walletIds): iterable
    {
        // A drawer variance belongs to its association/shift, not to a student wallet.
        if ($walletIds !== null) return;
        $shifts = CashShift::with('cashRegister')->where('status', CashShiftStatus::CLOSED)
            ->where('closed_at', '>=', $windowStart)->where('closed_at', '<', $windowEnd)->orderBy('id')->cursor();
        foreach ($shifts as $shift) {
            $expected = app(CashShiftService::class)->calculate($shift)['expected_amount_cents'];
            $references = ['cash_shift_id' => strtolower($shift->public_id), 'cash_register_id' => strtolower($shift->cashRegister->public_id),
                'association_id' => $shift->cashRegister->association_id, 'currency' => $shift->cashRegister->currency,
                'closed_by' => $shift->closed_by, 'closing_reason' => $shift->closing_reason];
            if ($expected !== $shift->counted_amount_cents) yield new CashDifference('CASH_SHIFT', $shift->public_id, null,
                $expected, $shift->counted_amount_cents, $references);
            if ($expected !== $shift->expected_amount_cents) yield new CashDifference('CASH_SHIFT_SNAPSHOT', $shift->public_id, null,
                $expected, $shift->expected_amount_cents, $references);
        }
    }
}
