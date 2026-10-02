<?php

namespace App\Http\Requests\StudentServices\Lockers;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmLockerPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_reference' => [
                'required',
                'string',
                'max:100',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_reference.required' => 'La referencia de pago es obligatoria.',
            'payment_reference.max' => 'La referencia de pago es demasiado larga.',
        ];
    }
}
