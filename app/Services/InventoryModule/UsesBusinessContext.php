<?php
namespace App\Services\InventoryModule;
trait UsesBusinessContext
{
    protected function businessId(): string
    {
        return app(BusinessContext::class)->id();
    }
}
