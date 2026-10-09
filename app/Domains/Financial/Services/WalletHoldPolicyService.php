<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Models\WalletHoldPolicy;
use App\Domains\Financial\Models\WalletHoldPolicyChange;
use App\Domains\Financial\Support\FinancialCorrelation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WalletHoldPolicyService
{
    public function create(string $operationType, int $maxSeconds, bool $active, string $actor, string $reason): WalletHoldPolicy
    {
        $this->assertAudit($actor, $reason);
        $this->assertDuration($maxSeconds);
        if (!preg_match('/^[A-Z][A-Z0-9_]{0,99}$/', $operationType)) {
            throw new InvalidArgumentException('El tipo de operación no es válido.');
        }
        try {
            return DB::connection('sqlsrv')->transaction(function () use ($operationType, $maxSeconds, $active, $actor, $reason) {
                $policy = WalletHoldPolicy::create([
                    'public_id' => (string) Str::uuid(), 'operation_type' => $operationType,
                    'max_duration_seconds' => $maxSeconds, 'active' => $active, 'version' => 1,
                    'created_by' => $actor, 'updated_by' => $actor,
                ]);
                $this->record($policy, null, $actor, $reason);
                return $policy;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new InvalidArgumentException('Ya existe una política para este tipo de operación.', 0, $exception);
        }
    }

    public function update(WalletHoldPolicy $policy, int $expectedVersion, array $changes, string $actor, string $reason): WalletHoldPolicy
    {
        $this->assertAudit($actor, $reason);
        if ($expectedVersion < 1 || array_diff(array_keys($changes), ['max_duration_seconds', 'active']) !== [] || $changes === []) {
            throw new InvalidArgumentException('Se requieren una versión y cambios válidos.');
        }
        if (array_key_exists('max_duration_seconds', $changes)) {
            if (!is_int($changes['max_duration_seconds'])) {
                throw new InvalidArgumentException('La duración debe ser un entero de segundos.');
            }
            $this->assertDuration($changes['max_duration_seconds']);
        }
        if (array_key_exists('active', $changes) && !is_bool($changes['active'])) {
            throw new InvalidArgumentException('El estado activo debe ser booleano.');
        }
        return DB::connection('sqlsrv')->transaction(function () use ($policy, $expectedVersion, $changes, $actor, $reason) {
            $locked = WalletHoldPolicy::where('public_id', $policy->public_id)->lockForUpdate()->firstOrFail();
            if ($locked->version !== $expectedVersion) {
                throw new InvalidArgumentException('La política fue modificada; consulta su versión vigente antes de cambiarla.');
            }
            $before = $this->snapshot($locked);
            $locked->fill($changes);
            if (!$locked->isDirty(['max_duration_seconds', 'active'])) {
                return $locked;
            }
            if ($locked->version >= 2147483647) {
                throw new InvalidArgumentException('Se alcanzó el límite técnico de versiones.');
            }
            $locked->version++;
            $locked->updated_by = $actor;
            $locked->save();
            $this->record($locked, $before, $actor, $reason);
            return $locked;
        });
    }

    /** Called inside the hold transaction: serialize creation against policy updates. */
    public function forOperation(string $operationType): WalletHoldPolicy
    {
        $policy = WalletHoldPolicy::where('operation_type', $operationType)->lockForUpdate()->first();
        if (!$policy || !$policy->active) {
            throw new InvalidArgumentException('No hay una política de retención activa para esta operación.');
        }
        $this->assertDuration($policy->max_duration_seconds);
        return $policy;
    }

    private function assertDuration(int $seconds): void
    {
        $ceiling = config('financial.holds.absolute_max_seconds', 2147483647);
        if (!is_int($ceiling) || $ceiling < 1 || $ceiling > 2147483647) {
            throw new InvalidArgumentException('El límite de seguridad de duración no está configurado correctamente.');
        }
        if ($seconds < 1 || $seconds > $ceiling) {
            throw new InvalidArgumentException('La duración debe ser positiva y no superar el límite de seguridad configurado.');
        }
    }

    private function assertAudit(string $actor, string $reason): void
    {
        if (trim($actor) === '' || mb_strlen($actor) > 255 || trim($reason) === '' || mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException('El responsable y el motivo del cambio son obligatorios.');
        }
    }

    private function snapshot(WalletHoldPolicy $policy): array
    {
        return ['operation_type' => $policy->operation_type, 'max_duration_seconds' => $policy->max_duration_seconds,
            'active' => $policy->active, 'version' => $policy->version];
    }

    private function record(WalletHoldPolicy $policy, ?array $before, string $actor, string $reason): void
    {
        WalletHoldPolicyChange::create([
            'public_id' => (string) Str::uuid(), 'policy_id' => $policy->public_id, 'version' => $policy->version,
            'change_type' => $before === null ? 'CREACION' : 'MODIFICACION',
            'actor_id' => $actor, 'reason' => $reason, 'correlation_id' => app(FinancialCorrelation::class)->id(),
            'before' => $before, 'after' => $this->snapshot($policy),
        ]);
    }
}
