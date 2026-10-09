<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\CashRegisterStatus;
use App\Domains\Financial\Enums\CashShiftStatus;
use App\Domains\Financial\Models\CashRegister;
use App\Domains\Financial\Models\CashRegisterChange;
use App\Domains\Financial\Models\CashShift;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CashRegisterAdministrationService
{
    public function __construct(private readonly CashShiftService $shifts) {}

    public function create(string $association, string $name, string $currency, string $actor, string $reason, string $key): array
    {
        $this->validate($actor, $reason, $key);
        $this->shifts->text($association); $this->shifts->text($name, 100);
        if ($association !== trim($association)) throw new InvalidArgumentException('El identificador de asociación no debe tener espacios externos.');
        $name = trim($name); $currency = strtoupper(trim($currency));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) throw new InvalidArgumentException('La moneda debe contener tres letras.');
        $hash = $this->hash(['CREATE', $association, $name, $currency, trim($actor), trim($reason)]);
        return DB::connection('sqlsrv')->transaction(function () use ($association, $name, $currency, $actor, $reason, $key, $hash) {
            if ($prior = $this->replay($key, $hash)) return $prior;
            $register = $this->shifts->createRegister($association, $name, $currency, $actor)->fresh();
            $after = $this->snapshot($register);
            $this->record($register, 'CREATE', null, $after, $actor, $reason, $key, $hash);
            return $after;
        });
    }

    public function update(string $association, string $registerId, string $name, CashRegisterStatus $status,
        int $expectedVersion, string $actor, string $reason, string $key): array
    {
        $this->validate($actor, $reason, $key); $this->shifts->text($name, 100);
        if ($expectedVersion < 1) throw new InvalidArgumentException('La versión debe ser positiva.');
        $name = trim($name);
        $hash = $this->hash(['UPDATE', $association, strtolower($registerId), $name, $status->value, $expectedVersion, trim($actor), trim($reason)]);
        return DB::connection('sqlsrv')->transaction(function () use ($association, $registerId, $name, $status, $expectedVersion, $actor, $reason, $key, $hash) {
            // Same register-first lock order as opening, settling and closing a shift.
            $register = CashRegister::where('association_id', $association)->where('public_id', $registerId)->lockForUpdate()->firstOrFail();
            if ($prior = $this->replay($key, $hash)) return $prior;
            if ($register->version !== $expectedVersion) throw new InvalidArgumentException('La caja cambió. Actualiza sus datos antes de guardar.');
            if ($status === CashRegisterStatus::INACTIVE && CashShift::where('cash_register_id', $register->id)->where('status', CashShiftStatus::OPEN)->exists())
                throw new InvalidArgumentException('No se puede desactivar una caja con un turno abierto.');
            if ($register->name === $name && $register->status === $status) throw new InvalidArgumentException('No hay cambios que guardar.');
            $before = $this->snapshot($register);
            $register->fill(['name' => $name, 'status' => $status, 'version' => $register->version + 1])->save();
            $after = $this->snapshot($register);
            $this->record($register, 'UPDATE', $before, $after, $actor, $reason, $key, $hash);
            return $after;
        });
    }

    public function snapshot(CashRegister $register): array
    {
        return ['id' => strtolower($register->public_id), 'association_id' => $register->association_id,
            'name' => $register->name, 'currency' => $register->currency, 'status' => $register->status->value,
            'version' => $register->version, 'created_by' => $register->created_by];
    }

    private function validate(string $actor, string $reason, string $key): void
    {
        $this->shifts->text($actor); $this->shifts->text($key); $this->shifts->text($reason, 1000);
    }

    private function hash(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    private function replay(string $key, string $hash): ?array
    {
        $prior = CashRegisterChange::where('idempotency_key', trim($key))->first();
        if ($prior && $prior->request_hash !== $hash) throw new InvalidArgumentException('La clave pertenece a otra solicitud de administración de caja.');
        return $prior?->after_state;
    }

    private function record(CashRegister $register, string $action, ?array $before, array $after,
        string $actor, string $reason, string $key, string $hash): void
    {
        CashRegisterChange::create(['public_id' => (string) Str::uuid(), 'cash_register_id' => $register->id,
            'version' => $register->version, 'action' => $action, 'before_state' => $before, 'after_state' => $after,
            'actor_id' => trim($actor), 'reason' => trim($reason), 'idempotency_key' => trim($key), 'request_hash' => $hash]);
    }
}
