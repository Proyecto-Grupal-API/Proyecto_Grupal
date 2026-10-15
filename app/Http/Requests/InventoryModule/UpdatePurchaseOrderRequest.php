<?php

namespace App\Http\Requests\InventoryModule;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\InventoryModule\Supplier;
use App\Models\InventoryModule\Product;

class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $identity = app(\App\Services\InventoryModule\IdentityService::class);
        $userId   = $identity->resolveCurrentUserId();

        return $identity->userCan($userId, 'compras.update')
            && ($this->input('status') !== 'AUTORIZADA' || $identity->userCan($userId, 'compras.authorize'))
            && ($this->input('status') !== 'CANCELADA' || $identity->userCan($userId, 'compras.cancel'));
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'string', function ($attribute, $value, $fail) {
                if (!Supplier::where('_id', $value)->where('business_id', app(\App\Services\InventoryModule\BusinessContext::class)->id())->exists()) {
                    $fail('El proveedor seleccionado no existe.');
                }
            }],
            'expected_at' => 'required|date',
            'notes' => 'nullable|string|max:1000',
            'status' => 'required|string|in:BORRADOR,SOLICITADA,AUTORIZADA,CANCELADA',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => ['required', 'string', function ($attribute, $value, $fail) {
                if (!Product::where('_id', $value)->where('business_id', app(\App\Services\InventoryModule\BusinessContext::class)->id())->exists()) {
                    $fail('Uno de los productos seleccionados no existe.');
                }
            }],
            'items.*.quantity' => 'required|integer|min:1|max:100000',
            'items.*.unit_cost' => 'required|numeric|min:0|max:1000000',
        ];
    }
}
