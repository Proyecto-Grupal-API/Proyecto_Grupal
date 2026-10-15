<?php
namespace App\Services\InventoryModule;

use App\Models\InventoryModule\InventoryBusiness;

class BusinessContext
{
    public function id(): string
    {
        return (string) request()->attributes->get('inventory_business_id', config('team4.business_id'));
    }

    public function select(string $id): void
    {
        request()->attributes->set('inventory_business_id', $id);
    }

    public function exists(string $id): bool
    {
        return InventoryBusiness::where('business_id', $id)->where('active', true)->exists();
    }

    public function options(): array
    {
        return InventoryBusiness::where('active', true)->orderBy('name')->limit(100)
            ->get(['business_id', 'name'])->map(fn ($item) => ['id' => $item->business_id, 'name' => $item->name])->all();
    }
}
