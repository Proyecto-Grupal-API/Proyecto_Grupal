<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class GuardarEventoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:150'], 'descripcion' => ['required', 'string', 'max:5000'], 'ubicacion' => ['required', 'string', 'max:255'],
            'fecha_hora_inicio' => ['required', 'date_format:Y-m-d\TH:i'], 'fecha_hora_fin' => ['required', 'date_format:Y-m-d\TH:i'],
            'fecha_inicio_registro' => ['required', 'date_format:Y-m-d\TH:i'], 'fecha_fin_registro' => ['required', 'date_format:Y-m-d\TH:i'],
            'costo' => ['required', 'numeric', 'min:0', 'max:100000', 'decimal:0,2'], 'capacidad' => ['required', 'integer', 'min:1', 'max:10000'], 'lista_espera' => ['required', 'boolean'],
        ];
    }

    public function datosEvento(): array
    {
        $data = $this->validated();
        foreach (['fecha_hora_inicio', 'fecha_hora_fin', 'fecha_inicio_registro', 'fecha_fin_registro'] as $campo) {
            $data[$campo] = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data[$campo], config('comunidad.eventos_timezone'))->utc();
        }
        $errors = [];
        if ($data['fecha_hora_inicio']->lte(now())) {
            $errors['fecha_hora_inicio'] = 'El evento debe iniciar en el futuro.';
        }
        if ($data['fecha_hora_fin']->lte($data['fecha_hora_inicio'])) {
            $errors['fecha_hora_fin'] = 'El final debe ser posterior al inicio.';
        }
        if ($data['fecha_fin_registro']->lte($data['fecha_inicio_registro'])) {
            $errors['fecha_fin_registro'] = 'El cierre debe ser posterior a la apertura de inscripción.';
        }
        if ($data['fecha_fin_registro']->gt($data['fecha_hora_inicio'])) {
            $errors['fecha_fin_registro'] = 'La inscripción debe cerrar a más tardar al iniciar el evento.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $data['costo'] = (float) $data['costo'];
        $data['capacidad'] = (int) $data['capacidad'];

        return $data;
    }
}
