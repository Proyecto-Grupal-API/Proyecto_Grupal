<?php
namespace App\Domains\Financial\Services;
use App\Domains\Financial\Contracts\TransferAuthorizationProvider;
use App\Domains\Financial\Models\StudentTransferPolicy;
use App\Domains\Financial\Models\StudentTransferPolicyChange;
use DateTimeZone;
use App\Domains\Financial\Data\TransferAuditContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
class StudentTransferPolicyService
{
    public function __construct(private readonly TransferAuthorizationProvider $authorization) {}
    public function current(): StudentTransferPolicy { return StudentTransferPolicy::where('policy_key', 'STUDENT_MXN')->firstOrFail(); }
    public function snapshot(StudentTransferPolicy $p): array
    {
        return $p->only(['policy_key', 'enabled', 'minimum_cents', 'maximum_cents', 'daily_cents', 'monthly_cents', 'business_timezone', 'version', 'confirmation_seconds']);
    }
    public function update(string $userId, int $expectedVersion, array $changes, string $reason, ?TransferAuditContext $audit = null): StudentTransferPolicy
    {
        if (!$this->authorization->canManagePolicy($userId)) { throw new AuthorizationException('No puedes administrar las políticas de transferencias.'); }
        if (!preg_match('/^[a-f0-9]{24}$/i', $userId) || trim($reason) === '' || mb_strlen($reason) > 1000 || $expectedVersion < 1
            || $changes === [] || array_diff(array_keys($changes), ['enabled', 'minimum_cents', 'maximum_cents', 'daily_cents', 'monthly_cents', 'business_timezone', 'confirmation_seconds'])) {
            throw new InvalidArgumentException('Responsable, motivo, versión o cambios no válidos.');
        }
        return DB::connection('sqlsrv')->transaction(function () use ($userId, $expectedVersion, $changes, $reason, $audit) {
            $p = StudentTransferPolicy::where('policy_key', 'STUDENT_MXN')->lockForUpdate()->firstOrFail();
            if ($p->version !== $expectedVersion) { throw new InvalidArgumentException('La política cambió; consulta la versión vigente.'); }
            // A timezone change cannot redefine already-counted periods in the active month.
            if (isset($changes['business_timezone']) && $changes['business_timezone'] !== $p->business_timezone) {
                throw new InvalidArgumentException('La zona contable no puede cambiarse mediante una actualización de límites.');
            }
            $before = $this->snapshot($p); $merged = array_replace($before, $changes); $this->validate($merged);
            $p->fill($changes);
            if (!$p->isDirty(array_keys($changes))) { return $p; }
            if ($p->version >= 2147483647) { throw new InvalidArgumentException('Se alcanzó el límite técnico de versiones.'); }
            $p->version++; $p->updated_by = 'user:' . strtolower($userId); $p->save();
            StudentTransferPolicyChange::create(['public_id' => (string) Str::uuid(), 'policy_key' => $p->policy_key,
                'version' => $p->version, 'actor_id' => $p->updated_by, 'reason' => trim($reason),
                'audit_context' => ($audit ?? TransferAuditContext::internal())->toArray(), 'before_data' => $before, 'after_data' => $this->snapshot($p)]);
            return $p;
        });
    }
    public function validate(array $data): void
    {
        if (!is_int($data['confirmation_seconds'] ?? null) || $data['confirmation_seconds'] < 1 || $data['confirmation_seconds'] > 2147483647) {
            throw new InvalidArgumentException('El plazo de confirmación debe ser un entero positivo de segundos.');
        }
        foreach (['minimum_cents', 'maximum_cents', 'daily_cents', 'monthly_cents'] as $f) {
            if (!is_int($data[$f] ?? null) || $data[$f] < 1 || $data[$f] > 9007199254740991) { throw new InvalidArgumentException('Los límites deben ser enteros positivos de centavos.'); }
        }
        if (!is_bool($data['enabled'] ?? null) || $data['minimum_cents'] > $data['maximum_cents']
            || $data['maximum_cents'] > $data['daily_cents'] || $data['daily_cents'] > $data['monthly_cents']
            || !in_array($data['business_timezone'] ?? '', DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('La política contiene límites o zona contable inconsistentes.');
        }
    }
}
