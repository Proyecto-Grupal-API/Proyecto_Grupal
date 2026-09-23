<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GuardarCampanaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:150'], 'asunto' => ['required', 'string', 'max:150'], 'cuerpo' => ['required', 'string', 'min:10', 'max:6000'],
            'audiencia' => ['required', Rule::in(['miembros', 'evento_confirmados', 'evento_espera', 'beca_solicitudes', 'beca_aprobadas'])],
            'referencia_id' => ['required_unless:audiencia,miembros', 'nullable', 'string', 'regex:/^[a-f0-9]{24}$/i'],
            'accion' => ['required', Rule::in(['ninguna', 'eventos', 'becas', 'mis_boletos', 'boleto_evento', 'solicitud_beca'])],
            'texto_accion' => ['required_unless:accion,ninguna', 'nullable', 'string', 'max:60'],
        ];
    }

    public function datos(): array
    {
        $d = $this->validated();
        if (($d['accion'] === 'boleto_evento' && ! str_starts_with($d['audiencia'], 'evento_')) || ($d['accion'] === 'solicitud_beca' && ! str_starts_with($d['audiencia'], 'beca_'))) {
            throw ValidationException::withMessages(['accion' => 'La acción debe corresponder a la audiencia seleccionada.']);
        }
        if ($d['audiencia'] === 'miembros') {
            $d['referencia_id'] = null;
        }
        if ($d['accion'] === 'ninguna') {
            $d['texto_accion'] = null;
        }

        return $d;
    }
}
