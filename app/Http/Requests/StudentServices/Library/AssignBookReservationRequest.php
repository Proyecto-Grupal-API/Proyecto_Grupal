<?php

namespace App\Http\Requests\StudentServices\Library;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AssignBookReservationRequest extends FormRequest
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
            'pickup_hours' => [
                'nullable',
                'integer',
                'min:1',
                'max:168',
            ],
        ];
    }
}
