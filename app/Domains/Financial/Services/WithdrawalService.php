<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\WalletStatus;
use App\Domains\Financial\Enums\WithdrawalMethod;
use App\Domains\Financial\Enums\WithdrawalStatus;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Models\Withdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WithdrawalService
{
    public function create(
        Wallet $wallet,
        int $amountCents,
        WithdrawalMethod $method,
        ?string $agentId = null,
        ?string $externalReference = null
    ): Withdrawal {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException(
                'El monto del retiro debe ser mayor que cero.'
            );
        }

        if ($wallet->status !== WalletStatus::ACTIVA) {
            throw new InvalidArgumentException(
                'La wallet debe estar activa para solicitar un retiro.'
            );
        }

        return Withdrawal::create([
            'public_id' => (string) Str::uuid(),
            'folio' => null,
            'wallet_id' => $wallet->public_id,
            'amount_cents' => $amountCents,
            'currency' => $wallet->currency,
            'method' => $method,
            'status' => WithdrawalStatus::PENDIENTE,
            'agent_id' => $agentId,
            'cash_shift_id' => null,
            'external_reference' => $externalReference,
        ]);
    }

    public function complete(
        Withdrawal $withdrawal,
        string $idempotencyKey
    ): Withdrawal {
        return DB::connection('sqlsrv')->transaction(
            function () use (
                $withdrawal,
                $idempotencyKey
            ) {
                $lockedWithdrawal = Withdrawal::where(
                    'public_id',
                    $withdrawal->public_id
                )
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $lockedWithdrawal->status
                    !== WithdrawalStatus::PENDIENTE
                ) {
                    throw new InvalidArgumentException(
                        'El retiro debe estar pendiente para completarse.'
                    );
                }

                $wallet = Wallet::where(
                    'public_id',
                    $lockedWithdrawal->wallet_id
                )->firstOrFail();

                if ($wallet->status !== WalletStatus::ACTIVA) {
                    throw new InvalidArgumentException(
                        'La wallet debe estar activa para completar el retiro.'
                    );
                }

                $ledgerService = new LedgerService();

                $ledgerService->debit(
                    $wallet,
                    $lockedWithdrawal->amount_cents,
                    MovementType::RETIRO,
                    $idempotencyKey,
                    'WITHDRAWAL',
                    $lockedWithdrawal->public_id,
                    [
                        'withdrawal_method' =>
                            $lockedWithdrawal->method->value,
                    ]
                );

                $lockedWithdrawal->status =
                    WithdrawalStatus::COMPLETADA;

                $lockedWithdrawal->save();

                return $lockedWithdrawal;
            }
        );
    }
}