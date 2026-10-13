<?php

namespace App\Http\Requests\StudentServices\Lockers;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ValidateLockerAccessRequest extends FormRequest
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
                'max:60',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Escanea o escribe un código.',
            'code.max' => 'El código es demasiado largo.',
        ];
    }
}
