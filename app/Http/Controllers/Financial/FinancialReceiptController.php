<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Services\ReceiptService;
use App\Domains\Financial\Services\WalletService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class FinancialReceiptController extends Controller
{
    public function index(
        Request $request,
        WalletService $walletService,
        ReceiptService $receiptService
    ): Response {
        $wallet = $walletService->findByOwner(
            'USER',
            (string) $request->user()->getKey(),
            WalletType::USUARIO
        );

        return Inertia::render('Financial/Receipts', [
            'wallet' => $wallet ? [
                'id' => $wallet->public_id,
                'currency' => $wallet->currency,
            ] : null,
            'receipts' => $wallet
                ? $receiptService->listForWallet($wallet)
                : [],
        ]);
    }

    public function show(
        Request $request,
        string $transactionId,
        WalletService $walletService,
        ReceiptService $receiptService
    ): Response {
        $wallet = $walletService->findByOwner(
            'USER',
            (string) $request->user()->getKey(),
            WalletType::USUARIO
        );

        abort_if(!$wallet, 404);

        try {
            $receipt = $receiptService->forWallet(
                $wallet,
                $transactionId
            );
        } catch (InvalidArgumentException $exception) {
            abort(409, $exception->getMessage());
        }

        return Inertia::render('Financial/Receipt', [
            'receipt' => $receipt,
        ]);
    }
}
