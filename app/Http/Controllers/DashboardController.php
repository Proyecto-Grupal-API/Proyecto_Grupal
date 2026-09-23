<?php

namespace App\Http\Controllers;

use App\Models\Campana;
use App\Models\ConvocatoriaBeca;
use App\Models\Eleccion;
use App\Models\Encuesta;
use App\Models\Evento;
use App\Models\Mensaje;
use App\Models\MiembroOrganizacion;
use App\Models\RegistroEvento;
use App\Models\ReporteTransparencia;
use App\Models\RespuestaEncuesta;
use App\Models\SolicitudBeca;
use App\Models\Voto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $org = $this->organizacion($request);
        $id = (string) $org->id;
        $usuario = (string) $request->user()->id;
        $gestion = Gate::allows('update', $org);
        $solicitudes = SolicitudBeca::where('organizacion_id', $id);
        if ($gestion) {
            $solicitudes->whereNotNull('enviado_en')->where('estado', '!=', 'borrador');
        } else {
            $solicitudes->where('usuario_id', $usuario);
        }
        $pendientes = (clone $solicitudes)->whereIn('estado', ['pendiente', 'en_revision'])->count();
        $ultimas = (clone $solicitudes)->orderBy('creado_en', 'desc')->orderBy('_id')->limit(5)->get();
        $convocatorias = ConvocatoriaBeca::where('organizacion_id', $id)->whereIn('_id', $ultimas->pluck('convocatoria_id'))->get()->keyBy('id');
        $ultimas = $ultimas->filter(fn ($s) => $convocatorias->has($s->convocatoria_id))->map(fn ($s) => [...$s->only(['id', 'estado', 'folio', 'creado_en']), 'beca' => $convocatorias->get($s->convocatoria_id)->titulo])->values();
        $eventos = Evento::where('organizacion_id', $id)->where('estado', 'publicado')->where('fecha_hora_fin', '>', now());
        $proximo = (clone $eventos)->orderBy('fecha_hora_inicio')->first();
        $reserva = $proximo ? RegistroEvento::where('evento_id', (string) $proximo->id)->where('estado', 'confirmada') : null;
        $consultas = [];
        foreach (['encuestas' => [Encuesta::class, RespuestaEncuesta::class], 'votaciones' => [Eleccion::class, Voto::class]] as $tipo => [$modelo, $respuesta]) {
            $respondidas = $respuesta::where('organizacion_id', $id)->where('usuario_id', $usuario)->pluck('consulta_id');
            $consultas[$tipo] = $modelo::where('organizacion_id', $id)->whereNotNull('revision')->where('estado', 'publicada')->where('fecha_inicio', '<=', now())->where('fecha_fin', '>', now())->where('padron', $usuario)->whereNotIn('_id', $respondidas)->count();
        }

        return response()->json([
            'generado_en' => now()->toIso8601String(), 'puede_gestionar' => $gestion, 'organizacion' => $org->nombre,
            'miembrosActivos' => MiembroOrganizacion::activos()->where('organizacion_id', $id)->count(),
            'eventosActivos' => (clone $eventos)->count(), 'cajaDisponible' => null, 'becasPendientes' => $pendientes, 'ultimasBecas' => $ultimas,
            'proximoEvento' => $proximo ? ['id' => (string) $proximo->id, 'nombre' => $proximo->titulo, 'lugar' => $proximo->ubicacion, 'fecha' => $proximo->fecha_hora_inicio->toIso8601String(), 'en_curso' => $proximo->fecha_hora_inicio->lte(now()), 'reservas' => (clone $reserva)->count(), 'capacidad' => $proximo->capacidad, 'asistencias' => (clone $reserva)->where('estado_asistencia', 'asistio')->whereNotNull('asistio_en')->count()] : null,
            'personales' => ['no_leidos' => Mensaje::where('usuario_id', $usuario)->whereNull('eliminado_en')->whereNull('archivado_en')->whereNull('leido_en')->count(), 'consultas' => $consultas, 'boletos' => RegistroEvento::where('usuario_id', $usuario)->whereIn('evento_id', (clone $eventos)->pluck('id'))->whereIn('estado', ['confirmada', 'espera'])->count()],
            'gestion' => $gestion ? ['campanas_pendientes' => Campana::where('organizacion_id', $id)->whereIn('estado', ['en_cola', 'enviando', 'error', 'pausada'])->count(), 'reportes_borrador' => ReporteTransparencia::where('organizacion_id', $id)->where('version', 1)->where('estado', 'borrador')->count()] : null,
        ])->header('Cache-Control', 'private, no-store');
    }
}
