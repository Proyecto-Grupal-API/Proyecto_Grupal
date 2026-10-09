<?php

namespace App\Http\Requests\StudentServices\Support;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeTicketStatusRequest extends FormRequest
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
            'status' => [
                'required',
                'string',
                Rule::in([
                    'open',
                    'in_review',
                    'in_progress',
                    'resolved',
                    'closed',
                ]),
            ],

            'message' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Selecciona el nuevo estado.',

            'status.in' => 'El estado seleccionado no es válido.',
        ];
    }
}
