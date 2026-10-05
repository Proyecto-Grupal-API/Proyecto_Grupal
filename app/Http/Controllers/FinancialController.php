<?php

namespace App\Http\Controllers;

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\FinancialAdjustmentService;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\WalletService;
use Illuminate\Http\RedirectResponse;
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
        $wallet = $this->userWallet($request, $walletService);
        $history = $wallet
            ? $ledgerService->getWalletHistory($wallet)
            : collect();

        return Inertia::render('Financial/Dashboard', [
            'wallet' => $this->walletData($wallet),
            'history' => $this->historyData($history),
        ]);
    }

    public function adjustments(
        Request $request,
        WalletService $walletService,
        LedgerService $ledgerService
    ): Response {
        $wallet = $this->userWallet($request, $walletService);
        $history = $wallet
            ? $ledgerService->getWalletHistory($wallet)
            : collect();

        return Inertia::render('Financial/Adjustments', [
            'wallet' => $this->walletData($wallet),
            'history' => $this->historyData($history),
        ]);
    }

    public function requestRefund(
        Request $request,
        string $transactionId,
        WalletService $walletService,
        FinancialAdjustmentService $adjustmentService
    ): RedirectResponse {
        $validated = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);

        $wallet = $this->userWallet($request, $walletService);
        if (!$wallet) {
            return back()->withErrors([
                'transaction' => 'No existe una wallet financiera asociada a tu cuenta.',
            ]);
        }

        $transaction = FinancialTransaction::where(
            'public_id',
            $transactionId
        )->first();

        if (!$transaction) {
            return back()->withErrors([
                'transaction' => 'La operación solicitada no existe.',
            ]);
        }

        $entry = LedgerEntry::where(
            'transaction_id',
            $transaction->public_id
        )
            ->where('wallet_id', $wallet->public_id)
            ->whereIn('movement_type', [
                MovementType::PAGO->value,
                MovementType::RETIRO->value,
                MovementType::TRANSFERENCIA_SALIDA->value,
            ])
            ->where('amount_cents', '<', 0)
            ->first();

        if (!$entry) {
            return back()->withErrors([
                'transaction' => 'Esta operación no admite una devolución desde tu wallet.',
            ]);
        }

        try {
            $refund = $adjustmentService->refund(
                $transaction,
                $validated['amount_cents'],
                $validated['idempotency_key'],
                $validated['reason'] ?? null
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors([
                'amount_cents' => $exception->getMessage(),
            ]);
        }

        return back()->with('success', [
            'message' => 'La solicitud de devolución fue registrada.',
            'transaction_id' => $refund->public_id,
            'status' => $refund->status->value,
        ]);
    }

    private function userWallet(Request $request, WalletService $walletService): ?Wallet
    {
        return $walletService->findByOwner(
            'USER',
            (string) $request->user()->getKey(),
            WalletType::USUARIO
        );
    }

    private function walletData($wallet): ?array
    {
        if (!$wallet) {
            return null;
        }

        return [
            'id' => $wallet->public_id,
            'type' => $wallet->type->value,
            'currency' => $wallet->currency,
            'status' => $wallet->status->value,
            'available_balance_cents' =>
                $wallet->available_balance_cents,
            'held_balance_cents' =>
                $wallet->held_balance_cents,
        ];
    }

    private function historyData($history): array
    {
        $transactionIds = $history
            ->pluck('transaction_id')
            ->unique()
            ->values();

        $transactions = FinancialTransaction::whereIn(
            'public_id',
            $transactionIds
        )
            ->get()
            ->keyBy('public_id');

        return $history->map(function (LedgerEntry $entry) use ($transactions) {
            $transaction = $transactions->get($entry->transaction_id);

            return [
                'id' => $entry->public_id,
                'transaction_id' => $entry->transaction_id,
                'movement_type' => $entry->movement_type->value,
                'amount_cents' => $entry->amount_cents,
                'available_balance_after_cents' =>
                    $entry->available_balance_after_cents,
                'held_balance_after_cents' =>
                    $entry->held_balance_after_cents,
                'reference_type' => $transaction?->reference_type,
                'reference_id' => $transaction?->reference_id,
                'transaction_status' => $transaction?->status?->value,
                'created_at' => $entry->created_at?->toISOString(),
            ];
        })->values()->all();
    }
}
