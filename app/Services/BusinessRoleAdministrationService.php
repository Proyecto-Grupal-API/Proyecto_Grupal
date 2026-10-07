<?php

namespace App\Services;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Trusted internal actor/subject boundary; no owner provisioning or ownership transfer. */
final class BusinessRoleAdministrationService
{
    public function __construct(private BusinessAuthorizationService $authorization) {}

    private function authorize(?User $actor, ?User $target, ?string $businessId, string $role, bool $assign): array
    {
        $subject = $this->authorization->validUser($target);
        $id = $this->authorization->businessId($businessId);
        if (! $subject || $id === null || ! in_array($role, ['business_manager', 'cashier', 'inventory_manager', 'buyer'], true)
            || ! Role::where('name', $role)->exists()
            || ($assign && ! $this->authorization->hasMembership($subject, $id))) {
            throw new AuthorizationException('Business role operation denied.');
        }
        $roles = $this->authorization->effectiveRoles($actor, $id);
        if (! in_array('business_owner', $roles, true)
            && ! (in_array('business_manager', $roles, true) && in_array($role, ['inventory_manager', 'buyer'], true))) {
            throw new AuthorizationException('Business role operation denied.');
        }

        return [$subject, $id];
    }

    public function assign(?User $actor, ?User $target, ?string $businessId, string $role): RoleAssignment
    {
        return DB::connection('mongodb')->transaction(function () use ($actor, $target, $businessId, $role) {
            [$subject, $id] = $this->authorize($actor, $target, $businessId, $role, true);
            $existing = $this->identity($subject, $id, $role)->first();
            if ($existing) {
                // An idempotent request never silently reactivates an existing generation.
                return $existing;
            }

            return RoleAssignment::create([
                'user_id' => (string) $subject->getKey(), 'role_key' => $role,
                'scope_type' => 'business', 'scope_id' => $id, 'status' => 'active',
                'assigned_by' => (string) $actor->getKey(), 'origin' => 'business_role_management',
            ]);
        });
    }

    public function revoke(?User $actor, ?User $target, ?string $businessId, string $role): bool
    {
        return DB::connection('mongodb')->transaction(function () use ($actor, $target, $businessId, $role) {
            [$subject, $id] = $this->authorize($actor, $target, $businessId, $role, false);
            $record = $this->identity($subject, $id, $role)->first();
            if (! $record) {
                return false;
            }
            $record->transitionTo('revoked', (string) $actor->getKey());

            return true;
        });
    }

    private function identity(User $subject, string $businessId, string $role)
    {
        return RoleAssignment::where('user_id', (string) $subject->getKey())->where('role_key', $role)
            ->where('scope_type', 'business')->where('scope_id', $businessId)->where('is_current', true);
    }
}
