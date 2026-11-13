<?php

namespace App\Http\Requests\InventoryModule;

use App\Models\InventoryModule\Category;
use App\Models\InventoryModule\Product;
use App\Services\InventoryModule\BusinessContext;
use App\Services\InventoryModule\IdentityService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reglas comunes de alta y edición de productos (E4). Antes estaban copiadas en Store/UpdateProductRequest;
 * sólo difieren el permiso y el producto excluido de la unicidad del SKU (rúbrica 5.6).
 */
abstract class ProductRequest extends FormRequest
{
    abstract protected function permission(): string;

    /** Producto que se edita (se excluye de la unicidad del SKU); null en el alta. */
    protected function ignoredProductId(): ?string
    {
        return null;
    }

    public function authorize(): bool
    {
        $identity = app(IdentityService::class);
        $userId = $identity->resolveCurrentUserId();

        return $identity->userCan($userId, $this->permission());
    }

    public function rules(): array
    {
        $ignored = $this->ignoredProductId();

        return [
            'sku' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9\-]+$/', function ($attribute, $value, $fail) use ($ignored) {
                $query = Product::where('sku', $value)->where('business_id', app(BusinessContext::class)->id());
                if ($ignored !== null) {
                    $query->where('_id', '!=', $ignored);
                }
                if ($query->exists()) {
                    $fail($ignored === null ? 'Ya existe un producto registrado con ese SKU.' : 'Ya existe otro producto registrado con ese SKU.');
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
            'collections' => 'nullable|array|max:100',
            'collections.*' => 'required|string|max:100|distinct',
            'packages' => 'nullable|array|max:100',
            'packages.*' => 'required|string|max:100|distinct',
            'preorder' => 'nullable|array:enabled,available_at,limit',
            'preorder.enabled' => 'sometimes|boolean',
            'preorder.available_at' => ['nullable', 'required_if:preorder.enabled,true', 'date'],
            'preorder.limit' => ['nullable', 'required_if:preorder.enabled,true', 'integer', 'min:1', 'max:1000000'],
            'official' => 'sometimes|boolean',
            'stock_min' => 'required|integer|min:0|max:100000',
            'stock_max' => ['required', 'integer', 'min:0', 'max:1000000', 'gte:stock_min'],
            'active' => 'required|boolean',
        ];
    }
}
