<?php

namespace App\Http\Requests\StudentServices\Benefits;

class StoreBenefitAssignmentRequest extends BenefitApiRequest
{
    /**
     * Acepta el contrato v1 que ya genera Comunidad (tipo,
     * vigencia_inicio, vigencia_fin_exclusiva, clave_idempotencia) y los
     * nombres de la solicitud REQ-M6-E5-001 (beneficio_id, periodo).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'beneficiario_id' => ['required', 'string', 'max:64'],
            'organizacion_id' => ['required', 'string', 'max:64'],
            'convocatoria_id' => ['required', 'string', 'max:64'],
            'solicitud_id' => ['required', 'string', 'max:64'],
            'folio' => ['nullable', 'string', 'max:64'],
            'beneficio_id' => ['required_without:tipo', 'nullable', 'string', 'max:40'],
            'tipo' => ['required_without:beneficio_id', 'nullable', 'string', 'max:40'],
            'cantidad' => ['required', 'integer', 'min:1'],
            'periodo' => ['nullable', 'array'],
            'periodo.inicio' => ['required_without:vigencia_inicio', 'nullable', 'date'],
            'periodo.fin' => ['required_without:vigencia_fin_exclusiva', 'nullable', 'date'],
            'vigencia_inicio' => ['required_without:periodo.inicio', 'nullable', 'date'],
            'vigencia_fin_exclusiva' => ['required_without:periodo.fin', 'nullable', 'date'],
            'edificio' => ['nullable', 'string', 'max:60'],
            'clave_idempotencia' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'beneficiario_id.required' => 'beneficiario_id es obligatorio.',
            'organizacion_id.required' => 'organizacion_id es obligatorio.',
            'convocatoria_id.required' => 'convocatoria_id es obligatorio.',
            'solicitud_id.required' => 'solicitud_id es obligatorio.',
            'beneficio_id.required_without' => 'Indica beneficio_id (o tipo).',
            'tipo.required_without' => 'Indica beneficio_id (o tipo).',
            'cantidad.required' => 'cantidad es obligatoria.',
            'cantidad.integer' => 'cantidad debe ser un entero.',
            'cantidad.min' => 'cantidad debe ser al menos 1.',
            'periodo.inicio.required_without' => 'Indica el inicio de la vigencia (periodo.inicio o vigencia_inicio).',
            'periodo.fin.required_without' => 'Indica el fin exclusivo de la vigencia (periodo.fin o vigencia_fin_exclusiva).',
            'vigencia_inicio.required_without' => 'Indica el inicio de la vigencia (periodo.inicio o vigencia_inicio).',
            'vigencia_fin_exclusiva.required_without' => 'Indica el fin exclusivo de la vigencia (periodo.fin o vigencia_fin_exclusiva).',
            '*.date' => 'Las fechas deben ser ISO 8601.',
        ];
    }
}
