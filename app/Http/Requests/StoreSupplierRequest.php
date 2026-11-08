<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Supplier;
use App\Models\Product;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        $identity = app(\App\Services\IdentityService::class);
        $userId   = $identity->resolveCurrentUserId();

        return $identity->userCan($userId, 'proveedores.create');
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9\-]+$/', function ($attribute, $value, $fail) {
                if (Supplier::where('code', $value)->where('business_id', config('team4.business_id'))->exists()) {
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
            'supplied_products' => 'nullable|array|max:500',
            'supplied_products.*' => ['required', 'string', 'max:100', function ($attribute, $value, $fail) {
                $exists = Product::where('business_id', config('team4.business_id'))
                    ->where(function ($query) use ($value) {
                        $query->where('sku', $value)->orWhere('_id', $value);
                    })->exists();
                if (!$exists) {
                    $fail('El producto suministrado no pertenece a este negocio.');
                }
            }],
            'contacts' => 'nullable|array|max:50',
            'contacts.*' => ['required', function ($attribute, $value, $fail) {
                if (is_string($value) && mb_strlen($value) <= 200) {
                    return;
                }
                if (!is_array($value)
                    || !isset($value['name'])
                    || !is_string($value['name'])
                    || mb_strlen($value['name']) > 150) {
                    $fail('Cada contacto debe ser texto o incluir un nombre válido.');
                    return;
                }
                if (isset($value['email']) && $value['email'] !== null
                    && (!is_string($value['email']) || !filter_var($value['email'], FILTER_VALIDATE_EMAIL))) {
                    $fail('El correo del contacto no es válido.');
                }
                foreach (['phone' => 30, 'role' => 100] as $field => $maxLength) {
                    if (isset($value[$field]) && (!is_string($value[$field]) || mb_strlen($value[$field]) > $maxLength)) {
                        $fail('El campo ' . $field . ' del contacto excede la longitud permitida.');
                    }
                }
            }],
        ];
    }
}
