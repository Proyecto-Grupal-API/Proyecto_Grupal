<?php
namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Models\WalletHold;
use App\Domains\Financial\Services\WalletHoldService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\Concerns\FinancialControlResponses;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class WalletHoldController extends Controller
{
    use FinancialControlResponses;

    public function show(Request $request, string $holdId): JsonResponse
    {
        return $this->ok($request, $this->data(WalletHold::where('public_id', $holdId)->firstOrFail()));
    }

    public function store(Request $request, WalletHoldService $service): JsonResponse
    {
        $data = $request->validate([
            'wallet_id' => ['required', 'uuid'], 'amount_cents' => ['required', 'integer', 'min:1'],
            'operation_type' => ['required', 'string', 'max:100', 'regex:/^[A-Z][A-Z0-9_]*$/'],
            'expires_at' => ['required', 'date'], 'reason' => ['required', 'string', 'max:1000'],
            'reference_type' => ['required', 'string', 'max:100'],
            'reference_id' => ['required', 'string', 'max:255'],
        ]);
        $key = $this->key($request);
        $wallet = Wallet::where('public_id', $data['wallet_id'])->firstOrFail();
        try {
            $hold = $service->create($wallet, (int) $data['amount_cents'], $data['operation_type'],
                CarbonImmutable::parse($data['expires_at']), $key, $this->authenticatedActor($request),
                $data['reason'], $data['reference_type'], $data['reference_id']);
        } catch (InvalidArgumentException $exception) {
            return $this->conflict($request, $exception->getMessage());
        }
        return $this->ok($request, $this->data($hold), $hold->wasRecentlyCreated ? 201 : 200);
    }

    public function release(Request $request, string $holdId, WalletHoldService $service): JsonResponse
    {
        return $this->close($request, $holdId, $service, false);
    }

    public function capture(Request $request, string $holdId, WalletHoldService $service): JsonResponse
    {
        return $this->close($request, $holdId, $service, true);
    }

    private function close(Request $request, string $holdId, WalletHoldService $service, bool $capture): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $key = $this->key($request);
        $hold = WalletHold::where('public_id', $holdId)->firstOrFail();
        try {
            $method = $capture ? 'capture' : 'release';
            $hold = $service->$method($hold, $key, $this->authenticatedActor($request), $data['reason']);
        } catch (InvalidArgumentException $exception) {
            return $this->conflict($request, $exception->getMessage());
        }
        return $this->ok($request, $this->data($hold));
    }

    private function key(Request $request): string
    {
        $data = validator(['key' => $request->header('Idempotency-Key')], [
            'key' => ['required', 'string', 'max:255'],
        ])->validate();
        return $data['key'];
    }

    private function data(WalletHold $hold): array
    {
        return [
            'id' => strtolower($hold->public_id), 'wallet_id' => $hold->wallet_id,
            'amount_cents' => $hold->amount_cents, 'currency' => $hold->currency,
            'operation_type' => $hold->operation_type, 'status' => $hold->status->value,
            'expires_at' => $hold->expires_at->toISOString(), 'requested_by' => $hold->requested_by,
            'reason' => $hold->reason, 'reference_type' => $hold->reference_type,
            'reference_id' => $hold->reference_id, 'hold_transaction_id' => $hold->hold_transaction_id,
            'closing_transaction_id' => $hold->closing_transaction_id,
            'closed_by' => $hold->closed_by, 'closing_reason' => $hold->closing_reason,
            'closed_at' => $hold->closed_at?->toISOString(),
        ];
    }
}
