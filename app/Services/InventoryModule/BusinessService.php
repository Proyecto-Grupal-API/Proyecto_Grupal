<?php
namespace App\Services\InventoryModule;
class BusinessService
{
    public function validate(string $businessId): bool
    {
        if (!app(BusinessContext::class)->exists($businessId)) { throw new \InvalidArgumentException('El negocio no tiene inventario habilitado.'); }
        return true;
    }
}
