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
        if (in_array($movementType, [MovementType::RETENCION, MovementType::LIBERACION], true)) {
            throw new InvalidArgumentException('Usa WalletHoldService para retener o liberar saldo.');
        }

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
                    $sameReferenceType =
                        $existingTransaction->reference_type === $referenceType;

                    $sameReferenceId =
                        strtolower((string) $existingTransaction->reference_id)
                        === strtolower((string) $referenceId);

                    if (!$sameReferenceType || !$sameReferenceId) {
                        throw new InvalidArgumentException(
                            'La clave de idempotencia ya pertenece a otra operación.'
                        );
                    }

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

                $lockedWallet = Wallet::where(
                    'public_id',
                    $wallet->public_id
                )
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedWallet->increment(
                    'available_balance_cents',
                    $amountCents
                );

                $lockedWallet->refresh();

                LedgerEntry::create([
                    'public_id' => (string) Str::uuid(),
                    'transaction_id' => $transaction->public_id,
                    'wallet_id' => $lockedWallet->public_id,
                    'movement_type' => $movementType,
                    'amount_cents' => $amountCents,
                    'balance_after_cents' =>
                        $lockedWallet->available_balance_cents,
                    'available_balance_after_cents' =>
                        $lockedWallet->available_balance_cents,
                    'held_balance_after_cents' =>
                        $lockedWallet->held_balance_cents,
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
        if (in_array($movementType, [MovementType::RETENCION, MovementType::LIBERACION], true)) {
            throw new InvalidArgumentException('Usa WalletHoldService para retener o liberar saldo.');
        }

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
                    $sameReferenceType =
                        $existingTransaction->reference_type === $referenceType;

                    $sameReferenceId =
                        strtolower((string) $existingTransaction->reference_id)
                        === strtolower((string) $referenceId);

                    if (!$sameReferenceType || !$sameReferenceId) {
                        throw new InvalidArgumentException(
                            'La clave de idempotencia ya pertenece a otra operación.'
                        );
                    }

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
                    $sameReferenceType =
                        $existingTransaction->reference_type === $referenceType;

                    $sameReferenceId =
                        strtolower((string) $existingTransaction->reference_id)
                        === strtolower((string) $referenceId);

                    if (!$sameReferenceType || !$sameReferenceId) {
                        throw new InvalidArgumentException(
                            'La clave de idempotencia ya pertenece a otra operación.'
                        );
                    }

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

    /** Monetary amount is the total-value change; deltas track its two buckets. */
    public function moveHeldFunds(
        Wallet $wallet, int $amountCents, string $idempotencyKey,
        string $holdId, string $actorId, string $mode
    ): FinancialTransaction {
        if ($amountCents <= 0 || trim($idempotencyKey) === '' || strlen($idempotencyKey) > 255
            || trim($actorId) === '' || !in_array($mode, ['RESERVE', 'RELEASE', 'CAPTURE'], true)) {
            throw new InvalidArgumentException('Datos de movimiento retenido no válidos.');
        }
        return DB::connection('sqlsrv')->transaction(function () use (
            $wallet, $amountCents, $idempotencyKey, $holdId, $actorId, $mode
        ) {
            $locked = Wallet::where('public_id', $wallet->public_id)->lockForUpdate()->firstOrFail();
            if ($locked->available_balance_cents < 0 || $locked->held_balance_cents < 0) {
                throw new InvalidArgumentException('La wallet presenta saldos inconsistentes.');
            }
            $reference = 'WALLET_HOLD_' . $mode;
            $existing = FinancialTransaction::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ($existing->reference_type !== $reference
                    || strtolower((string) $existing->reference_id) !== strtolower($holdId)
                    || $existing->status !== TransactionStatus::COMPLETADA
                    || strtolower((string) ($existing->metadata['wallet_id'] ?? '')) !== strtolower($locked->public_id)
                    || ($existing->metadata['amount_cents'] ?? null) !== $amountCents
                    || ($existing->metadata['actor_id'] ?? null) !== $actorId) {
                    throw new InvalidArgumentException('La clave de idempotencia pertenece a otra operación.');
                }
                return $existing;
            }
            if ($mode !== 'RELEASE' && $locked->status !== \App\Domains\Financial\Enums\WalletStatus::ACTIVA) {
                throw new InvalidArgumentException('La wallet debe estar activa.');
            }
            if (($mode === 'RESERVE' && $locked->available_balance_cents < $amountCents)
                || ($mode !== 'RESERVE' && $locked->held_balance_cents < $amountCents)) {
                throw new InvalidArgumentException('Saldo insuficiente para el movimiento retenido.');
            }
            [$availableDelta, $heldDelta, $totalDelta, $movement] = match ($mode) {
                'RESERVE' => [-$amountCents, $amountCents, 0, MovementType::RETENCION],
                'RELEASE' => [$amountCents, -$amountCents, 0, MovementType::LIBERACION],
                'CAPTURE' => [0, -$amountCents, -$amountCents, MovementType::PAGO],
            };
            $transaction = FinancialTransaction::create([
                'public_id' => (string) Str::uuid(), 'idempotency_key' => $idempotencyKey,
                'reference_type' => $reference, 'reference_id' => $holdId,
                'status' => TransactionStatus::PENDIENTE,
                'metadata' => ['amount_cents' => $amountCents, 'actor_id' => $actorId, 'wallet_id' => $locked->public_id],
            ]);
            $locked->available_balance_cents += $availableDelta;
            $locked->held_balance_cents += $heldDelta;
            $locked->save();
            LedgerEntry::create([
                'public_id' => (string) Str::uuid(), 'transaction_id' => $transaction->public_id,
                'wallet_id' => $locked->public_id, 'movement_type' => $movement,
                'amount_cents' => $totalDelta, 'available_delta_cents' => $availableDelta,
                'held_delta_cents' => $heldDelta,
                'balance_after_cents' => $locked->available_balance_cents,
                'available_balance_after_cents' => $locked->available_balance_cents,
                'held_balance_after_cents' => $locked->held_balance_cents,
            ]);
            $transaction->status = TransactionStatus::COMPLETADA;
            $transaction->save();
            return $transaction;
        });
    }

    public function getWalletHistory(Wallet $wallet)
    {
        return LedgerEntry::where(
            'wallet_id',
            $wallet->public_id
        )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }
}
