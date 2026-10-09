<?php

namespace App\Http\Requests\StudentServices\Lockers;

use App\Models\StudentServices\Lockers\Locker;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveLockerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9_-]+$/',
            ],

            'building' => [
                'required',
                'string',
                'max:100',
            ],

            'zone' => [
                'required',
                'string',
                'max:100',
            ],

            'size' => [
                'required',
                Rule::in(Locker::SIZES),
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
            'code.required' => 'El código del locker es obligatorio.',
            'code.max' => 'El código del locker es demasiado largo.',
            'code.regex' => 'El código solo puede tener letras, números, guiones y guion bajo.',

            'building.required' => 'El edificio es obligatorio.',
            'building.max' => 'El nombre del edificio es demasiado largo.',

            'zone.required' => 'La zona es obligatoria.',
            'zone.max' => 'El nombre de la zona es demasiado largo.',

            'size.required' => 'Debes seleccionar el tamaño del locker.',
            'size.in' => 'El tamaño seleccionado no es válido.',

            'notes.max' => 'Las notas no pueden superar los 1000 caracteres.',
        ];
    }
}
