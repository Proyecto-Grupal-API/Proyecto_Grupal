<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Models\LedgerEntry;
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

    public function ledger(string $walletId): JsonResponse
    {
    $wallet = Wallet::where(
        'public_id',
        $walletId
    )->firstOrFail();

    $entries = LedgerEntry::where(
        'wallet_id',
        $wallet->public_id
    )
        ->orderByDesc('created_at')
        ->orderByDesc('id')
        ->limit(50)
        ->get();

    return response()->json([
        'data' => [
            'wallet_id' => $wallet->public_id,
            'items' => $entries->map(function (LedgerEntry $entry) {
                return [
                    'id' => $entry->public_id,
                    'transaction_id' => $entry->transaction_id,
                    'movement_type' => $entry->movement_type->value,
                    'amount_cents' => $entry->amount_cents,
                    'available_balance_after_cents' =>
                        $entry->available_balance_after_cents,
                    'held_balance_after_cents' =>
                        $entry->held_balance_after_cents,
                    'created_at' =>
                        $entry->created_at?->toISOString(),
                ];
            })->values(),
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
