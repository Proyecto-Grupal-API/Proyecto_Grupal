<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Models\Wallet;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class WalletController extends Controller
{
    public function show(string $walletId): JsonResponse
    {
        $wallet = Wallet::where(
            'public_id',
            $walletId
        )->firstOrFail();

        return response()->json([
            'data' => [
                'id' => $wallet->public_id,
                'owner_type' => $wallet->owner_type,
                'owner_id' => $wallet->owner_id,
                'type' => $wallet->type->value,
                'currency' => $wallet->currency,
                'status' => $wallet->status->value,
                'available_balance_cents' =>
                    $wallet->available_balance_cents,
                'held_balance_cents' =>
                    $wallet->held_balance_cents,
            ],
            'meta' => [
                'request_id' => request()->header(
                    'X-Request-Id',
                    (string) str()->uuid()
                ),
                'api_version' => 'v1',
            ],
        ]);
    }
}
               