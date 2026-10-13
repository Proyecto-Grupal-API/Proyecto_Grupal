<?php

namespace App\Http\Requests\StudentServices\Library;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PayLibraryFineRequest extends FormRequest
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
            'payment_reference_id' => [
                'required',
                'string',
                'max:150',
            ],
        ];
    }
}
