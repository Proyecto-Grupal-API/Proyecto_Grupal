<?php

namespace App\Http\Requests\StudentServices\Lockers;

use App\Models\StudentServices\Lockers\Locker;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaidLockerRequest extends FormRequest
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
            'period_id' => [
                'required',
                'string',
                'regex:/^[a-fA-F0-9]{24}$/',
            ],

            'size' => [
                'required',
                Rule::in(Locker::SIZES),
            ],

            'building' => [
                'nullable',
                'string',
                'max:100',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'period_id.required' => 'Debes seleccionar un periodo.',
            'period_id.regex' => 'El identificador del periodo no es válido.',

            'size.required' => 'Debes seleccionar el tamaño del locker.',
            'size.in' => 'El tamaño seleccionado no es válido.',

            'building.max' => 'El nombre del edificio es demasiado largo.',
        ];
    }
}
