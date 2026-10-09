<?php

namespace App\Http\Requests\StudentServices\RestSpaces;

use App\Models\StudentServices\RestSpaces\RestSpace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRestSpaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(array_keys(RestSpace::TYPES))],
            'location' => ['required', 'string', 'max:120'],
            'capacity' => ['required', 'integer', 'min:1', 'max:50'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Captura el código del espacio.',
            'name.required' => 'Captura el nombre del espacio.',
            'type.required' => 'Selecciona el tipo de espacio.',
            'location.required' => 'Captura la ubicación.',
            'capacity.required' => 'Captura la capacidad.',
        ];
    }
}
