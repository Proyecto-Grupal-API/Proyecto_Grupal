<?php

namespace App\Support;

final class Permissions
{
    public static function forRole(string $role): array
    {
        return config("permissions.roles.{$role}", []);
    }

    public static function homeArea(string $role): ?string
    {
        return config("permissions.home_area.{$role}");
    }
}
