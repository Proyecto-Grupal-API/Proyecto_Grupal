<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Supplier;
use App\Models\Product;

class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'string', function ($attribute, $value, $fail) {
                if (!Supplier::where('_id', $value)->where('business_id', 'BUS-CD-SOUV-001')->exists()) {
                    $fail('El proveedor seleccionado no existe.');
                }
            }],
            'expected_at' => 'required|date',
            'notes' => 'nullable|string|max:1000',
            'status' => 'required|string|in:BORRADOR,SOLICITADA,AUTORIZADA,CANCELADA',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => ['required', 'string', function ($attribute, $value, $fail) {
                if (!Product::where('_id', $value)->where('business_id', 'BUS-CD-SOUV-001')->exists()) {
                    $fail('Uno de los productos seleccionados no existe.');
                }
            }],
            'items.*.quantity' => 'required|integer|min:1|max:100000',
            'items.*.unit_cost' => 'required|numeric|min:0|max:1000000',
        ];
    }
}
