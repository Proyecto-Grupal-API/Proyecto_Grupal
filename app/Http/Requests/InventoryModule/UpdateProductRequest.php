<?php

namespace App\Http\Requests\InventoryModule;

class UpdateProductRequest extends ProductRequest
{
    protected function permission(): string
    {
        return 'productos.update';
    }

    protected function ignoredProductId(): ?string
    {
        return (string) $this->route('id');
    }
}
