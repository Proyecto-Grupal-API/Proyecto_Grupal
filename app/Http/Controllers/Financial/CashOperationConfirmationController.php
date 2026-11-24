<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Models\CashOperationConfirmation;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\CashOperationConfirmationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $wallet = Wallet::where('public_id', $values['wallet_id'])->firstOrFail();
        $key = $this->key($request);

        return $this->execute($request, fn () => $this->confirmationData($service->request($shift->public_id, $wallet,
            $values['operation'], $values['amount_cents'], $actor, $values['reason'], $key)));
    }

    /** FIN-05: titular y wallet por matrícula para el operador del turno (no es un directorio abierto). */
    public function holder(Request $request, string $associationId, string $shiftId, \App\Domains\Financial\Services\CashHolderLookup $lookup): JsonResponse
    {
        $actor = $this->authorizeAssociation($request, $associationId, 'operate');
        $shift = $this->shift($associationId, $shiftId);
        abort_unless($shift->agent_id === $actor, 403);
        $values = $request->validate(['titular' => ['required', 'string', 'max:128']]);
        try {
            return $this->execute($request, fn () => $lookup->find($values['titular'], $actor, $request->ip(), $shift->cashRegister->currency));
        } catch (\App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()] + ($e instanceof \App\Domains\Financial\Exceptions\TransferRecipientUnavailableException
                ? ['code' => $e->reasonCode, 'correlation_id' => $e->correlationId] : []), 503);
        }
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
            'supervisor_required' => $proof->supervisor_required, 'supervisor_status' => $proof->supervisor_status,
            'approval_policy_version' => $proof->approval_policy_snapshot['version'] ?? 0,
            'rejected_by_system' => $proof->rejected_by === CashOperationConfirmationService::LIMIT_REJECTOR,
            'rejection_reason' => $proof->rejection_reason, 'rejection_alert_id' => $proof->rejection_alert_id ? strtolower($proof->rejection_alert_id) : null,
            'student_url' => route('financial.cash.confirmations.student.show', ['confirmationId' => $proof->public_id], false)];
    }
}
