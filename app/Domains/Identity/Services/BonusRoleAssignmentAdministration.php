<?php
namespace App\Domains\Identity\Services;
use App\Domains\Identity\Authorization\FinancialRoleCatalog as Catalog;
use App\Domains\Identity\Contracts\ScopedCapabilityAuthorization;
use App\Models\BusinessMembership;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
final class BonusRoleAssignmentAdministration
{
    public function __construct(private readonly ScopedCapabilityAuthorization $authorization) {}
    private function authorize(string $actorId): void
    { if (!$this->authorization->authorized($actorId, 'identity.bonus_roles.manage')) { throw new AuthorizationException(); } }
    public function assign(string $actorId, array $data, ?array $audit = null): RoleAssignment
    {
        $this->authorize($actorId);
        return DB::connection('mongodb')->transaction(function () use ($actorId, $data, $audit) {
            $this->authorize($actorId); $role = $data['role_key'];
            if (!isset(Catalog::ROLES[$role]) || ($data['scope_type'] ?? null) !== Catalog::ROLES[$role][1]
                || (($data['scope_type'] ?? null) === null) !== (($data['scope_id'] ?? null) === null)) { throw new InvalidArgumentException('El rol requiere su ámbito exacto.'); }
            $target = User::whereKey(strtolower($data['user_id']))->whereNull('deleted_at')->first();
            if (!$target || $target->account_activation_pending || $target->must_change_password || !$target->hasVerifiedEmail()) { throw new InvalidArgumentException('La cuenta destinataria debe estar activa y verificada.'); }
            $id = (string)$target->getKey();
            if ($role === 'admin' && $id === $actorId) { throw new AuthorizationException('La administración de tu propio rol global requiere otro administrador.'); }
            $type = $data['scope_type'] ?? null; $scope = $data['scope_id'] ?? null;
            $existing = RoleAssignment::where('user_id', $id)->where('role_key', $role)->where('scope_type', $type)->where('scope_id', $scope)->where('is_current', true)->first();
            if ($existing) { throw new InvalidArgumentException('Existe una asignación vigente o suspendida; revócala antes de crear otra generación.'); }
            if ($type === 'business') {
                $membership = BusinessMembership::where('user_id', $id)->where('business_id', $scope)->where('is_current', true)->first();
                if (!$membership) { BusinessMembership::create(['user_id' => $id, 'business_id' => $scope, 'status' => 'active', 'assigned_by' => $actorId, 'reason' => trim($data['reason']), 'origin' => 'bonus_role_administration']); }
                elseif ($membership->status !== 'active' || ($membership->starts_at && $membership->starts_at->gt(now())) || ($membership->ends_at && $membership->ends_at->lte(now()))) { throw new InvalidArgumentException('La membresía de negocio está suspendida, pendiente o vencida.'); }
            }
            return RoleAssignment::create(['user_id' => $id, 'role_key' => $role, 'scope_type' => $type, 'scope_id' => $scope,
                'status' => 'active', 'starts_at' => $data['starts_at'] ?? null, 'ends_at' => $data['ends_at'] ?? null,
                'assigned_by' => $actorId, 'reason' => trim($data['reason']), 'origin' => 'bonus_role_administration', 'assignment_audit' => $audit]);
        });
    }
    public function revoke(string $actorId, string $assignmentId, string $reason, ?array $audit = null): RoleAssignment
    {
        $this->authorize($actorId);
        return DB::connection('mongodb')->transaction(function () use ($actorId, $assignmentId, $reason, $audit) {
            $this->authorize($actorId); $record = RoleAssignment::whereKey($assignmentId)->firstOrFail();
            if (!isset(Catalog::ROLES[$record->role_key]) || !$record->is_current) { throw new InvalidArgumentException('La asignación no pertenece a este catálogo o ya fue revocada.'); }
            if ($record->role_key === 'admin' && $record->user_id === $actorId) { throw new AuthorizationException('Tu rol global debe revocarlo otro administrador.'); }
            $record->transitionTo('revoked', $actorId, trim($reason), $audit); return $record;
        });
    }
}
