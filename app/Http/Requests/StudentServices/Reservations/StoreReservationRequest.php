<?php

namespace App\Http\Requests\StudentServices\Reservations;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreReservationRequest extends FormRequest
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
            'facility_id' => [
                'required',
                'string',
            ],

            'date' => [
                'required',
                'date',
            ],

            'start_time' => [
                'required',
                'date_format:H:i',
            ],

            'end_time' => [
                'required',
                'date_format:H:i',
                'after:start_time',
            ],

            'idempotency_key' => [
                'required',
                'string',
                'max:64',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'facility_id.required' => 'Selecciona una instalación.',
            'date.required' => 'Selecciona una fecha.',
            'start_time.required' => 'Selecciona la hora de inicio.',
            'end_time.required' => 'Selecciona la hora de fin.',
            'end_time.after' => 'La hora de fin debe ser posterior a la de inicio.',
        ];
    }
}
