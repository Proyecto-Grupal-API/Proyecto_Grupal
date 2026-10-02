<?php

namespace App\Http\Requests\StudentServices\Support;

use Illuminate\Foundation\Http\FormRequest;

class AssignTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assigned_to' => [
                'required',
                'string',
                'max:150',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'assigned_to.required' =>
                'Debes indicar a quién se asignará el ticket.',

            'assigned_to.max' =>
                'El identificador de asignación es demasiado largo.',
        ];
    }
}
