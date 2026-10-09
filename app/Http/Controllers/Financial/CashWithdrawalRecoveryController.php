<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Models\CashRegister;
use App\Domains\Financial\Models\CashShift;
use App\Domains\Financial\Models\FinancialRefundRequest;
use App\Domains\Financial\Models\Withdrawal;
use App\Domains\Financial\Services\CashWithdrawalRecoveryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CashWithdrawalRecoveryController extends CashController
{
    private function requests(string $association): Builder
    {
        $registers = CashRegister::where('association_id', $association)->select('id');
        $shifts = CashShift::whereIn('cash_register_id', $registers)->select('public_id');
        $withdrawals = Withdrawal::whereIn('cash_shift_id', $shifts)->select('public_id');
        return FinancialRefundRequest::whereHas('originalTransaction', fn ($query) =>
            $query->where('reference_type', 'WITHDRAWAL')->whereIn('reference_id', $withdrawals));
    }

    public function index(Request $request, string $associationId): JsonResponse
    {
        $this->authorizeAssociation($request, $associationId, 'read');
        return $this->paginated($request, $this->requests($associationId)->with(['wallet', 'originalTransaction', 'withdrawalRecovery.cashMovement.receipt'])
            ->orderByDesc('id')->paginate($this->pageSize($request)), function ($refund) {
                $recovery = $refund->withdrawalRecovery;
                return ['id' => strtolower($refund->public_id), 'status' => $refund->status->value,
                    'amount_cents' => $refund->amount_cents, 'currency' => $refund->wallet->currency,
                    'wallet_id' => strtolower($refund->wallet_id), 'withdrawal_id' => strtolower($refund->originalTransaction->reference_id),
                    'original_transaction_id' => strtolower($refund->original_transaction_id), 'reason' => $refund->reason,
                    'recovery' => $recovery ? ['id' => strtolower($recovery->public_id), 'folio' => $recovery->recovery_reference,
                        'cash_shift_id' => $recovery->cash_shift_id ? strtolower($recovery->cash_shift_id) : null,
                        'receipt_id' => $recovery->cashMovement?->receipt ? strtolower($recovery->cashMovement->receipt->public_id) : null,
                        'confirmed_by' => $recovery->confirmed_by] : null];
            });
    }

    public function recover(Request $request, string $associationId, string $shiftId, string $refundId, CashWithdrawalRecoveryService $service): JsonResponse
    {
        $actor = $this->authorizeAssociation($request, $associationId, 'recover');
        $shift = $this->shift($associationId, $shiftId);
        abort_unless($shift->agent_id === $actor, 403, 'El turno pertenece a otro operador.');
        $refund = $this->requests($associationId)->where('public_id', $refundId)->firstOrFail();
        $values = $request->validate(['reason' => ['required', 'string', 'max:1000'],
            'amount_cents' => ['prohibited'], 'recovery_reference' => ['prohibited']]);
        $key = $this->key($request);
        return $this->execute($request, function () use ($service, $associationId, $shift, $refund, $key, $actor, $values) {
            $recovery = $service->recover($associationId, $shift->public_id, $refund, $key, $actor, $values['reason']);
            $receipt = $recovery->cashMovement->receipt;
            return ['id' => strtolower($recovery->public_id), 'refund_request_id' => strtolower($recovery->refund_request_id),
                'withdrawal_id' => strtolower($recovery->withdrawal_id), 'amount_cents' => $recovery->amount_cents,
                'currency' => $recovery->currency, 'folio' => $recovery->recovery_reference,
                'cash_shift_id' => strtolower($recovery->cash_shift_id), 'cash_movement_id' => strtolower($recovery->cash_movement_id),
                'receipt_id' => strtolower($receipt->public_id), 'confirmed_by' => $recovery->confirmed_by,
                'confirmed_at' => $recovery->confirmed_at?->toISOString()];
        });
    }
}
