<?php

namespace App\Http\Requests\StudentServices\Calendars;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCalendarRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'capacity' => ['required', 'integer', 'min:1', 'max:1000'],
            'open_time' => ['required', 'date_format:H:i'],
            'close_time' => ['required', 'date_format:H:i', 'after:open_time'],
            'slot_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'min_booking_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'max_booking_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'cancel_before_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'no_show_tolerance_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'max_active_per_student' => ['required', 'integer', 'min:1', 'max:20'],
            'max_advance_days' => ['required', 'integer', 'min:0', 'max:365'],
            'max_no_shows' => ['required', 'integer', 'min:0', 'max:50'],
            'no_show_window_days' => ['required', 'integer', 'min:1', 'max:365'],
            'operating_days' => ['required', 'array', 'min:1'],
            'operating_days.*' => ['integer', 'between:1,7'],
        ];
    }

    public function messages(): array
    {
        return [
            'close_time.after' => 'La hora de cierre debe ser posterior a la de apertura.',
            'operating_days.required' => 'Selecciona al menos un día de operación.',
            'operating_days.min' => 'Selecciona al menos un día de operación.',
        ];
    }
}
