<?php

namespace App\Services;

use App\Contracts\BonusAuthorization;
use App\Models\AcademicProgram;
use App\Models\Campus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;

/** Read-only financial infrastructure. No grants, issuance, actor authentication or public API. */
class FinancialAuthorizationService implements BonusAuthorization
{
    public const BONUS_CAPABILITIES = [
        'BECA' => 'bonuses.beca.issue',
        'CAMPANIA' => 'bonuses.campania.issue',
        'BENEFICIO' => 'bonuses.beneficio.issue',
        'CATEGORIA' => 'bonuses.categoria.issue',
        'NEGOCIO' => 'bonuses.negocio.issue',
    ];

    public function __construct(private EffectiveRoleAssignments $assignments, private BusinessAuthorizationService $identities) {}

    protected function roleScopes(): array
    {
        return Role::FINANCIAL_ROLE_SCOPES;
    }

    protected function mappings(): array
    {
        return RolePermission::FINANCIAL_MAPPINGS;
    }

    public function allows(?string $subjectId, string $bonusType, string $operation, ?string $scopeType = null, ?string $scopeId = null): bool
    {
        $capability = self::BONUS_CAPABILITIES[$bonusType] ?? null;
        if ($operation !== 'issue' || $capability === null
            || (($scopeType === null) !== ($scopeId === null))
            || ($scopeType !== null && ! (($bonusType === 'NEGOCIO' && $scopeType === 'business')
                || ($bonusType === 'BECA' && in_array($scopeType, ['academic_program', 'council'], true))))) {
            return false;
        }
        $id = $scopeType === null ? null : $this->identities->businessId($scopeId);
        $userId = $this->identities->businessId($subjectId);
        if ($userId === null || ($scopeType !== null && $id === null)) {
            return false;
        }
        $user = $this->identities->validUser(User::whereKey($userId)->first());
        if (! $user || ! Permission::where('key', $capability)->where('domain', 'financial')->where('active', true)->exists()) {
            return false;
        }
        if ($scopeType === 'council' && (! preg_match('/\A[0-9a-f]{24}\z/', $id)
            || ! \App\Models\Organizacion::whereKey($id)->where('tipo', 'consejo')->where('estado', 'activa')->exists())) {
            return false;
        }
        if ($scopeType === 'business' && ! $this->identities->hasMembership($user, $id)) {
            return false;
        }
        $program = $scopeType === 'academic_program' ? AcademicProgram::whereKey($id)->where('is_active', true)->first() : null;
        if ($scopeType === 'academic_program' && (! $program
            || ! Campus::whereKey($program->campus_id)->where('is_active', true)->exists())) {
            return false;
        }
        if ($program) {
            $id = (string) $program->getKey();
        }
        $matrix = $this->mappings();
        $roles = array_keys(array_filter($this->roleScopes(), fn ($scope, $role) => $scope === $scopeType
            && in_array($capability, $matrix[$role] ?? [], true), ARRAY_FILTER_USE_BOTH));
        $catalog = Role::whereIn('name', $roles)->pluck('name')->all();
        $query = $this->assignments->queryForRoles($user, $catalog)
            ->where('scope_type', $scopeType)->where('scope_id', $id)
            ->whereRaw(['scope_type' => ['$exists' => true], 'scope_id' => ['$exists' => true]]);
        if ($scopeType === 'council') {
            $query->whereNull('revoked_at');
        }
        if ($program) {
            // Preserve the original assignment binding: a moved program cannot inherit a grant.
            $query->where('campus_id', (string) $program->campus_id);
        }
        $effective = $query->pluck('role_key')->all();

        return RolePermission::whereIn('role_key', $effective)->where('permission_key', $capability)->exists();
    }
}
