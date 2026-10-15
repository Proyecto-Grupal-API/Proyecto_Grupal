<?php

namespace App\Http\Requests\StudentServices\Library;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBookCopyRequest extends FormRequest
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
            'book_id' => [
                'required',
                'string',
                'regex:/^[a-fA-F0-9]{24}$/',
            ],

            'code' => [
                'required',
                'string',
                'max:50',
            ],

            'barcode' => [
                'nullable',
                'string',
                'max:100',
            ],

            'location' => [
                'required',
                'string',
                'max:255',
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
            'book_id.required' => 'Debes seleccionar un libro.',

            'book_id.regex' => 'El identificador del libro no es válido.',

            'code.required' => 'El código del ejemplar es obligatorio.',

            'code.max' => 'El código del ejemplar es demasiado largo.',

            'barcode.max' => 'El código de barras es demasiado largo.',

            'location.required' => 'La ubicación del ejemplar es obligatoria.',

            'location.max' => 'La ubicación es demasiado larga.',

            'notes.max' => 'Las notas no pueden superar los 1000 caracteres.',
        ];
    }
}
