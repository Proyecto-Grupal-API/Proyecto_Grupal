<?php

namespace App\Http\Requests\StudentServices\Lockers;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReleaseLockerAssignmentRequest extends FormRequest
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
            'reason' => [
                'required',
                'string',
                'max:255',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Debes indicar el motivo de la liberación.',
            'reason.max' => 'El motivo es demasiado largo.',
        ];
    }
}
