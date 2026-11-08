<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Supplier;
use App\Models\Product;

class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $identity = app(\App\Services\IdentityService::class);
        $userId   = $identity->resolveCurrentUserId();

        return $identity->userCan($userId, 'compras.update');
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'string', function ($attribute, $value, $fail) {
                if (!Supplier::where('_id', $value)->where('business_id', config('team4.business_id'))->exists()) {
                    $fail('El proveedor seleccionado no existe.');
                }
            }],
            'expected_at' => 'required|date',
            'notes' => 'nullable|string|max:1000',
            'status' => 'required|string|in:BORRADOR,SOLICITADA',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => ['required', 'string', function ($attribute, $value, $fail) {
                if (!Product::where('_id', $value)->where('business_id', config('team4.business_id'))->exists()) {
                    $fail('Uno de los productos seleccionados no existe.');
                }
            }],
            'items.*.quantity' => 'required|integer|min:1|max:100000',
            'items.*.unit_cost' => 'required|numeric|min:0|max:1000000',
        ];
    }
}
