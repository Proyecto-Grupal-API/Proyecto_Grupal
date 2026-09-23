<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class GuardarConsultaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $eleccion = $this->route('tipo') === 'votaciones';

        return [
            'revision' => ['sometimes', 'integer', 'min:1'],
            'titulo' => ['required', 'string', 'max:150'],
            'descripcion' => ['required', 'string', 'min:10', 'max:3000'],
            'fecha_inicio' => ['required', 'date_format:Y-m-d\TH:i'],
            'fecha_fin' => ['required', 'date_format:Y-m-d\TH:i', 'after:fecha_inicio'],
            'preguntas' => ['required', 'array', 'list', 'min:1', 'max:'.($eleccion ? 1 : 10)],
            'preguntas.*' => ['required', 'array:titulo,opciones'],
            'preguntas.*.titulo' => ['required', 'string', 'max:250'],
            'preguntas.*.opciones' => ['required', 'array', 'list', 'min:2', 'max:'.($eleccion ? 20 : 10)],
            'preguntas.*.opciones.*' => ['required', 'string', 'max:150'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            foreach ($this->input('preguntas') as $i => $p) {
                $opciones = array_map(fn ($s) => mb_strtolower(trim($s)), $p['opciones']);
                if (count(array_unique($opciones)) !== count($opciones)) {
                    $v->errors()->add("preguntas.$i.opciones", 'Cada opción debe ser distinta.');
                }
            }
            if (CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $this->input('fecha_fin'), 'America/Mexico_City')->lte(now())) {
                $v->errors()->add('fecha_fin', 'El cierre debe estar en el futuro.');
            }
        }];
    }

    public function datos(): array
    {
        $d = $this->safe()->except('revision');
        foreach (['fecha_inicio', 'fecha_fin'] as $campo) {
            $d[$campo] = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $d[$campo], 'America/Mexico_City')->utc();
        }
        $d['preguntas'] = array_map(fn ($p) => ['id' => (string) Str::uuid(), 'titulo' => $p['titulo'], 'opciones' => array_map(fn ($texto) => ['id' => (string) Str::uuid(), 'texto' => $texto], $p['opciones'])], array_values($d['preguntas']));

        return $d;
    }
}
