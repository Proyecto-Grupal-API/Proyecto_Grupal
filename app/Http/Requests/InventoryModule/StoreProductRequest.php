<?php

namespace App\Http\Requests\InventoryModule;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\InventoryModule\Product;
use App\Models\InventoryModule\Category;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $identity = app(\App\Services\InventoryModule\IdentityService::class);
        $userId   = $identity->resolveCurrentUserId();

        return $identity->userCan($userId, 'productos.create');
    }

    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9\-]+$/', function ($attribute, $value, $fail) {
                if (Product::where('sku', $value)->where('business_id', app(\App\Services\InventoryModule\BusinessContext::class)->id())->exists()) {
                    $fail('Ya existe un producto registrado con ese SKU.');
                }
            }],
            'name' => 'required|string|max:200',
            'description' => 'nullable|string|max:1000',
            'category' => ['required', 'string', function ($attribute, $value, $fail) {
                $exists = Category::where('business_id', app(\App\Services\InventoryModule\BusinessContext::class)->id())
                    ->where('slug', $value)
                    ->where('active', true)
                    ->exists();
                if (!$exists) {
                    $fail('La categoría seleccionada no es válida.');
                }
            }],
            'stock_min' => 'required|integer|min:0|max:100000',
            'stock_max' => ['required', 'integer', 'min:0', 'max:1000000', 'gte:stock_min'],
            'active' => 'required|boolean',
        ];
    }
}
