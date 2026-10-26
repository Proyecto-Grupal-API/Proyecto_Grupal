<?php

namespace App\Http\Requests\InventoryModule;

use App\Models\InventoryModule\Category;
use App\Models\InventoryModule\Product;
use App\Services\InventoryModule\BusinessContext;
use App\Services\InventoryModule\IdentityService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $identity = app(IdentityService::class);
        $userId = $identity->resolveCurrentUserId();

        return $identity->userCan($userId, 'productos.update');
    }

    public function rules(): array
    {
        $productId = (string) $this->route('id');

        return [
            'sku' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9\-]+$/', function ($attribute, $value, $fail) use ($productId) {
                if (Product::where('sku', $value)->where('business_id', app(BusinessContext::class)->id())->where('_id', '!=', $productId)->exists()) {
                    $fail('Ya existe otro producto registrado con ese SKU.');
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
