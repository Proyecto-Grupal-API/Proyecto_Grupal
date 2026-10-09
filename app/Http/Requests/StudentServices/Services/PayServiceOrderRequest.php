<?php

namespace App\Http\Requests\StudentServices\Services;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PayServiceOrderRequest extends FormRequest
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
            'payment_reference_id' => [
                'required',
                'string',
                'max:150',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_reference_id.required' => 'La referencia de pago es obligatoria.',

            'payment_reference_id.max' => 'La referencia de pago no puede superar los 150 caracteres.',
        ];
    }
}
