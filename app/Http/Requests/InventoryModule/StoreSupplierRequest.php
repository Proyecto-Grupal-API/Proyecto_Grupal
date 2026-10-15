<?php

namespace App\Http\Requests\InventoryModule;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\InventoryModule\Supplier;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        $identity = app(\App\Services\InventoryModule\IdentityService::class);
        $userId   = $identity->resolveCurrentUserId();

        return $identity->userCan($userId, 'proveedores.create');
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9\-]+$/', function ($attribute, $value, $fail) {
                if (Supplier::where('code', $value)->where('business_id', app(\App\Services\InventoryModule\BusinessContext::class)->id())->exists()) {
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
