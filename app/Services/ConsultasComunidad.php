<?php

namespace App\Services;

use App\Models\ConsultaComunidad;
use App\Models\Eleccion;
use App\Models\Encuesta;
use App\Models\MiembroOrganizacion;
use App\Models\ParticipacionConsulta;
use App\Models\RespuestaEncuesta;
use App\Models\User;
use App\Models\Voto;
use Illuminate\Validation\ValidationException;

class ConsultasComunidad
{
    public function consulta(string $tipo): ConsultaComunidad
    {
        abort_unless(in_array($tipo, ['encuestas', 'votaciones']), 404);

        return $tipo === 'encuestas' ? new Encuesta : new Eleccion;
    }

    public function participacion(string $tipo): ParticipacionConsulta
    {
        return $tipo === 'encuestas' ? new RespuestaEncuesta : new Voto;
    }

    public function padron(ConsultaComunidad $c): array
    {
        $miembros = MiembroOrganizacion::activos()->where('organizacion_id', $c->organizacion_id)->pluck('usuario_id');
        $ids = User::whereIn('_id', $miembros)->limit(5001)->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
        if (count($ids) > 5000) {
            throw ValidationException::withMessages(['padron' => 'El máximo local es de 5000 integrantes por consulta.']);
        }

        return $ids;
    }

    public function firma(ConsultaComunidad $c, array $ids): string
    {
        return hash_hmac('sha256', json_encode([$c->getTable(), (string) $c->id, $c->revision, $ids], JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function resultados(string $tipo, ConsultaComunidad $c): array
    {
        // Solo agregados; ninguna API devuelve respuestas individuales ni listas del padrón.
        abort_unless($c->fase() === 'cerrada', 403, 'Los resultados estarán disponibles al cerrar.');
        $respuestas = $this->participacion($tipo)->where('consulta_id', (string) $c->id)->get(['respuestas']);
        $total = $respuestas->count();
        $preguntas = array_map(function ($p) use ($respuestas, $total) {
            $conteos = [];
            foreach ($respuestas as $r) {
                foreach ($r->respuestas as $respuesta) {
                    if ($respuesta['pregunta_id'] === $p['id']) {
                        $conteos[$respuesta['opcion_id']] = ($conteos[$respuesta['opcion_id']] ?? 0) + 1;
                    }
                }
            }

            return ['id' => $p['id'], 'titulo' => $p['titulo'], 'opciones' => array_map(fn ($o) => [...$o, 'cantidad' => $conteos[$o['id']] ?? 0, 'porcentaje' => $total ? round(($conteos[$o['id']] ?? 0) / $total * 100, 1) : 0], $p['opciones'])];
        }, $c->preguntas);

        return ['total' => $total, 'total_padron' => $c->total_padron, 'participacion_porcentaje' => $c->total_padron ? round($total / $c->total_padron * 100, 1) : 0, 'preguntas' => $preguntas];
    }
}
