<?php

namespace App\Http\Requests\StudentServices\Calendars;

use App\Services\StudentServices\Calendars\BookableResources;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCalendarBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resource_type' => ['required', Rule::in(BookableResources::types())],
            'resource_id' => ['required', 'string', 'size:24'],
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'resource_type.required' => 'Selecciona un recurso.',
            'resource_id.required' => 'Selecciona un recurso.',
            'resource_id.size' => 'Selecciona un recurso.',
            'date.required' => 'Selecciona la fecha del bloqueo.',
            'start_time.required' => 'Indica la hora de inicio.',
            'end_time.required' => 'Indica la hora de fin.',
            'end_time.after' => 'La hora de fin debe ser posterior a la de inicio.',
            'reason.required' => 'Indica el motivo del bloqueo.',
        ];
    }
}
