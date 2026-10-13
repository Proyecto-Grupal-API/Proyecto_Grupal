<?php

namespace App\Http\Requests\StudentServices\Lockers;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveLockerPeriodRequest extends FormRequest
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
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^[A-Za-z0-9_-]+$/',
            ],

            'name' => [
                'required',
                'string',
                'max:100',
            ],

            'starts_at' => [
                'required',
                'date_format:Y-m-d',
            ],

            'ends_at' => [
                'required',
                'date_format:Y-m-d',
                'after:starts_at',
            ],

            'prices' => [
                'required',
                'array',
            ],

            'prices.small' => $this->priceRules(),
            'prices.medium' => $this->priceRules(),
            'prices.large' => $this->priceRules(),
        ];
    }

    /**
     * @return list<string>
     */
    private function priceRules(): array
    {
        return [
            'required',
            'numeric',
            'min:0',
            'max:99999.99',
            'decimal:0,2',
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'El código del periodo es obligatorio.',
            'code.max' => 'El código del periodo es demasiado largo.',
            'code.regex' => 'El código solo puede tener letras, números, guiones y guion bajo.',

            'name.required' => 'El nombre del periodo es obligatorio.',
            'name.max' => 'El nombre del periodo es demasiado largo.',

            'starts_at.required' => 'La fecha de inicio es obligatoria.',
            'starts_at.date_format' => 'La fecha de inicio no es válida.',

            'ends_at.required' => 'La fecha de fin es obligatoria.',
            'ends_at.date_format' => 'La fecha de fin no es válida.',
            'ends_at.after' => 'La fecha de fin debe ser posterior a la de inicio.',

            'prices.required' => 'Debes indicar los costos del periodo.',

            'prices.small.required' => 'El costo del locker chico es obligatorio.',
            'prices.medium.required' => 'El costo del locker mediano es obligatorio.',
            'prices.large.required' => 'El costo del locker grande es obligatorio.',

            'prices.small.numeric' => 'El costo del locker chico debe ser un número.',
            'prices.medium.numeric' => 'El costo del locker mediano debe ser un número.',
            'prices.large.numeric' => 'El costo del locker grande debe ser un número.',

            'prices.small.min' => 'El costo del locker chico no puede ser negativo.',
            'prices.medium.min' => 'El costo del locker mediano no puede ser negativo.',
            'prices.large.min' => 'El costo del locker grande no puede ser negativo.',

            'prices.small.decimal' => 'El costo del locker chico admite máximo 2 decimales.',
            'prices.medium.decimal' => 'El costo del locker mediano admite máximo 2 decimales.',
            'prices.large.decimal' => 'El costo del locker grande admite máximo 2 decimales.',
        ];
    }
}
