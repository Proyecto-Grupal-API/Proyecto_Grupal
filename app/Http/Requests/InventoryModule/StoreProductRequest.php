<?php

namespace App\Http\Requests\InventoryModule;

use App\Models\InventoryModule\Category;
use App\Models\InventoryModule\Product;
use App\Services\InventoryModule\BusinessContext;
use App\Services\InventoryModule\IdentityService;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $identity = app(IdentityService::class);
        $userId = $identity->resolveCurrentUserId();

        return $identity->userCan($userId, 'productos.create');
    }

    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9\-]+$/', function ($attribute, $value, $fail) {
                if (Product::where('sku', $value)->where('business_id', app(BusinessContext::class)->id())->exists()) {
                    $fail('Ya existe un producto registrado con ese SKU.');
                }
            }],
            'name' => 'required|string|max:200',
            'description' => 'nullable|string|max:1000',
            'category' => ['required', 'string', function ($attribute, $value, $fail) {
                $exists = Category::where('business_id', app(BusinessContext::class)->id())
                    ->where('slug', $value)
                    ->where('active', true)
                    ->exists();
                if (! $exists) {
                    $fail('La categoría seleccionada no es válida.');
                }
            }],
            'sizes' => 'nullable|array|max:100',
            'sizes.*' => 'required|string|max:80|distinct',
            'colors' => 'nullable|array|max:100',
            'colors.*' => 'required|string|max:80|distinct',
            'seasons' => 'nullable|array|max:100',
            'seasons.*' => 'required|string|max:80|distinct',
            'stock_min' => 'required|integer|min:0|max:100000',
            'stock_max' => ['required', 'integer', 'min:0', 'max:1000000', 'gte:stock_min'],
            'active' => 'required|boolean',
        ];
    }
}
