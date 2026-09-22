<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Location;
use App\Models\Warehouse;

class StoreLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'string', function ($attribute, $value, $fail) {
                if (!Warehouse::where('_id', $value)->where('business_id', 'BUS-CD-SOUV-001')->exists()) {
                    $fail('El almacén seleccionado no existe.');
                }
            }],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9\-]+$/', function ($attribute, $value, $fail) {
                $warehouseId = $this->input('warehouse_id');
                if (Location::where('code', $value)->where('warehouse_id', $warehouseId)->exists()) {
                    $fail('Ya existe una ubicación con ese código en este almacén.');
                }
            }],
            'name' => 'required|string|max:150',
            'type' => 'required|string|in:STORAGE,DISPLAY,RECEIVING,SHIPPING',
            'capacity' => 'required|integer|min:0|max:1000000',
            'active' => 'required|boolean',
        ];
    }
}
