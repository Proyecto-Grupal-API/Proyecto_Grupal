<?php

namespace App\Services;

use App\Models\AcademicProgram;
use App\Models\AuthorizationLifecycleRecord;
use App\Models\Campus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Internal institutional decisions only; this service neither grants roles nor authenticates actors. */
final class InstitutionalAuthorizationService
{
    public function __construct(private EffectiveRoleAssignments $assignments) {}

    public function allows(?User $subject, string $capability, ?string $scopeType, ?string $scopeId): bool
    {
        if (! in_array($scopeType, ['campus', 'academic_program'], true)
            || ! isset(Permission::CATALOG[$capability]) || Permission::CATALOG[$capability][1] !== 'institutional'
            || ! $subject?->exists || $subject->trashed() || $subject->getKey() === null) {
            return false;
        }
        $user = User::whereKey($subject->getKey())->first();
        if (! $user || $user->account_activation_pending === true || $user->must_change_password === true) {
            return false;
        }
        try {
            $id = AuthorizationLifecycleRecord::normalizeIdentifier($scopeId, 'scope_id');
        } catch (ValidationException) {
            return false;
        }
        // Revalidate canonical catalogs at decision time, including the program's original campus binding.
        $program = $scopeType === 'academic_program' ? AcademicProgram::whereKey($id)->where('is_active', true)->first() : null;
        if ($scopeType === 'academic_program' && ! $program) {
            return false;
        }
        $campus = Campus::whereKey($program ? $program->campus_id : $id)->where('is_active', true)->first();
        if (! $campus || ! Permission::where('key', $capability)->where('domain', 'institutional')->where('active', true)->exists()) {
            return false;
        }
        $id = (string) ($program ?? $campus)->getKey();
        $catalog = array_keys(array_filter(Role::FOUNDATION_ROLE_SCOPES, fn ($scope) => $scope === $scopeType));
        $roles = Role::whereIn('name', $catalog)->pluck('name')->all();
        $query = $this->assignments->queryForRoles($user, $roles)->where('scope_type', $scopeType)->where('scope_id', $id);
        if ($program) {
            $query->where('campus_id', (string) $campus->getKey());
        }
        $effective = $query->pluck('role_key')->all();

        // Storage grants are required; the versioned matrix rejects unapproved persisted mappings.
        return RolePermission::whereIn('role_key', $effective)->where('permission_key', $capability)->get()
            ->contains(fn ($mapping) => in_array($capability, RolePermission::MAPPINGS[$mapping->role_key] ?? [], true));
    }
}
