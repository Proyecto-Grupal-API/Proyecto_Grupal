<?php

namespace App\Http\Controllers;

use App\Models\DocumentoConsulta;
use App\Models\Eleccion;
use App\Models\ReporteTransparencia;
use App\Services\ConsultasComunidad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TransparenciaController extends Controller
{
    public function index(Request $request)
    {
        $org = $this->organizacion($request);
        $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'buscar' => ['nullable', 'string', 'max:120']]);
        $gestion = Gate::allows('update', $org);
        $q = ReporteTransparencia::where('organizacion_id', (string) $org->id)->where('version', 1);
        if (! $gestion) {
            $q->where('estado', 'publicado')->where('publicado_en', '<=', now());
        }
        if ($request->filled('buscar')) {
            $q->where('titulo', 'like', '%'.$request->buscar.'%');
        }
        $p = $q->orderBy('generado_en', 'desc')->orderBy('_id')->paginate(10);
        $reportes = $p->getCollection()->map(fn ($r) => $r->only(['id', 'titulo', 'fecha_inicio', 'fecha_fin', 'generado_en', 'publicado_en', 'estado']));
        $eleccion = DocumentoConsulta::en('elecciones')->where('organizacion_id', (string) $org->id)->where(function ($q) {
            $q->where('estado', 'cerrada')->orWhere(function ($q) {
                $q->where('estado', 'publicada')->whereNotNull('revision')->where('fecha_fin', '<=', now());
            });
        })->orderBy('fecha_inicio', 'desc')->first();
        $resultados = collect();
        $totalVotos = 0;
        if ($eleccion && $eleccion->revision) {
            $modelo = Eleccion::findOrFail($eleccion->id);
            $agregado = app(ConsultasComunidad::class)->resultados('votaciones', $modelo);
            $totalVotos = $agregado['total'];
            $resultados = collect($agregado['preguntas'][0]['opciones'])->map(fn ($o) => ['id' => $o['id'], 'planilla' => $o['texto'], 'votos' => $o['cantidad'], 'porcentaje' => $o['porcentaje']]);
            $eleccion->criterios_votantes = 'Padrón de '.$modelo->total_padron.' integrantes al publicar';
        } elseif ($eleccion) {
            $opciones = DocumentoConsulta::en('opciones_elecciones')->where('organizacion_id', (string) $org->id)->where('eleccion_id', (string) $eleccion->id)->get();
            $votos = DocumentoConsulta::en('votos')->where('organizacion_id', (string) $org->id)->where('eleccion_id', (string) $eleccion->id)->whereIn('opcion_eleccion_id', $opciones->pluck('id'))->get()->countBy('opcion_eleccion_id');
            $totalVotos = $votos->sum();
            $resultados = $opciones->map(fn ($o) => ['id' => $o->id, 'planilla' => $o->cargo, 'votos' => $votos->get($o->id, 0), 'porcentaje' => $totalVotos ? round($votos->get($o->id, 0) / $totalVotos * 100) : 0]);
        }

        $eleccion = $eleccion?->only(['id', 'titulo', 'criterios_votantes', 'fecha_inicio', 'fecha_fin']);

        return response()->json([...compact('reportes', 'eleccion', 'resultados', 'totalVotos'), 'page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'puede_gestionar' => $gestion])->header('Cache-Control', 'private, no-store');
    }
}
