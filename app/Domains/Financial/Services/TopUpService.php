<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\TopUpMethod;
use App\Domains\Financial\Enums\TopUpStatus;
use App\Domains\Financial\Enums\WalletStatus;
use App\Domains\Financial\Models\TopUp;
use App\Domains\Financial\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TopUpService
{
    public function __construct(
        private LedgerService $ledgerService
    ) {
    }

    public function create(
        Wallet $wallet,
        int $amountCents,
        TopUpMethod $method,
        ?string $agentId = null,
        ?string $externalReference = null
    ): TopUp {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException(
                'El monto de la recarga debe ser mayor que cero.'
            );
        }

        if ($wallet->status !== WalletStatus::ACTIVA) {
            throw new InvalidArgumentException(
                'La wallet debe estar activa para recibir una recarga.'
            );
        }

        return TopUp::create([
            'public_id' => (string) Str::uuid(),
            'folio' => null,
            'wallet_id' => $wallet->public_id,
            'amount_cents' => $amountCents,
            'currency' => $wallet->currency,
            'method' => $method,
            'status' => TopUpStatus::PENDIENTE,
            'agent_id' => $agentId,
            'cash_shift_id' => null,
            'external_reference' => $externalReference,
        ]);
    }

    public function complete(
        TopUp $topUp,
        string $idempotencyKey
    ): TopUp {
        return DB::connection('sqlsrv')->transaction(
            function () use ($topUp, $idempotencyKey) {
                $lockedTopUp = TopUp::where(
                    'public_id',
                    $topUp->public_id
                )
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedTopUp->status !== TopUpStatus::PENDIENTE) {
                    throw new InvalidArgumentException(
                        'Solo una recarga pendiente puede completarse.'
                    );
                }

                $wallet = Wallet::where(
                    'public_id',
                    $lockedTopUp->wallet_id
                )->firstOrFail();

                if ($wallet->status !== WalletStatus::ACTIVA) {
                    throw new InvalidArgumentException(
                        'La wallet debe estar activa para recibir una recarga.'
                    );
                }

                $this->ledgerService->credit(
                    $wallet,
                    $lockedTopUp->amount_cents,
                    MovementType::RECARGA,
                    $idempotencyKey,
                    'TOPUP',
                    $lockedTopUp->public_id,
                    [
                        'topup_method' => $lockedTopUp->method->value,
                    ]
                );

                $lockedTopUp->status = TopUpStatus::COMPLETADA;
                $lockedTopUp->save();

                return $lockedTopUp;
            }
        );
    }
}