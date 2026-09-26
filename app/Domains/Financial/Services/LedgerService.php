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

        return DB::connection('sqlsrv')->transaction(
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
                    'balance_after_cents' =>
                        $wallet->available_balance_cents,
                    'available_balance_after_cents' =>
                        $wallet->available_balance_cents,
                    'held_balance_after_cents' =>
                        $wallet->held_balance_cents,
                ]);

                $transaction->status = TransactionStatus::COMPLETADA;
                $transaction->save();

                return $transaction;
            }
        );
    }

    public function debit(
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

        return DB::connection('sqlsrv')->transaction(
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

                $lockedWallet = Wallet::where(
                    'public_id',
                    $wallet->public_id
                )
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $lockedWallet->available_balance_cents
                    < $amountCents
                ) {
                    throw new InvalidArgumentException(
                        'Saldo insuficiente.'
                    );
                }

                $transaction = FinancialTransaction::create([
                    'public_id' => (string) Str::uuid(),
                    'idempotency_key' => $idempotencyKey,
                    'status' => TransactionStatus::PENDIENTE,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'metadata' => $metadata,
                ]);

                $lockedWallet->decrement(
                    'available_balance_cents',
                    $amountCents
                );

                $lockedWallet->refresh();

                LedgerEntry::create([
                    'public_id' => (string) Str::uuid(),
                    'transaction_id' => $transaction->public_id,
                    'wallet_id' => $lockedWallet->public_id,
                    'movement_type' => $movementType,
                    'amount_cents' => -$amountCents,
                    'balance_after_cents' =>
                        $lockedWallet->available_balance_cents,
                    'available_balance_after_cents' =>
                        $lockedWallet->available_balance_cents,
                    'held_balance_after_cents' =>
                        $lockedWallet->held_balance_cents,
                ]);

                $transaction->status =
                    TransactionStatus::COMPLETADA;

                $transaction->save();

                return $transaction;
            }
        );
    }

    public function transfer(
        Wallet $sourceWallet,
        Wallet $destinationWallet,
        int $amountCents,
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

        if (
            strtolower($sourceWallet->public_id)
            === strtolower($destinationWallet->public_id)
        ) {
            throw new InvalidArgumentException(
                'La wallet de origen y destino deben ser diferentes.'
            );
        }

        if ($sourceWallet->currency !== $destinationWallet->currency) {
            throw new InvalidArgumentException(
                'Las wallets deben utilizar la misma moneda.'
            );
        }

        return DB::connection('sqlsrv')->transaction(
            function () use (
                $sourceWallet,
                $destinationWallet,
                $amountCents,
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

                $walletIds = [
                    $sourceWallet->public_id,
                    $destinationWallet->public_id,
                ];

                sort($walletIds);

                $lockedWallets = Wallet::whereIn(
                    'public_id',
                    $walletIds
                )
                    ->orderBy('public_id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy(function (Wallet $wallet) {
                        return strtolower($wallet->public_id);
                    });

                $lockedSource = $lockedWallets->get(
                    strtolower($sourceWallet->public_id)
                );

                $lockedDestination = $lockedWallets->get(
                    strtolower($destinationWallet->public_id)
                );

                if (!$lockedSource || !$lockedDestination) {
                    throw new InvalidArgumentException(
                        'No fue posible localizar las wallets.'
                    );
                }

                if (
                    $lockedSource->available_balance_cents
                    < $amountCents
                ) {
                    throw new InvalidArgumentException(
                        'Saldo insuficiente.'
                    );
                }

                $transaction = FinancialTransaction::create([
                    'public_id' => (string) Str::uuid(),
                    'idempotency_key' => $idempotencyKey,
                    'status' => TransactionStatus::PENDIENTE,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'metadata' => $metadata,
                ]);

                $lockedSource->decrement(
                    'available_balance_cents',
                    $amountCents
                );

                $lockedDestination->increment(
                    'available_balance_cents',
                    $amountCents
                );

                $lockedSource->refresh();
                $lockedDestination->refresh();

                LedgerEntry::create([
                    'public_id' => (string) Str::uuid(),
                    'transaction_id' => $transaction->public_id,
                    'wallet_id' => $lockedSource->public_id,
                    'movement_type' =>
                        MovementType::TRANSFERENCIA_SALIDA,
                    'amount_cents' => -$amountCents,
                    'balance_after_cents' =>
                        $lockedSource->available_balance_cents,
                    'available_balance_after_cents' =>
                        $lockedSource->available_balance_cents,
                    'held_balance_after_cents' =>
                        $lockedSource->held_balance_cents,
                ]);

                LedgerEntry::create([
                    'public_id' => (string) Str::uuid(),
                    'transaction_id' => $transaction->public_id,
                    'wallet_id' => $lockedDestination->public_id,
                    'movement_type' =>
                        MovementType::TRANSFERENCIA_ENTRADA,
                    'amount_cents' => $amountCents,
                    'balance_after_cents' =>
                        $lockedDestination->available_balance_cents,
                    'available_balance_after_cents' =>
                        $lockedDestination->available_balance_cents,
                    'held_balance_after_cents' =>
                        $lockedDestination->held_balance_cents,
                ]);

                $transaction->status =
                    TransactionStatus::COMPLETADA;

                $transaction->save();

                return $transaction;
            }
        );
    }
}