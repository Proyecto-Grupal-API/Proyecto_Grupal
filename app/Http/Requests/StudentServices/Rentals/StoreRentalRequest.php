<?php

namespace App\Http\Requests\StudentServices\Rentals;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRentalRequest extends FormRequest
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
            'asset_id' => [
                'required',
                'string',
                'size:24',
            ],

            'due_at' => [
                'required',
                'date',
                'after_or_equal:today',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'asset_id.required' => 'Debes seleccionar un equipo.',

            'due_at.required' => 'Debes indicar la fecha de devolución.',

            'due_at.date' => 'La fecha de devolución no es válida.',

            'due_at.after_or_equal' => 'La fecha de devolución no puede ser anterior a hoy.',
        ];
    }
}
