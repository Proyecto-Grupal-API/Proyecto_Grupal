<?php

namespace App\Http\Requests\InventoryModule;

class StoreProductRequest extends ProductRequest
{
    protected function permission(): string
    {
        return 'productos.create';
    }
}
