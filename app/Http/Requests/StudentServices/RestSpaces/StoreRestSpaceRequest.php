<?php

namespace App\Http\Requests\StudentServices\RestSpaces;

use App\Models\StudentServices\RestSpaces\RestSpace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRestSpaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
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

    /**
     * Datos validados con los tipos que espera RestSpaceService::create().
     *
     * @return array{code: string, name: string, type: string, location: string, capacity: int, description?: string|null}
     */
    public function spaceData(): array
    {
        return [
            'code' => $this->string('code')->value(),
            'name' => $this->string('name')->value(),
            'type' => $this->string('type')->value(),
            'location' => $this->string('location')->value(),
            'capacity' => $this->integer('capacity'),
            'description' => $this->filled('description') ? $this->string('description')->value() : null,
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
