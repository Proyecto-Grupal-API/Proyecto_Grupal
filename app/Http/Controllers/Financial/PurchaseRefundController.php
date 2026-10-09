<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Models\PurchasePayment;
use App\Domains\Financial\Models\PurchaseRefundRequest;
use App\Domains\Financial\Services\PurchaseRefundService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PurchaseRefundController extends Controller
{
    public function show(
        Request $request,
        string $refundId
    ): JsonResponse {
        return $this->respond($request, $this->findRefund($refundId));
    }

    public function store(
        Request $request,
        PurchaseRefundService $service
    ): JsonResponse {
        $validated = $request->validate([
            'purchase_payment_id' => ['required', 'uuid'],
            'wallet_amount_cents' => ['required', 'integer', 'min:0'],
            'bonus_refunds' => ['sometimes', 'array'],
            'bonus_refunds.*' => ['required', 'array'],
            'bonus_refunds.*.bonus_id' => ['required', 'uuid'],
            'bonus_refunds.*.amount_cents' => [
                'required',
                'integer',
                'min:1',
            ],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $key = $this->idempotencyKey($request);
        $actor = $this->serviceActor($request);

        $payment = PurchasePayment::where(
            'public_id',
            $validated['purchase_payment_id']
        )->firstOrFail();

        // Normalizar los enteros validados antes de llamar al servicio.
        $bonusRefunds = array_map(
            fn (array $item) => [
                'bonus_id' => $item['bonus_id'],
                'amount_cents' => (int) $item['amount_cents'],
            ],
            $validated['bonus_refunds'] ?? []
        );

        try {
            $refund = $service->request(
                payment: $payment,
                walletAmountCents: (int) $validated['wallet_amount_cents'],
                bonusRefunds: $bonusRefunds,
                idempotencyKey: $key,
                requestedBy: $actor,
                reason: $validated['reason'] ?? null
            );
        } catch (InvalidArgumentException $exception) {
            return $this->conflict($request, $exception);
        }

        return $this->respond(
            $request,
            $refund,
            $refund->wasRecentlyCreated ? 201 : 200
        );
    }

    public function approve(
        Request $request,
        string $refundId,
        PurchaseRefundService $service
    ): JsonResponse {
        $validated = $request->validate([
            'review_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $actor = $this->serviceActor($request);
        $refund = $this->findRefund($refundId);

        try {
            $refund = $service->approve(
                refundRequest: $refund,
                reviewedBy: $actor,
                reviewReason: $validated['review_reason'] ?? null
            );
        } catch (InvalidArgumentException $exception) {
            return $this->conflict($request, $exception);
        }

        return $this->respond($request, $refund);
    }

    public function reject(
        Request $request,
        string $refundId,
        PurchaseRefundService $service
    ): JsonResponse {
        $validated = $request->validate([
            'review_reason' => [
                'required',
                'string',
                'max:1000',
                'regex:/\S/',
            ],
        ]);

        $actor = $this->serviceActor($request);
        $refund = $this->findRefund($refundId);

        try {
            $refund = $service->reject(
                refundRequest: $refund,
                reviewedBy: $actor,
                reviewReason: $validated['review_reason']
            );
        } catch (InvalidArgumentException $exception) {
            return $this->conflict($request, $exception);
        }

        return $this->respond($request, $refund);
    }

    public function complete(
        Request $request,
        string $refundId,
        PurchaseRefundService $service
    ): JsonResponse {
        $key = $this->idempotencyKey($request);
        $actor = $this->serviceActor($request);
        $refund = $this->findRefund($refundId);

        try {
            $service->complete(
                refundRequest: $refund,
                idempotencyKey: $key,
                executedBy: $actor
            );
        } catch (InvalidArgumentException $exception) {
            return $this->conflict($request, $exception);
        }

        return $this->respond($request, $refund->refresh());
    }

    private function findRefund(string $refundId): PurchaseRefundRequest
    {
        return PurchaseRefundRequest::where(
            'public_id',
            $refundId
        )->firstOrFail();
    }

    private function idempotencyKey(Request $request): string
    {
        $validated = validator([
            'idempotency_key' => $request->header('Idempotency-Key'),
        ], [
            'idempotency_key' => [
                'required',
                'string',
                'max:255',
                'regex:/\S/',
            ],
        ])->validate();

        return $validated['idempotency_key'];
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
        PurchaseRefundRequest $refund,
        int $status = 200
    ): JsonResponse {
        return response()->json([
            'data' => [
                'id' => $refund->public_id,
                'purchase_payment_id' => $refund->purchase_payment_id,
                'request_transaction_id' => $refund->request_transaction_id,
                'wallet_id' => $refund->wallet_id,
                'currency' => $refund->currency,
                'total_amount_cents' => $refund->total_amount_cents,
                'wallet_amount_cents' => $refund->wallet_amount_cents,
                'bonus_amount_cents' => $refund->bonus_amount_cents,
                'bonus_refunds' => $refund->bonuses()
                    ->orderBy('bonus_id')
                    ->get()
                    ->map(fn ($line) => [
                        'bonus_id' => $line->bonus_id,
                        'amount_cents' => $line->amount_cents,
                    ])->values()->all(),
                'status' => $refund->status->value,
                'requested_by' => $refund->requested_by,
                'reviewed_by' => $refund->reviewed_by,
                'reason' => $refund->reason,
                'review_reason' => $refund->review_reason,
                'reviewed_at' => $refund->reviewed_at?->toISOString(),
                'completed_at' => $refund->completed_at?->toISOString(),
                'financial_transaction_id' => $refund->financial_transaction_id,
                'created_at' => $refund->created_at?->toISOString(),
                'updated_at' => $refund->updated_at?->toISOString(),
            ],
            'meta' => $this->meta($request),
        ], $status);
    }

    private function conflict(
        Request $request,
        InvalidArgumentException $exception
    ): JsonResponse {
        return response()->json([
            'message' => $exception->getMessage(),
            'meta' => $this->meta($request),
        ], 409);
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