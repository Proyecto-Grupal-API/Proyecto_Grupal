<?php

namespace App\Http\Requests\InventoryModule;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\InventoryModule\Warehouse;

class UpdateWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $identity = app(\App\Services\InventoryModule\IdentityService::class);
        $userId   = $identity->resolveCurrentUserId();

        return $identity->userCan($userId, 'almacenes.update');
    }

    public function rules(): array
    {
        $warehouseId = (string) $this->route('id');

        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9\-]+$/', function ($attribute, $value, $fail) use ($warehouseId) {
                if (Warehouse::where('code', $value)->where('business_id', app(\App\Services\InventoryModule\BusinessContext::class)->id())->where('_id', '!=', $warehouseId)->exists()) {
                    $fail('Ya existe otro almacén con ese código.');
                }
            }],
            'name' => 'required|string|max:150',
            'type' => 'required|string|in:MAIN,STORAGE,DISPLAY,CONSIGNMENT',
            'active' => 'required|boolean',
        ];
    }
}
