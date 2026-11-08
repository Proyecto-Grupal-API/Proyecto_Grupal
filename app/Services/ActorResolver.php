<?php

namespace App\Services;

class ActorResolver
{
    public static function resolve(string $fallback = 'SYSTEM'): string
    {
        if (app()->runningInConsole()) return $fallback;
        return app(IdentityService::class)->resolveCurrentUserId();
    }
}
