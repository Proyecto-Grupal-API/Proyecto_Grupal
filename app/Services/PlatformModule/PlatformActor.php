<?php

namespace App\Services\PlatformModule;

/** Request projection of E1 identity, never a second account or credential store. */
final class PlatformActor
{
    public const ROLE_STUDENT = 'student';

    public const ROLE_PROFESSOR = 'professor';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_PLATFORM_ADMIN = 'platform_admin';

    public const ROLE_AUDITOR = 'auditor';

    public function __construct(public readonly string $id, public readonly string $name, public readonly string $role) {}

    public function getKey(): string
    {
        return $this->id;
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function permissions(): array
    {
        return config('platform_permissions.roles.'.$this->role, []);
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'role' => $this->role, 'permissions' => $this->permissions()];
    }
}
