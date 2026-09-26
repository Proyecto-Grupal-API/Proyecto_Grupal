<?php

namespace App\Http\Controllers;

use App\Http\Requests\CrearReporteRequest;
use App\Models\AuditoriaComunidad;
use App\Models\ReporteTransparencia;
use App\Services\MetricasComunidad;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class ReporteController extends Controller
{
    public function store(CrearReporteRequest $r)
    {
        $org = $this->organizacion($r, true);
        $d = $r->validated();
        $d['descripcion'] = $d['descripcion'] ?? '';

        return $this->bloquear('crear:'.$org->id.':'.$d['clave_solicitud'], function () use ($r, $org, $d) {
            $previo = ReporteTransparencia::where('organizacion_id', (string) $org->id)->where('clave_solicitud', $d['clave_solicitud'])->first();
            if ($previo) {
                foreach (['titulo', 'descripcion', 'fecha_inicio', 'fecha_fin'] as $campo) {
                    abort_unless($previo->{$campo} === $d[$campo], 409, 'La clave de creación ya se usó con otros datos. Abre un nuevo borrador.');
                }

                return response()->json(['message' => 'Este borrador ya estaba generado.', 'reporte' => $this->resumen($previo)]);
            }
            $corte = CarbonImmutable::now();
            $metricas = app(MetricasComunidad::class)->calcular((string) $org->id, $d['fecha_inicio'], $d['fecha_fin'], $corte);
            $reporte = ReporteTransparencia::create([...$d, 'organizacion_id' => (string) $org->id, 'organizacion_nombre' => $org->nombre, 'estado' => 'borrador', 'version' => 1, 'generado_en' => $corte, 'metricas' => $metricas]);
            $this->auditar($r, $reporte, 'reporte_generado');

            return response()->json(['message' => 'Borrador generado. Revisa las cifras antes de publicar.', 'reporte' => $this->resumen($reporte)], 201);
        });
    }

    public function show(Request $r, string $id)
    {
        return response()->json($this->resumen($this->buscar($r, $id)))->header('Cache-Control', 'private, no-store');
    }

    public function publicar(Request $r, string $id)
    {
        $this->organizacion($r, true);
        $r->validate(['confirmar' => ['required', 'accepted']]);

        return $this->bloquear($id, function () use ($r, $id) {
            $this->organizacion($r, true);
            $reporte = $this->buscar($r, $id);
            if ($reporte->estado === 'publicado') {
                return response()->json(['message' => 'El reporte ya estaba publicado.']);
            }
            abort_unless($reporte->estado === 'borrador', 422, 'Solo se publican borradores. Genera otro reporte si necesitas corregirlo.');
            $reporte->update(['estado' => 'publicado', 'publicado_en' => now()]);
            $this->auditar($r, $reporte, 'reporte_publicado');

            return response()->json(['message' => 'Reporte publicado para los integrantes de la organización.']);
        });
    }

    public function retirar(Request $r, string $id)
    {
        $this->organizacion($r, true);
        $d = $r->validate(['motivo' => ['required', 'string', 'min:10', 'max:500']]);

        return $this->bloquear($id, function () use ($r, $id, $d) {
            $this->organizacion($r, true);
            $reporte = $this->buscar($r, $id);
            if ($reporte->estado === 'retirado') {
                return response()->json(['message' => 'El reporte ya estaba retirado.']);
            }
            $reporte->update(['estado' => 'retirado', 'retirado_en' => now(), 'motivo_retiro' => $d['motivo']]);
            $this->auditar($r, $reporte, 'reporte_retirado');

            return response()->json(['message' => 'Reporte retirado. Se conserva para revisión de presidencia.']);
        });
    }

    public function imprimir(Request $r, string $id)
    {
        return response()->view('reportes.imprimir', ['reporte' => $this->resumen($this->buscar($r, $id))])->header('Cache-Control', 'private, no-store');
    }

    public function csv(Request $r, string $id)
    {
        $reporte = $this->buscar($r, $id);

        return response()->streamDownload(function () use ($reporte) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            $celda = fn ($s) => preg_match('/^[\s]*[=+@-]/u', (string) $s) ? "'".$s : $s;
            $fila = function ($datos) use ($f, $celda) {
                fputcsv($f, array_map($celda, $datos), ',', '"', '');
            };
            $fila(['Reporte', $reporte->titulo]);
            $fila(['Organización', $reporte->organizacion_nombre]);
            $fila(['Estado', $reporte->estado]);
            $fila(['Desde', $reporte->fecha_inicio, 'Hasta (inclusive)', $reporte->fecha_fin]);
            $fila(['Generado UTC', $reporte->generado_en->toIso8601String()]);
            $fila(['Descripción', $reporte->descripcion]);
            $fila(['Grupo', 'Indicador', 'Valor', 'Unidad', 'Criterio']);
            foreach ($reporte->metricas as $m) {
                $fila([$m['grupo'], $m['etiqueta'], $m['valor'], $m['unidad'], $m['criterio']]);
            }
            fclose($f);
        }, 'reporte-'.$reporte->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function resumen(ReporteTransparencia $r): array
    {
        return $r->only(['id', 'organizacion_nombre', 'titulo', 'descripcion', 'fecha_inicio', 'fecha_fin', 'generado_en', 'publicado_en', 'estado', 'motivo_retiro', 'retirado_en', 'version', 'metricas']);
    }

    private function buscar(Request $r, string $id): ReporteTransparencia
    {
        $org = $this->organizacion($r);
        $q = ReporteTransparencia::where('organizacion_id', (string) $org->id)->where('version', 1);
        if (! Gate::allows('update', $org)) {
            $q->where('estado', 'publicado')->where('publicado_en', '<=', now());
        }

        return $q->findOrFail($id);
    }

    private function bloquear(string $id, callable $fn)
    {
        try {
            return Cache::store('file')->lock('reporte:'.$id, 60)->block(5, $fn);
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'Hay otra operación en curso. Intenta de nuevo.'], 409);
        }
    }

    private function auditar(Request $r, ReporteTransparencia $reporte, string $accion): void
    {
        AuditoriaComunidad::create(['organizacion_id' => $reporte->organizacion_id, 'usuario_id' => (string) $r->user()->id, 'accion' => $accion, 'entidad_id' => (string) $reporte->id, 'antes' => null, 'despues' => ['estado' => $reporte->estado, 'version' => $reporte->version, 'fecha_inicio' => $reporte->fecha_inicio, 'fecha_fin' => $reporte->fecha_fin, 'motivo' => $reporte->motivo_retiro]]);
    }
}
