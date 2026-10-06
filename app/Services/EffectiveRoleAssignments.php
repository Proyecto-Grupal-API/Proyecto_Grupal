<?php

namespace App\Services;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;

/** Sole authority for the existing legacy role API; no legacy fallback or capability resolution. */
final class EffectiveRoleAssignments
{
    public function query(User $user)
    {
        $now = now();

        return RoleAssignment::where('user_id', (string) $user->getKey())
            ->whereIn('role_key', Role::VALID_ROLES)
            ->where('status', 'active')->where('is_current', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    public function has(User $user, string $role, ?string $type, ?string $id): bool
    {
        if (! $user->exists || $user->trashed() || ! in_array($role, Role::VALID_ROLES, true)
            || (($type === null) !== ($id === null))
            || ($type !== null && ! in_array($type, Role::VALID_SCOPE_TYPES, true))) {
            return false;
        }

        return $this->query($user)->where('role_key', $role)
            ->where('scope_type', $type)->where('scope_id', $id)->exists();
    }

    public function display(User $user): array
    {
        if (! $user->exists || $user->trashed()) {
            return [];
        }

        return $this->query($user)->get()->map(fn ($record) => [
            'name' => $record->role_key, 'scope_type' => $record->scope_type,
            'scope_id' => $record->scope_id, 'assigned_at' => $record->assigned_at?->toDateTimeString(),
        ])->all();
    }

    public function validateTuple(string $role, ?string $type, ?string $id): void
    {
        if (! in_array($role, Role::VALID_ROLES, true)
            || (($type === null) !== ($id === null))
            || ($type !== null && ! in_array($type, Role::VALID_SCOPE_TYPES, true))
            || $id === '') {
            throw new \InvalidArgumentException('Invalid legacy role or context.');
        }
    }

    private function identity(User $user, string $role, ?string $type, ?string $id)
    {
        return RoleAssignment::where('user_id', (string) $user->getKey())
            ->where('role_key', $role)->where('scope_type', $type)->where('scope_id', $id)
            ->where('is_current', true);
    }

    public function assign(User $user, string $role, ?string $type, ?string $id): void
    {
        $this->validateTuple($role, $type, $id);
        if ($this->identity($user, $role, $type, $id)->exists()) {
            // Do not silently reactivate suspended/pending/expired generations.
            return;
        }
        RoleAssignment::create([
            'user_id' => (string) $user->getKey(), 'role_key' => $role,
            'scope_type' => $type, 'scope_id' => $id, 'status' => 'active',
            'origin' => 'legacy_role_management',
        ]);
    }

    public function revoke(User $user, string $role, ?string $type, ?string $id): bool
    {
        $this->validateTuple($role, $type, $id);
        $record = $this->identity($user, $role, $type, $id)->first();
        if (! $record) {
            return false;
        }
        $record->transitionTo('revoked');

        return true;
    }
}
