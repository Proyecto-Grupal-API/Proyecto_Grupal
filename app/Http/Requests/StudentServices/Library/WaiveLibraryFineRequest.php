<?php

namespace App\Http\Requests\StudentServices\Library;

use Illuminate\Foundation\Http\FormRequest;

class WaiveLibraryFineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }
}
