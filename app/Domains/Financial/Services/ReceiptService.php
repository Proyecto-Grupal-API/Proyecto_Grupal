<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\Wallet;
use InvalidArgumentException;

class ReceiptService
{
    public function forTransaction(string $transactionId): array
    {
        $transaction = FinancialTransaction::where(
            'public_id',
            $transactionId
        )->firstOrFail();

        $entries = LedgerEntry::where(
            'transaction_id',
            $transaction->public_id
        )
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($entries->isEmpty()) {
            throw new InvalidArgumentException(
                'La transacción todavía no tiene movimientos contables para generar un comprobante.'
            );
        }

        return $this->buildReceipt(
            $transaction,
            $entries
        );
    }

    public function forWallet(
        Wallet $wallet,
        string $transactionId
    ): array {
        LedgerEntry::where(
            'transaction_id',
            $transactionId
        )
            ->where('wallet_id', $wallet->public_id)
            ->firstOrFail();

        $receipt = $this->forTransaction(
            $transactionId
        );

        $receipt['entries'] = array_values(
            array_filter(
                $receipt['entries'],
                fn (array $entry) =>
                    strtolower($entry['wallet_id'])
                    === strtolower($wallet->public_id)
            )
        );

        return $receipt;
    }

    public function listForWallet(
        Wallet $wallet,
        int $limit = 50
    ): array {
        $entries = LedgerEntry::where(
            'wallet_id',
            $wallet->public_id
        )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        if ($entries->isEmpty()) {
            return [];
        }

        $transactions = FinancialTransaction::whereIn(
            'public_id',
            $entries->pluck('transaction_id')->unique()
        )
            ->get()
            ->keyBy(
                fn (FinancialTransaction $transaction) =>
                    strtolower($transaction->public_id)
            );

        return $entries
            ->map(function (LedgerEntry $entry) use ($transactions, $wallet) {
                $transaction = $transactions->get(
                    strtolower($entry->transaction_id)
                );

                return [
                    'transaction_id' => $entry->transaction_id,
                    'movement_type' => $entry->movement_type->value,
                    'amount_cents' => $entry->amount_cents,
                    'currency' => $wallet->currency,
                    'status' => $transaction?->status?->value,
                    'reference_type' => $transaction?->reference_type,
                    'reference_id' => $transaction?->reference_id,
                    'created_at' => $entry->created_at?->toISOString(),
                ];
            })
            ->values()
            ->all();
    }

    private function buildReceipt(
        FinancialTransaction $transaction,
        $entries
    ): array {
        $walletIds = $entries
            ->pluck('wallet_id')
            ->unique()
            ->values();

        $wallets = Wallet::whereIn(
            'public_id',
            $walletIds
        )
            ->get()
            ->keyBy(
                fn (Wallet $wallet) =>
                    strtolower($wallet->public_id)
            );

        return [
            'receipt_id' => $transaction->public_id,
            'transaction_id' => $transaction->public_id,
            'status' => $transaction->status->value,
            'reference_type' => $transaction->reference_type,
            'reference_id' => $transaction->reference_id,
            'original_transaction_id' =>
                $transaction->original_transaction_id,
            'issued_at' =>
                $transaction->created_at?->toISOString(),
            'entries' => $entries
                ->map(function (LedgerEntry $entry) use ($wallets) {
                    $wallet = $wallets->get(
                        strtolower($entry->wallet_id)
                    );

                    return [
                        'id' => $entry->public_id,
                        'wallet_id' => $entry->wallet_id,
                        'currency' => $wallet?->currency,
                        'movement_type' =>
                            $entry->movement_type->value,
                        'amount_cents' => $entry->amount_cents,
                        'balance_after_cents' =>
                            $entry->balance_after_cents,
                        'available_balance_after_cents' =>
                            $entry->available_balance_after_cents,
                        'held_balance_after_cents' =>
                            $entry->held_balance_after_cents,
                        'created_at' =>
                            $entry->created_at?->toISOString(),
                    ];
                })
                ->values()
                ->all(),
        ];
    }
}
