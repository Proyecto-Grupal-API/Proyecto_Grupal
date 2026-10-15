<?php
namespace App\Services\InventoryModule;
class ActorResolver
{
    public static function resolve(string $fallback = 'SYSTEM'): string
    {
        return (string) (request()->user()?->getAuthIdentifier()
            ?? request()->attributes->get('inventory_service_actor') ?? 'SYSTEM');
    }
}
