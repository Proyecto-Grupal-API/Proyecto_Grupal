<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CrearReporteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['clave_solicitud' => ['required', 'uuid'], 'titulo' => ['required', 'string', 'max:150'], 'descripcion' => ['nullable', 'string', 'max:1500'], 'fecha_inicio' => ['required', 'date_format:Y-m-d'], 'fecha_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio']];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $inicio = CarbonImmutable::createFromFormat('!Y-m-d', $this->fecha_inicio, 'America/Mexico_City');
            $fin = CarbonImmutable::createFromFormat('!Y-m-d', $this->fecha_fin, 'America/Mexico_City');
            if ($fin->gt(CarbonImmutable::today('America/Mexico_City'))) {
                $v->errors()->add('fecha_fin', 'El periodo no puede terminar después de hoy.');
            }
            if ($inicio->diffInDays($fin) >= 366) {
                $v->errors()->add('fecha_fin', 'El periodo máximo es de 366 días, incluyendo inicio y fin.');
            }
        }];
    }
}
