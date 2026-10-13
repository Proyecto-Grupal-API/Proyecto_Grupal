<?php

namespace App\Http\Requests\StudentServices\Benefits;

class ListBenefitAssignmentsRequest extends BenefitApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'clave_idempotencia' => ['nullable', 'string', 'max:150'],
            'solicitud_id' => ['nullable', 'string', 'max:64'],
            'convocatoria_id' => ['nullable', 'string', 'max:64'],
            'beneficiario_id' => ['nullable', 'string', 'max:64'],
            'organizacion_id' => ['nullable', 'string', 'max:64'],
            'beneficio_id' => ['nullable', 'string', 'max:40'],
            'cursor' => ['nullable', 'string', 'max:64'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
