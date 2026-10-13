<?php

namespace App\Http\Requests\StudentServices\Benefits;

class CancelBenefitAssignmentRequest extends BenefitApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'max:500'],
            'referencia_origen' => ['nullable', 'string', 'max:150'],
            'clave_idempotencia' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Indica el motivo de la cancelación.',
        ];
    }
}
