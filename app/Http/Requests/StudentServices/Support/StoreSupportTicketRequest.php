<?php

namespace App\Http\Requests\StudentServices\Support;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupportTicketRequest extends FormRequest
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
            'category' => [
                'required',
                'string',
                Rule::in([
                    'network',
                    'equipment',
                    'facilities',
                    'software',
                    'printing',
                    'other',
                ]),
            ],

            'subject' => [
                'required',
                'string',
                'max:150',
            ],

            'description' => [
                'required',
                'string',
                'max:2000',
            ],

            'priority' => [
                'required',
                'string',
                Rule::in([
                    'low',
                    'medium',
                    'high',
                    'critical',
                ]),
            ],

            'location' => [
                'required',
                'string',
                'max:200',
            ],

            'evidence' => [
                'nullable',
                'file',
                'mimes:png,jpg,jpeg,pdf',
                'max:10240',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'category.required' => 'Selecciona una categoría.',

            'category.in' => 'La categoría seleccionada no es válida.',

            'subject.required' => 'El asunto es obligatorio.',

            'subject.max' => 'El asunto no puede superar los 150 caracteres.',

            'description.required' => 'La descripción es obligatoria.',

            'description.max' => 'La descripción no puede superar los 2000 caracteres.',

            'priority.required' => 'Selecciona una prioridad.',

            'priority.in' => 'La prioridad seleccionada no es válida.',

            'location.required' => 'La ubicación es obligatoria.',

            'location.max' => 'La ubicación no puede superar los 200 caracteres.',

            'evidence.file' => 'La evidencia seleccionada no es válida.',

            'evidence.mimes' => 'La evidencia debe ser PNG, JPG, JPEG o PDF.',

            'evidence.max' => 'La evidencia no puede superar los 10 MB.',
        ];
    }
}
