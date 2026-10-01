<?php

namespace App\Http\Controllers;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\WalletService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinancialController extends Controller
{
    public function index(
        Request $request,
        WalletService $walletService,
        LedgerService $ledgerService
    ): Response {
        $userId = (string) $request->user()->getKey();

        $wallet = $walletService->findByOwner(
            'USER',
            $userId,
            WalletType::USUARIO
        );

        $history = $wallet
            ? $ledgerService->getWalletHistory($wallet)
            : collect();

        return Inertia::render('Financial/Dashboard', [
            'wallet' => $wallet ? [
                'id' => $wallet->public_id,
                'type' => $wallet->type->value,
                'currency' => $wallet->currency,
                'status' => $wallet->status->value,
                'available_balance_cents' =>
                    $wallet->available_balance_cents,
                'held_balance_cents' =>
                    $wallet->held_balance_cents,
            ] : null,

            'history' => $history->map(function ($entry) {
                return [
                    'id' => $entry->public_id,
                    'movement_type' => $entry->movement_type->value,
                    'amount_cents' => $entry->amount_cents,
                    'available_balance_after_cents' =>
                        $entry->available_balance_after_cents,
                    'held_balance_after_cents' =>
                        $entry->held_balance_after_cents,
                    'created_at' => $entry->created_at?->toISOString(),
                ];
            })->values(),
        ]);
    }
}
