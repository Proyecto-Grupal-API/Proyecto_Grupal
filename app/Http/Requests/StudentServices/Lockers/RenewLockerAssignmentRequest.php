<?php

namespace App\Http\Requests\StudentServices\Lockers;

use Illuminate\Foundation\Http\FormRequest;

class RenewLockerAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period_id' => [
                'required',
                'string',
                'regex:/^[a-fA-F0-9]{24}$/',
            ],

            'payment_reference' => [
                'nullable',
                'string',
                'max:100',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'period_id.required' => 'Debes seleccionar el periodo al que renuevas.',
            'period_id.regex' => 'El identificador del periodo no es válido.',

            'payment_reference.max' => 'La referencia de pago es demasiado larga.',
        ];
    }
}
