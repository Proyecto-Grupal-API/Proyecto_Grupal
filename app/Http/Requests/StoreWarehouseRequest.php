<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Warehouse;

class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9\-]+$/', function ($attribute, $value, $fail) {
                if (Warehouse::where('code', $value)->where('business_id', 'BUS-CD-SOUV-001')->exists()) {
                    $fail('Ya existe un almacén con ese código.');
                }
            }],
            'name' => 'required|string|max:150',
            'type' => 'required|string|in:MAIN,STORAGE,DISPLAY,CONSIGNMENT',
            'active' => 'required|boolean',
        ];
    }
}
