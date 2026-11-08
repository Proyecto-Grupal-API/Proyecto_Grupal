<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Product;
use App\Models\Category;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $identity = app(\App\Services\IdentityService::class);
        $userId   = $identity->resolveCurrentUserId();

        return $identity->userCan($userId, 'productos.create');
    }

    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9\-]+$/', function ($attribute, $value, $fail) {
                if (Product::where('sku', $value)->where('business_id', config('team4.business_id'))->exists()) {
                    $fail('Ya existe un producto registrado con ese SKU.');
                }
            }],
            'name' => 'required|string|max:200',
            'description' => 'nullable|string|max:1000',
            'category' => ['required', 'string', function ($attribute, $value, $fail) {
                $exists = Category::where('business_id', config('team4.business_id'))
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
            'sizes' => 'nullable|array|max:100',
            'sizes.*' => 'required|string|max:50',
            'colors' => 'nullable|array|max:100',
            'colors.*' => 'required|string|max:50',
            'seasons' => 'nullable|array|max:100',
            'seasons.*' => 'required|string|max:100',
            'collections' => 'nullable|array|max:100',
            'collections.*' => 'required|string|max:100',
            'packages' => 'nullable|array|max:100',
            'packages.*' => 'required|string|max:100',
            'preorder' => 'nullable|array',
            'preorder.enabled' => 'sometimes|boolean',
            'preorder.available_at' => ['nullable', 'required_if:preorder.enabled,true', 'date'],
            'preorder.limit' => ['nullable', 'required_if:preorder.enabled,true', 'integer', 'min:1', 'max:1000000'],
            'official' => 'sometimes|boolean',
        ];
    }
}
