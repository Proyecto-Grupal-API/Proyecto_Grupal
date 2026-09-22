<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Supplier;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9\-]+$/', function ($attribute, $value, $fail) {
                if (Supplier::where('code', $value)->where('business_id', 'BUS-CD-SOUV-001')->exists()) {
                    $fail('Ya existe un proveedor con ese código.');
                }
            }],
            'legal_name' => 'required|string|max:200',
            'trade_name' => 'nullable|string|max:200',
            'tax_id' => 'nullable|string|max:20',
            'contact_name' => 'required|string|max:150',
            'contact_email' => 'nullable|email|max:150',
            'contact_phone' => 'nullable|string|max:30',
            'payment_terms' => 'required|string|in:CONTADO,15_DIAS,30_DIAS,60_DIAS',
            'notes' => 'nullable|string|max:1000',
            'status' => 'required|string|in:ACTIVO,INACTIVO,SUSPENDIDO',
        ];
    }
}
