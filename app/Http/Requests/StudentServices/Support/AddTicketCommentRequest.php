<?php

namespace App\Http\Requests\StudentServices\Support;

use Illuminate\Foundation\Http\FormRequest;

class AddTicketCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => [
                'required',
                'string',
                'max:2000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'message.required' =>
                'El comentario no puede estar vacío.',

            'message.max' =>
                'El comentario no puede superar los 2000 caracteres.',
        ];
    }
}
