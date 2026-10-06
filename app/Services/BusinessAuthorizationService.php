<?php

namespace App\Services;

use App\Models\AuthorizationLifecycleRecord;
use App\Models\BusinessMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Internal business decisions only; authentication and external subject binding are separate boundaries. */
final class BusinessAuthorizationService
{
    public function __construct(private EffectiveRoleAssignments $assignments) {}

    public function validUser(?User $user): ?User
    {
        if (! $user?->exists || $user->trashed() || $user->getKey() === null) {
            return null;
        }

        // Re-read identity so a stale caller object cannot authorize a deleted subject.
        $current = User::whereKey($user->getKey())->first();

        // Existing initial-access gates also apply to business operations, not just interactive routes.
        return $current && $current->account_activation_pending !== true && $current->must_change_password !== true
            ? $current : null;
    }

    public function businessId(?string $id): ?string
    {
        try {
            return AuthorizationLifecycleRecord::normalizeIdentifier($id, 'business_id');
        } catch (ValidationException) {
            return null;
        }
    }

    public function businessRoles(): array
    {
        return array_keys(array_filter(Role::FOUNDATION_ROLE_SCOPES, fn ($scope) => $scope === 'business'));
    }

    public function hasMembership(User $user, string $businessId): bool
    {
        return $this->assignments->activeCurrent(BusinessMembership::where('user_id', (string) $user->getKey())
            ->where('business_id', $businessId))->exists();
    }

    public function effectiveRoles(?User $subject, ?string $businessId): array
    {
        $user = $this->validUser($subject);
        $id = $this->businessId($businessId);
        if (! $user || $id === null || ! $this->hasMembership($user, $id)) {
            return [];
        }
        $catalog = Role::whereIn('name', $this->businessRoles())->pluck('name')->all();

        return $this->assignments->queryForRoles($user, $catalog)
            ->where('scope_type', 'business')->where('scope_id', $id)->pluck('role_key')->all();
    }

    public function allows(?User $subject, string $capability, ?string $scopeType, ?string $scopeId): bool
    {
        if ($scopeType !== 'business' || ! isset(Permission::CATALOG[$capability])
            || Permission::CATALOG[$capability][1] !== 'business'
            || ! Permission::where('key', $capability)->where('domain', 'business')->where('active', true)->exists()) {
            return false;
        }
        $roles = $this->effectiveRoles($subject, $scopeId);
        if ($roles === []) {
            return false;
        }

        // Persistent grants are mandatory; the versioned catalog also rejects unapproved storage drift.
        return RolePermission::whereIn('role_key', $roles)->where('permission_key', $capability)->get()
            ->contains(fn ($mapping) => in_array($capability, RolePermission::MAPPINGS[$mapping->role_key] ?? [], true));
    }
}
