<?php

namespace App\Http\Requests;

use App\Models\TipoBeneficio;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class GuardarConvocatoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:150'], 'descripcion' => ['required', 'string', 'max:5000'],
            'requisitos' => ['required', 'string', 'max:5000'], 'tipo_beneficio_id' => ['required', 'string', 'regex:/^[a-f0-9]{24}$/i'],
            'monto' => ['required', 'regex:/^\d{1,6}(\.\d{1,2})?$/'], 'cantidad' => ['required', 'integer', 'min:1', 'max:10000'],
            'total_espacios' => ['required', 'integer', 'min:1', 'max:10000'],
            'fecha_inicio' => ['required', 'date_format:Y-m-d'], 'fecha_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
            'vigencia_inicio' => ['required', 'date_format:Y-m-d', 'after:fecha_fin'], 'vigencia_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:vigencia_inicio'],
            'requisitos_documentos' => ['present', 'array', 'max:5'], 'requisitos_documentos.*' => ['required', 'string', 'max:150', 'distinct'],
        ];
    }

    public function datos(): array
    {
        $d = $this->validated();
        $tipo = TipoBeneficio::find($d['tipo_beneficio_id']);
        if (! $tipo || ! in_array($tipo->equipo, [2, 5], true)) {
            throw ValidationException::withMessages(['tipo_beneficio_id' => 'Selecciona un tipo de beneficio válido.']);
        }
        $partes = explode('.', (string) $d['monto']);
        $centavos = ((int) $partes[0]) * 100 + (int) str_pad($partes[1] ?? '', 2, '0');
        if ($tipo->es_monetario && $centavos < 1) {
            throw ValidationException::withMessages(['monto' => 'El monto debe ser mayor que cero.']);
        }
        unset($d['monto']);
        foreach (['fecha_inicio', 'fecha_fin', 'vigencia_inicio', 'vigencia_fin'] as $campo) {
            $fecha = CarbonImmutable::createFromFormat('!Y-m-d', $d[$campo], 'America/Mexico_City');
            $d[$campo] = in_array($campo, ['fecha_fin', 'vigencia_fin']) ? $fecha->addDay()->utc() : $fecha->utc();
        }
        if ($d['fecha_fin']->lte(now())) {
            throw ValidationException::withMessages(['fecha_fin' => 'La recepción debe cerrar en el futuro.']);
        }

        return [...$d, 'monto_centavos' => $tipo->es_monetario ? $centavos : 0, 'cantidad' => $tipo->es_monetario ? 1 : (int) $d['cantidad'],
            'total_espacios' => (int) $d['total_espacios'], 'beneficio' => ['slug' => $tipo->slug, 'nombre' => $tipo->nombre, 'equipo' => $tipo->equipo, 'es_monetario' => $tipo->es_monetario, 'es_servicio' => $tipo->es_servicio],
            'requiere_documentos' => count($d['requisitos_documentos']) > 0];
    }
}
