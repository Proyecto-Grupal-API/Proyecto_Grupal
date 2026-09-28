<?php

namespace App\Http\Controllers;

use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Services\WalletService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinancialController extends Controller
{
    public function index(
        Request $request,
        WalletService $walletService
    ): Response {
        $userId = (string) $request->user()->getKey();

        $wallet = $walletService->findByOwner(
            'USER',
            $userId,
            WalletType::USUARIO
        );

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
        ]);
    }
}
