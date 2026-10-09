<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Models\FinancialRefundRequest;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Services\FinancialAdjustmentService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class RefundController extends Controller
{
    public function show(
        Request $request,
        string $refundId
    ): JsonResponse {
        $refund = FinancialRefundRequest::where(
            'public_id',
            $refundId
        )->firstOrFail();

        return $this->respond($request, $refund);
    }

    public function store(
        Request $request,
        FinancialAdjustmentService $service
    ): JsonResponse {
        $validated = $request->validate([
            'original_transaction_id' => [
                'required',
                'uuid',
            ],
            'amount_cents' => [
                'required',
                'integer',
                'min:1',
            ],
            'reason' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        $headerData = validator([
            'idempotency_key' => $request->header('Idempotency-Key'),
        ], [
            'idempotency_key' => [
                'required',
                'string',
                'max:255',
                'regex:/\S/',
            ],
        ])->validate();

        $clientId = $request->attributes->get('oauth_client_id');

        if (!is_string($clientId) || trim($clientId) === '') {
            abort(401, 'No fue posible identificar al servicio solicitante.');
        }

        $original = FinancialTransaction::where(
            'public_id',
            $validated['original_transaction_id']
        )->firstOrFail();

        // Admitir pagos con un cargo o transferencias entre dos wallets.
        $entries = LedgerEntry::where(
            'transaction_id',
            $original->public_id
        )->get();

        $entry = $entries->first(
            fn (LedgerEntry $item) => $item->amount_cents < 0
        );

       $isPayment =
           $entries->count() === 1 &&
           $entry &&
           $entry->movement_type === MovementType::PAGO;

       $incomingEntry = $entries->first(
           fn (LedgerEntry $item) =>
               $item->movement_type === MovementType::TRANSFERENCIA_ENTRADA &&
               $item->amount_cents > 0
        );

        $isTransfer =
            $entries->count() === 2 &&
            $entry &&
            $incomingEntry &&
            $entry->movement_type === MovementType::TRANSFERENCIA_SALIDA &&
            $incomingEntry->amount_cents === abs($entry->amount_cents) &&
            strtolower($incomingEntry->wallet_id) !==
                strtolower($entry->wallet_id);

        $isWithdrawal =
            $entries->count() === 1 &&
            $entry &&
            $entry->movement_type === MovementType::RETIRO &&
            $original->reference_type === 'WITHDRAWAL';   

         if (!$isPayment && !$isTransfer && !$isWithdrawal) {
             return response()->json([
                 'message' =>
                     'Esta API solo admite devoluciones de pagos, transferencias y retiros.',
                 'meta' => $this->meta($request),
             ], 409);
        }

        try {
            $transaction = $service->refund(
                originalTransaction: $original,
                amountCents: (int) $validated['amount_cents'],
                idempotencyKey: $headerData['idempotency_key'],
                requestedBy: 'service:' . $clientId,
                reason: $validated['reason'] ?? null
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'meta' => $this->meta($request),
            ], 409);
        }

        $refund = FinancialRefundRequest::where(
            'request_transaction_id',
            $transaction->public_id
        )->firstOrFail();

        // Una solicitud nueva devuelve 201; un reintento devuelve 200.
        return $this->respond(
            $request,
            $refund,
            $transaction->wasRecentlyCreated ? 201 : 200
        );
    }

    public function approve(
        Request $request,
        string $refundId,
        FinancialAdjustmentService $service
    ): JsonResponse {
        $validated = $request->validate([
            'review_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $reviewedBy = $this->serviceActor($request);

        $refund = FinancialRefundRequest::where(
            'public_id',
            $refundId
        )->firstOrFail();

        try {
            $refund = $service->approveRefund(
                refundRequest: $refund,
                reviewedBy: $reviewedBy,
                reviewReason: $validated['review_reason'] ?? null
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'meta' => $this->meta($request),
            ], 409);
        }

        return $this->respond($request, $refund);

    }

    public function reject(
        Request $request,
        string $refundId,
        FinancialAdjustmentService $service
    ): JsonResponse {
        $validated = $request->validate([
            'review_reason' => [
                'required',
                'string',
                'max:255',
                'regex:/\S/',
            ],
        ]);

        $reviewedBy = $this->serviceActor($request);

        $refund = FinancialRefundRequest::where(
            'public_id',
            $refundId
        )->firstOrFail();

        try {
            $refund = $service->rejectRefund(
                refundRequest: $refund,
                reviewedBy: $reviewedBy,
                reviewReason: $validated['review_reason']
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'meta' => $this->meta($request),
            ], 409);
        } 

        return $this->respond($request, $refund);
    }

    public function complete(
        Request $request,
        string $refundId,
        FinancialAdjustmentService $service
    ): JsonResponse {
        $headerData = validator([
            'idempotency_key' => $request->header('Idempotency-Key'),
        ], [
            'idempotency_key' => [
                'required',
                'string',
                'max:255',
                'regex:/\S/',
            ],
        ])->validate();

        $executedBy = $this->serviceActor($request);

        $refund = FinancialRefundRequest::where(
            'public_id',
            $refundId
        )->firstOrFail();

        try {
             $service->completeRefund(
                 refundRequest: $refund,
                 idempotencyKey: $headerData['idempotency_key'],
                 executedBy: $executedBy
        );

     } catch (InvalidArgumentException $exception) {
         return response()->json([
             'message' => $exception->getMessage(),
             'meta' => $this->meta($request),
         ], 409);
     }

     return $this->respond($request, $refund->refresh());
 }
                          
public function recoverWithdrawal(
    Request $request,
    string $refundId,
    FinancialAdjustmentService $service
): JsonResponse {
    $validated = $request->validate([
        'recovery_reference' => [
            'required',
            'string',
            'max:255',
            'regex:/\S/',
        ],
        'notes' => [
            'nullable',
            'string',
            'max:1000',
        ],
    ]);

    $confirmedBy = $this->serviceActor($request);

    $refund = FinancialRefundRequest::where(
        'public_id',
        $refundId
    )->firstOrFail();

    try {
        $recovery = $service->confirmWithdrawalRecovery(
            refundRequest: $refund,
            recoveryReference: $validated['recovery_reference'],
            confirmedBy: $confirmedBy,
            notes: $validated['notes'] ?? null
        );
    } catch (InvalidArgumentException $exception) {
        return response()->json([
            'message' => $exception->getMessage(),
            'meta' => $this->meta($request),
        ], 409);
    }

    return response()->json([
        'data' => [
            'id' => $recovery->public_id,
            'refund_request_id' => $recovery->refund_request_id,
            'withdrawal_id' => $recovery->withdrawal_id,
            'amount_cents' => $recovery->amount_cents,
            'currency' => $recovery->currency,
            'recovery_reference' => $recovery->recovery_reference,
            'confirmed_by' => $recovery->confirmed_by,
            'confirmed_at' => $recovery->confirmed_at?->toISOString(),
            'notes' => $recovery->notes,
        ],
        'meta' => $this->meta($request),
    ], $recovery->wasRecentlyCreated ? 201 : 200);
}                        
                          
private function serviceActor(Request $request): string
{
    $clientId = $request->attributes->get('oauth_client_id');

    if (!is_string($clientId) || trim($clientId) === '') {
        abort(401, 'No fue posible identificar al servicio responsable.');
    }

    return 'service:' . $clientId;
}

    private function respond(
        Request $request,
        FinancialRefundRequest $refund,
        int $status = 200
    ): JsonResponse {
        return response()->json([
            'data' => [
                'id' => $refund->public_id,
                'request_transaction_id' =>
                    $refund->request_transaction_id,
                'original_transaction_id' =>
                    $refund->original_transaction_id,
                'wallet_id' => $refund->wallet_id,
                'amount_cents' => $refund->amount_cents,
                'status' => $refund->status->value,
                'requested_by' => $refund->requested_by,
                'reviewed_by' => $refund->reviewed_by,
                'reason' => $refund->reason,
                'review_reason' => $refund->review_reason,
                'reviewed_at' =>
                    $refund->reviewed_at?->toISOString(),
                'completed_at' =>
                    $refund->completed_at?->toISOString(),
                'financial_transaction_id' =>
                    $refund->financial_transaction_id,
                'created_at' =>
                    $refund->created_at?->toISOString(),
                'updated_at' =>
                    $refund->updated_at?->toISOString(),
            ],
            'meta' => $this->meta($request),
        ], $status);
    }

    private function meta(Request $request): array
    {
        return [
            'request_id' => $request->header(
                'X-Request-Id',
                (string) str()->uuid()
            ),
            'api_version' => 'v1',
        ];
    }
}