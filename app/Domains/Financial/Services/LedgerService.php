<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class LedgerService
{
    public function credit(
        Wallet $wallet,
        int $amountCents,
        MovementType $movementType,
        string $idempotencyKey,
        ?string $referenceType = null,
        ?string $referenceId = null,
        array $metadata = []
    ): FinancialTransaction {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException(
                'El monto debe ser mayor que cero.'
            );
        }

        return DB::connection('pgsql')->transaction(
            function () use (
                $wallet,
                $amountCents,
                $movementType,
                $idempotencyKey,
                $referenceType,
                $referenceId,
                $metadata
            ) {
                $existingTransaction = FinancialTransaction::where(
                    'idempotency_key',
                    $idempotencyKey
                )->first();

                if ($existingTransaction) {
                    return $existingTransaction;
                }

                $transaction = FinancialTransaction::create([
                    'public_id' => (string) Str::uuid(),
                    'idempotency_key' => $idempotencyKey,
                    'status' => TransactionStatus::PENDIENTE,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'metadata' => $metadata,
                ]);

                $wallet->increment(
                    'available_balance_cents',
                    $amountCents
                );

                $wallet->refresh();

                LedgerEntry::create([
                    'public_id' => (string) Str::uuid(),
                    'transaction_id' => $transaction->public_id,
                    'wallet_id' => $wallet->public_id,
                    'movement_type' => $movementType,
                    'amount_cents' => $amountCents,
                    'balance_after_cents' => $wallet->available_balance_cents,
                ]);

                $transaction->status = TransactionStatus::COMPLETADA;
                $transaction->save();

                return $transaction;
            }
        );
    }
}