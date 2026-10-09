<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\WalletHoldStatus;
use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Models\WalletHold;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WalletHoldService
{
    public function create(
        Wallet $wallet, int $amountCents, string $operationType,
        CarbonInterface $expiresAt, string $idempotencyKey, string $actorId,
        string $reason, string $referenceType, string $referenceId
    ): WalletHold {
        $this->assertInput($idempotencyKey, $actorId, $reason);
        if ($amountCents <= 0 || !preg_match('/^[A-Z][A-Z0-9_]{0,99}$/', $operationType)
            || trim($referenceType) === '' || strlen($referenceType) > 100
            || trim($referenceId) === '' || strlen($referenceId) > 255) {
            throw new InvalidArgumentException('Datos de retención no válidos.');
        }
        $expiry = CarbonImmutable::instance($expiresAt)->setTimezone(config('app.timezone', 'UTC'))->startOfSecond();
        try {
            return DB::connection('sqlsrv')->transaction(function () use (
                $wallet, $amountCents, $operationType, $expiry, $idempotencyKey,
                $actorId, $reason, $referenceType, $referenceId
            ) {
                $lockedWallet = Wallet::where('public_id', $wallet->public_id)->lockForUpdate()->firstOrFail();
                $existing = FinancialTransaction::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    $hold = WalletHold::where('hold_transaction_id', $existing->public_id)->first();
                    if (!$hold || $existing->reference_type !== 'WALLET_HOLD_RESERVE'
                        || $existing->status !== TransactionStatus::COMPLETADA
                        || strtolower($hold->wallet_id) !== strtolower($lockedWallet->public_id)
                        || $hold->amount_cents !== $amountCents || $hold->operation_type !== $operationType
                        || !$hold->expires_at->equalTo($expiry) || $hold->requested_by !== $actorId
                        || $hold->reason !== $reason || $hold->reference_type !== $referenceType
                        || $hold->reference_id !== $referenceId) {
                        throw new InvalidArgumentException('La clave de idempotencia pertenece a otra solicitud.');
                    }
                    return $hold;
                }
                $policies = config('financial.holds.max_seconds', []);
                $maxSeconds = is_array($policies) ? ($policies[$operationType] ?? null) : null;
                if (!is_int($maxSeconds) || $maxSeconds <= 0) {
                    throw new InvalidArgumentException('No hay una duración máxima configurada para esta operación.');
                }
                $now = CarbonImmutable::now()->startOfSecond();
                if ($expiry->lessThanOrEqualTo($now) || $expiry->greaterThan($now->addSeconds($maxSeconds))) {
                    throw new InvalidArgumentException('La vigencia debe ser futura y respetar la duración máxima configurada.');
                }
                $id = (string) Str::uuid();
                $transaction = app(LedgerService::class)->moveHeldFunds(
                    $lockedWallet, $amountCents, $idempotencyKey, $id, $actorId, 'RESERVE'
                );
                return WalletHold::create([
                    'public_id' => $id, 'wallet_id' => $lockedWallet->public_id,
                    'amount_cents' => $amountCents, 'currency' => $lockedWallet->currency,
                    'operation_type' => $operationType, 'expires_at' => $expiry,
                    'reference_type' => $referenceType, 'reference_id' => $referenceId,
                    'status' => WalletHoldStatus::ACTIVA, 'requested_by' => $actorId,
                    'reason' => $reason, 'hold_transaction_id' => $transaction->public_id,
                ]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new InvalidArgumentException('La clave de idempotencia pertenece a otra operación concurrente.', 0, $exception);
        }
    }

    public function release(WalletHold $hold, string $key, string $actor, string $reason): WalletHold
    {
        return $this->close($hold, $key, $actor, $reason, false);
    }

    /** Full capture; this debits reserved value, not the available bucket a second time. */
    public function capture(WalletHold $hold, string $key, string $actor, string $reason): WalletHold
    {
        return $this->close($hold, $key, $actor, $reason, true);
    }

    private function close(
        WalletHold $hold, string $key, string $actor, string $reason,
        bool $capture, bool $expireOnly = false
    ): WalletHold {
        $this->assertInput($key, $actor, $reason);
        // Obtain the canonical wallet, then use the same wallet -> hold lock order everywhere.
        $current = WalletHold::where('public_id', $hold->public_id)->firstOrFail();
        try {
            return DB::connection('sqlsrv')->transaction(function () use (
                $current, $key, $actor, $reason, $capture, $expireOnly
            ) {
                $wallet = Wallet::where('public_id', $current->wallet_id)->lockForUpdate()->firstOrFail();
                $locked = WalletHold::where('public_id', $current->public_id)->lockForUpdate()->firstOrFail();
                if (strtolower($locked->wallet_id) !== strtolower($wallet->public_id)
                    || $locked->currency !== $wallet->currency) {
                    throw new InvalidArgumentException('La retención no corresponde a la wallet bloqueada.');
                }
                $mode = $capture ? 'CAPTURE' : 'RELEASE';
                if ($locked->status !== WalletHoldStatus::ACTIVA) {
                    $transaction = FinancialTransaction::where('public_id', $locked->closing_transaction_id)->first();
                    if (!$transaction || $transaction->status !== TransactionStatus::COMPLETADA
                        || $transaction->idempotency_key !== $key
                        || $transaction->reference_type !== 'WALLET_HOLD_' . $mode
                        || strtolower((string) $transaction->reference_id) !== strtolower($locked->public_id)
                        || ($transaction->metadata['amount_cents'] ?? null) !== $locked->amount_cents
                        || $locked->closed_by !== $actor || $locked->closing_reason !== $reason) {
                        throw new InvalidArgumentException('La retención ya fue cerrada con otra operación o clave.');
                    }
                    return $locked;
                }
                $expired = $locked->expires_at->lessThanOrEqualTo(now());
                if (($capture && $expired) || ($expireOnly && !$expired)) {
                    throw new InvalidArgumentException('La retención no está vigente para esta operación.');
                }
                $transaction = app(LedgerService::class)->moveHeldFunds(
                    $wallet, $locked->amount_cents, $key, $locked->public_id, $actor, $mode
                );
                $locked->status = $capture ? WalletHoldStatus::CAPTURADA
                    : ($expired ? WalletHoldStatus::EXPIRADA : WalletHoldStatus::LIBERADA);
                $locked->closing_transaction_id = $transaction->public_id;
                $locked->closed_by = $actor;
                $locked->closing_reason = $reason;
                $locked->closed_at = now();
                $locked->save();
                return $locked;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new InvalidArgumentException('La clave de idempotencia pertenece a otra operación concurrente.', 0, $exception);
        }
    }

    public function expireDue(): int
    {
        $count = 0;
        WalletHold::where('status', WalletHoldStatus::ACTIVA->value)->where('expires_at', '<=', now())
            ->chunkById(100, function ($holds) use (&$count) {
                foreach ($holds as $hold) {
                    try {
                        $closed = $this->close($hold, 'hold-expire:' . strtolower($hold->public_id),
                            'SYSTEM:HOLD_EXPIRATION', 'Liberación por vencimiento.', false, true);
                        if ($closed->wasChanged('status')) {
                            $count++;
                        }
                    } catch (InvalidArgumentException $exception) {
                        if ($hold->fresh()?->status === WalletHoldStatus::ACTIVA) {
                            throw $exception;
                        }
                    }
                }
            });
        return $count;
    }

    private function assertInput(string $key, string $actor, string $reason): void
    {
        if (trim($key) === '' || strlen($key) > 255 || trim($actor) === '' || strlen($actor) > 255
            || trim($reason) === '' || mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException('Se requieren clave de idempotencia, actor y motivo válidos.');
        }
    }
}
