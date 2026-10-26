<?php

namespace App\Http\Requests\InventoryModule;

use App\Models\InventoryModule\Product;
use App\Models\InventoryModule\Supplier;
use App\Services\InventoryModule\BusinessContext;
use App\Services\InventoryModule\IdentityService;
use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        $identity = app(IdentityService::class);
        $userId = $identity->resolveCurrentUserId();

        return $identity->userCan($userId, 'proveedores.create');
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9\-]+$/', function ($attribute, $value, $fail) {
                if (Supplier::where('code', $value)->where('business_id', app(BusinessContext::class)->id())->exists()) {
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
            'supplied_products' => 'nullable|array|max:200',
            'supplied_products.*' => ['bail', 'required', 'string', 'max:50', 'distinct', function ($attribute, $value, $fail) {
                if (! Product::where('business_id', app(BusinessContext::class)->id())->where('sku', $value)->exists()) {
                    $fail('El producto surtido no pertenece al negocio seleccionado.');
                }
            }],
            'contacts' => 'nullable|array|max:30',
            'contacts.*' => 'required|string|max:250',
            'notes' => 'nullable|string|max:1000',
            'status' => 'required|string|in:ACTIVO,INACTIVO,SUSPENDIDO',
        ];
    }
}
