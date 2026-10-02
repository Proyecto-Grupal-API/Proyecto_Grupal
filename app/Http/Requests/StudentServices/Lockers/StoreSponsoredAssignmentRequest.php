<?php

namespace App\Http\Requests\StudentServices\Lockers;

use App\Models\StudentServices\Lockers\Locker;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSponsoredAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => [
                'required',
                'string',
                'max:100',
            ],

            'period_id' => [
                'required',
                'string',
                'regex:/^[a-fA-F0-9]{24}$/',
            ],

            'request_type' => [
                'required',
                Rule::in([
                    'council',
                    'scholarship',
                ]),
            ],

            'locker_id' => [
                'nullable',
                'string',
                'regex:/^[a-fA-F0-9]{24}$/',
            ],

            'size' => [
                'nullable',
                'required_without:locker_id',
                Rule::in(Locker::SIZES),
            ],

            'reference' => [
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'student_id.required' => 'La matrícula o identificador del estudiante es obligatorio.',
            'student_id.max' => 'El identificador del estudiante es demasiado largo.',

            'period_id.required' => 'Debes seleccionar un periodo.',
            'period_id.regex' => 'El identificador del periodo no es válido.',

            'request_type.required' => 'Debes indicar si es asignación del Consejo o de beca.',
            'request_type.in' => 'El tipo de asignación no es válido.',

            'locker_id.regex' => 'El identificador del locker no es válido.',

            'size.required_without' => 'Selecciona un locker específico o un tamaño.',
            'size.in' => 'El tamaño seleccionado no es válido.',

            'reference.max' => 'La referencia es demasiado larga.',
        ];
    }
}
