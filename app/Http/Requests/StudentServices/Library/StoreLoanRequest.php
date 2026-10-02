<?php

namespace App\Http\Requests\StudentServices\Library;

use Illuminate\Foundation\Http\FormRequest;

class StoreLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'copy_id' => [
                'required',
                'string',
                'size:24',
            ],

            'student_id' => [
                'required',
                'string',
                'max:100',
            ],

            'loan_days' => [
                'nullable',
                'integer',
                'min:1',
                'max:30',
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
            'copy_id.required' =>
                'Selecciona un ejemplar.',

            'copy_id.size' =>
                'El identificador del ejemplar no es válido.',

            'student_id.required' =>
                'El estudiante es obligatorio.',

            'loan_days.min' =>
                'El préstamo debe durar al menos un día.',

            'loan_days.max' =>
                'El préstamo no puede superar 30 días.',
        ];
    }
}
