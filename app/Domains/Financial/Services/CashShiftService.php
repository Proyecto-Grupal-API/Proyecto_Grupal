<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\CashMovementType;
use App\Domains\Financial\Enums\CashRegisterStatus;
use App\Domains\Financial\Enums\CashShiftStatus;
use App\Domains\Financial\Models\CashMovement;
use App\Domains\Financial\Models\CashRegister;
use App\Domains\Financial\Models\CashShift;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CashShiftService
{
    public function createRegister(string $associationId, string $name, string $currency, string $actor): CashRegister
    {
        $this->text($associationId); $this->text($name, 100); $this->text($actor);
        $currency = strtoupper(trim($currency));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) throw new InvalidArgumentException('La moneda debe contener tres letras.');
        return CashRegister::create(['public_id' => (string) Str::uuid(), 'association_id' => trim($associationId),
            'name' => trim($name), 'currency' => $currency, 'status' => CashRegisterStatus::ACTIVE, 'created_by' => trim($actor)]);
    }

    public function open(string $cashRegisterPublicId, string $agentId, int $openingAmountCents, string $key): CashShift
    {
        $this->text($agentId); $this->text($key);
        if ($openingAmountCents < 0) throw new InvalidArgumentException('El fondo inicial no puede ser negativo.');
        return DB::connection('sqlsrv')->transaction(function () use ($cashRegisterPublicId, $agentId, $openingAmountCents, $key) {
            $register = CashRegister::where('public_id', $cashRegisterPublicId)->lockForUpdate()->firstOrFail();
            $existing = CashShift::where('opening_key', trim($key))->first();
            if ($existing) {
                if ($existing->cash_register_id !== $register->id || $existing->agent_id !== trim($agentId) || $existing->opening_amount_cents !== $openingAmountCents)
                    throw new InvalidArgumentException('La clave de apertura pertenece a otra solicitud.');
                return $existing;
            }
            if ($register->status !== CashRegisterStatus::ACTIVE) throw new InvalidArgumentException('La caja no está activa.');
            if (CashShift::where('cash_register_id', $register->id)->where('status', CashShiftStatus::OPEN)->exists())
                throw new InvalidArgumentException('La caja ya tiene un turno abierto.');
            return CashShift::create(['public_id' => (string) Str::uuid(), 'cash_register_id' => $register->id,
                'agent_id' => trim($agentId), 'opening_amount_cents' => $openingAmountCents, 'opening_key' => trim($key),
                'status' => CashShiftStatus::OPEN, 'opened_at' => now()])->fresh();
        });
    }

    // These are physical cash events. They never modify a wallet or impersonate settlements.
    public function addMovement(string $shiftId, CashMovementType $type, int $amount, string $key, string $actor, string $reason,
        ?string $referenceType = null, ?string $referenceId = null): CashMovement
    {
        if (! in_array($type, [CashMovementType::CASH_IN, CashMovementType::CASH_OUT, CashMovementType::ADJUSTMENT], true))
            throw new InvalidArgumentException('Recargas y retiros requieren el servicio de liquidación de caja.');
        if ($amount === 0 || ($type !== CashMovementType::ADJUSTMENT && $amount < 0)) throw new InvalidArgumentException('El importe del movimiento no es válido.');
        $this->text($key); $this->text($actor); $this->text($reason, 1000);
        if ($referenceType !== null) $this->text($referenceType, 50);
        if ($referenceId !== null) $this->text($referenceId, 100);
        $hash = $this->hash([$shiftId, $type->value, $amount, trim($actor), trim($reason), $referenceType, $referenceId]);
        return DB::connection('sqlsrv')->transaction(function () use ($shiftId, $type, $amount, $key, $actor, $reason, $referenceType, $referenceId, $hash) {
            $shift = $this->lockShift($shiftId);
            $existing = $this->replay($key, $hash);
            if ($existing) return $existing;
            $this->assertOpen($shift);
            $expected = $this->getSummary($shiftId)['expected_amount_cents'];
            $delta = $type === CashMovementType::CASH_OUT ? -$amount : $amount;
            if ($expected + $delta < 0) throw new InvalidArgumentException('No hay efectivo suficiente en el turno.');
            $movement = CashMovement::create(['public_id' => (string) Str::uuid(), 'cash_shift_id' => $shift->id,
                'type' => $type, 'amount_cents' => $amount, 'idempotency_key' => trim($key), 'request_hash' => $hash,
                'actor_id' => trim($actor), 'reason' => trim($reason), 'reference_type' => $referenceType, 'reference_id' => $referenceId])->fresh();
            app(CashReceiptService::class)->issue($movement);
            return $movement;
        });
    }

    public function getSummary(string $shiftId): array
    {
        $shift = CashShift::where('public_id', $shiftId)->firstOrFail();
        if ($shift->status === CashShiftStatus::CLOSED) {
            return ['shift' => $shift, 'opening_amount_cents' => $shift->opening_amount_cents,
                'cash_in_cents' => $shift->cash_in_cents, 'cash_out_cents' => $shift->cash_out_cents,
                'adjustment_cents' => $shift->adjustment_cents, 'expected_amount_cents' => $shift->expected_amount_cents,
                'counted_amount_cents' => $shift->counted_amount_cents, 'difference_cents' => $shift->difference_cents];
        }
        return $this->calculate($shift);
    }

    public function calculate(CashShift $shift): array
    {
        $sums = $shift->movements()->select('type')->selectRaw('SUM(amount_cents) AS total')->groupBy('type')->get()->keyBy(fn ($row) => $row->type->value);
        $sum = fn (string $type) => (int) ($sums->get($type)?->total ?? 0);
        $in = $sum('CASH_IN') + $sum('TOPUP') + $sum('WITHDRAWAL_RECOVERY'); $out = $sum('CASH_OUT') + $sum('WITHDRAWAL'); $adjust = $sum('ADJUSTMENT');
        return ['shift' => $shift, 'opening_amount_cents' => $shift->opening_amount_cents, 'cash_in_cents' => $in,
            'cash_out_cents' => $out, 'adjustment_cents' => $adjust,
            'expected_amount_cents' => $shift->opening_amount_cents + $in - $out + $adjust];
    }

    public function close(string $shiftId, int $counted, string $key, string $actor, string $reason): array
    {
        $this->text($key); $this->text($actor); $this->text($reason, 1000);
        if ($counted < 0) throw new InvalidArgumentException('El efectivo contado no puede ser negativo.');
        return DB::connection('sqlsrv')->transaction(function () use ($shiftId, $counted, $key, $actor, $reason) {
            $shift = $this->lockShift($shiftId);
            if ($shift->status === CashShiftStatus::CLOSED) {
                if ($shift->closing_key === trim($key) && $shift->counted_amount_cents === $counted && $shift->closed_by === trim($actor) && $shift->closing_reason === trim($reason)) return $this->getSummary($shiftId);
                throw new InvalidArgumentException('El turno ya está cerrado; el reintento debe conservar la solicitud original.');
            }
            if (CashShift::where('closing_key', trim($key))->exists()) throw new InvalidArgumentException('La clave de cierre ya fue utilizada.');
            $summary = $this->calculate($shift);
            $shift->fill(array_intersect_key($summary, array_flip(['cash_in_cents', 'cash_out_cents', 'adjustment_cents', 'expected_amount_cents'])));
            $shift->fill(['counted_amount_cents' => $counted, 'difference_cents' => $counted - $summary['expected_amount_cents'],
                'status' => CashShiftStatus::CLOSED, 'closed_at' => now(), 'closing_key' => trim($key), 'closed_by' => trim($actor), 'closing_reason' => trim($reason)])->save();
            return $this->getSummary($shiftId);
        });
    }

    public function lockShift(string $shiftId): CashShift
    {
        $shift = CashShift::where('public_id', $shiftId)->firstOrFail();
        CashRegister::where('id', $shift->cash_register_id)->lockForUpdate()->firstOrFail();
        return CashShift::where('id', $shift->id)->lockForUpdate()->firstOrFail();
    }
    public function assertOpen(CashShift $shift): void
    {
        if ($shift->status !== CashShiftStatus::OPEN) throw new InvalidArgumentException('El turno está cerrado.');
    }
    public function replay(string $key, string $hash): ?CashMovement
    {
        $existing = CashMovement::where('idempotency_key', trim($key))->first();
        if ($existing && $existing->request_hash !== $hash) throw new InvalidArgumentException('La clave pertenece a otro movimiento de caja.');
        return $existing;
    }
    public function hash(array $values): string
    {
        $values[0] = strtolower($values[0]);
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }
    public function text(string $value, int $max = 255): void
    {
        if (trim($value) === '' || mb_strlen($value) > $max) throw new InvalidArgumentException('El identificador, actor o motivo es obligatorio y debe respetar su longitud.');
    }
}
