<?php

namespace App\Support\PlatformModule;

final class Permissions
{
    public static function forRole(string $role): array
    {
        return config("platform_permissions.roles.{$role}", []);
    }

    public static function homeArea(string $role): ?string
    {
        return config("platform_permissions.home_area.{$role}");
    }
}
