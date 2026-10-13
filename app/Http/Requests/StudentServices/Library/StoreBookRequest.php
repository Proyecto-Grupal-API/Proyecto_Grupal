<?php

namespace App\Http\Requests\StudentServices\Library;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBookRequest extends FormRequest
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
            'isbn' => [
                'nullable',
                'string',
                'max:30',
            ],

            'title' => [
                'required',
                'string',
                'max:255',
            ],

            'authors' => [
                'required',
                'string',
                'max:500',
            ],

            'publisher' => [
                'nullable',
                'string',
                'max:255',
            ],

            'edition' => [
                'nullable',
                'string',
                'max:100',
            ],

            'publication_year' => [
                'nullable',
                'integer',
                'min:1000',
                'max:2100',
            ],

            'category' => [
                'nullable',
                'string',
                'max:150',
            ],

            'description' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'El título es obligatorio.',
            'authors.required' => 'Debes indicar al menos un autor.',
            'publication_year.integer' => 'El año debe ser un número.',
            'publication_year.min' => 'El año de publicación no es válido.',
            'publication_year.max' => 'El año de publicación no es válido.',
        ];
    }
}
