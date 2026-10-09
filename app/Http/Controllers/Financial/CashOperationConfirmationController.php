<?php
namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Models\CashOperationConfirmation;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\CashOperationConfirmationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class CashOperationConfirmationController extends CashController
{
    public function store(Request $request, string $associationId, string $shiftId, CashOperationConfirmationService $service): JsonResponse
    {
        $actor = $this->authorizeAssociation($request, $associationId, 'operate');
        $shift = $this->shift($associationId, $shiftId);
        abort_unless($shift->agent_id === $actor, 403);
        $values = $request->validate(['wallet_id' => ['required', 'uuid'], 'operation' => ['required', Rule::in(['TOPUP', 'WITHDRAWAL'])],
            'amount_cents' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000']]);
        $wallet = Wallet::where('public_id', $values['wallet_id'])->firstOrFail(); $key = $this->key($request);
        return $this->execute($request, fn () => $this->confirmationData($service->request($shift->public_id, $wallet,
            $values['operation'], $values['amount_cents'], $actor, $values['reason'], $key)));
    }
    public function showConfirmation(Request $request, string $associationId, string $shiftId, string $confirmationId): JsonResponse
    {
        $actor = $this->authorizeAssociation($request, $associationId, 'operate');
        $shift = $this->shift($associationId, $shiftId);
        $proof = CashOperationConfirmation::where('cash_shift_id', $shift->id)->where('operator_id', $actor)->where('public_id', $confirmationId)->firstOrFail();
        return $this->execute($request, fn () => $this->confirmationData($proof));
    }
    public function cancel(Request $request, string $associationId, string $shiftId, string $confirmationId, CashOperationConfirmationService $service): JsonResponse
    {
        $actor = $this->authorizeAssociation($request, $associationId, 'operate');
        $shift = $this->shift($associationId, $shiftId);
        $proof = CashOperationConfirmation::where('cash_shift_id', $shift->id)->where('operator_id', $actor)->where('public_id', $confirmationId)->firstOrFail();
        return $this->execute($request, fn () => $this->confirmationData($service->cancel($proof->public_id, $actor)));
    }
    protected function confirmationData(CashOperationConfirmation $proof): array
    {
        return ['id' => strtolower($proof->public_id), 'status' => in_array($proof->status, ['PENDING', 'CONFIRMED'], true) && ! $proof->expires_at->isFuture() ? 'EXPIRED' : $proof->status,
            'wallet_id' => strtolower($proof->wallet_id), 'operation' => $proof->operation, 'amount_cents' => $proof->amount_cents,
            'currency' => $proof->currency, 'reason' => $proof->reason, 'expires_at' => $proof->expires_at->toISOString(),
            'confirmed_at' => $proof->confirmed_at?->toISOString(),
            'student_url' => route('financial.cash.confirmations.student.show', ['confirmationId' => $proof->public_id], false)];
    }
}
