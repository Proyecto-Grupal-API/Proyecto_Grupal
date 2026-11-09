<?php

namespace App\Services;

use App\Contracts\RewardsAuthorization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;

/** E7 rights only. Resource, data partition, eligibility and separation of duties remain E7-owned. */
class RewardsAuthorizationService implements RewardsAuthorization
{
    // Capability/context compatibility is technical validation, not a role grant policy.
    public const CAPABILITY_SCOPES = [
        'rewards.program.manage' => [null],
        'rewards.catalog.manage' => [null, 'business'],
        'rewards.config.approve' => [null],
        'rewards.redemption.deliver' => ['business'],
        'audit.view' => [null],
        'analytics.view' => [null, 'business'],
    ];

    public function __construct(private EffectiveRoleAssignments $assignments, private BusinessAuthorizationService $identities) {}

    protected function roleScopes(): array
    {
        return Role::REWARDS_ROLE_SCOPES;
    }

    protected function mappings(): array
    {
        return RolePermission::REWARDS_MAPPINGS;
    }

    public function allows(?string $subjectId, string $capability, ?string $scopeType = null, ?string $scopeId = null): bool
    {
        if (! array_key_exists($capability, self::CAPABILITY_SCOPES)
            || ! in_array($scopeType, self::CAPABILITY_SCOPES[$capability], true)
            || (($scopeType === null) !== ($scopeId === null))) {
            return false;
        }
        $id = $scopeType === null ? null : $this->identities->businessId($scopeId);
        $userId = $this->identities->businessId($subjectId);
        if ($userId === null || ($scopeType !== null && $id === null)) {
            return false;
        }
        $user = $this->identities->validUser(User::whereKey($userId)->first());
        if (! $user || ! Permission::where('key', $capability)->where('domain', 'rewards')->where('active', true)->exists()
            || ($scopeType === 'business' && ! $this->identities->hasMembership($user, $id))) {
            return false;
        }
        $matrix = $this->mappings();
        $roles = array_keys(array_filter($this->roleScopes(), fn ($scope, $role) => $scope === $scopeType
            && in_array($capability, $matrix[$role] ?? [], true), ARRAY_FILTER_USE_BOTH));
        $catalog = Role::whereIn('name', $roles)->pluck('name')->all();
        $effective = $this->assignments->queryForRoles($user, $catalog)
            ->where('scope_type', $scopeType)->where('scope_id', $id)
            ->whereRaw(['scope_type' => ['$exists' => true], 'scope_id' => ['$exists' => true]])
            ->pluck('role_key')->all();

        return RolePermission::whereIn('role_key', $effective)->where('permission_key', $capability)->exists();
    }
}
