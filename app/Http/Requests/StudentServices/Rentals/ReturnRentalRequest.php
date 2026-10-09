<?php

namespace App\Http\Requests\StudentServices\Rentals;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReturnRentalRequest extends FormRequest
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
            'condition' => [
                'required',
                'in:good,damaged,maintenance,lost',
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
            'condition.required' => 'Debes indicar la condición del equipo.',

            'condition.in' => 'La condición seleccionada no es válida.',
        ];
    }
}
