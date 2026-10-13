<?php

namespace App\Http\Requests\StudentServices\Lockers;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AssignLockerToRequestRequest extends FormRequest
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
            'locker_id' => [
                'required',
                'string',
                'regex:/^[a-fA-F0-9]{24}$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'locker_id.required' => 'Debes seleccionar un locker.',
            'locker_id.regex' => 'El identificador del locker no es válido.',
        ];
    }
}
