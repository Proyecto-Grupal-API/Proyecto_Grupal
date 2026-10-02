<?php

namespace App\Http\Requests\StudentServices\Library;

use Illuminate\Foundation\Http\FormRequest;

class FulfillBookReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'loan_days' => [
                'nullable',
                'integer',
                'min:1',
                'max:30',
            ],
        ];
    }
}
