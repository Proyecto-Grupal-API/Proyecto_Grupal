<?php

namespace App\Http\Requests\StudentServices\RestSpaces;

use Illuminate\Foundation\Http\FormRequest;

class StoreRestBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rest_space_id' => ['required', 'string', 'size:24'],
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'rest_space_id.required' => 'Selecciona un espacio.',
            'date.required' => 'Selecciona una fecha.',
            'start_time.required' => 'Selecciona la hora de inicio.',
            'start_time.date_format' => 'La hora de inicio no es válida.',
            'duration_minutes.required' => 'Selecciona la duración.',
        ];
    }
}
