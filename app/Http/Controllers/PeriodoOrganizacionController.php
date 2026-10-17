<?php

namespace App\Http\Controllers;

use App\Models\PeriodoOrganizacion;
use App\Models\RolOrganizacion;
use App\Services\PeriodosOrganizacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PeriodoOrganizacionController extends Controller
{
    public function index(Request $r)
    {
        $org = $this->organizacion($r);
        $r->validate(['situacion' => ['nullable', Rule::in(['todos', 'programado', 'vigente', 'finalizado', 'cancelado'])], 'page' => ['nullable', 'integer', 'min:1']]);
        $q = PeriodoOrganizacion::where('organizacion_id', (string) $org->id);
        $situacion = $r->input('situacion', 'todos');
        if ($situacion === 'cancelado') {
            $q->where('estado', 'cancelado');
        } elseif ($situacion !== 'todos') {
            $q->where('estado', 'registrado');
            $hoy = today()->toDateString();
            if ($situacion === 'programado') {
                $q->where('fecha_inicio', '>', $hoy);
            } elseif ($situacion === 'finalizado') {
                $q->where('fecha_fin', '<', $hoy);
            } else {
                $q->where('fecha_inicio', '<=', $hoy)->where('fecha_fin', '>=', $hoy);
            }
        }
        $p = $q->orderBy('fecha_inicio', 'desc')->orderBy('_id')->paginate(10);
        $usados = RolOrganizacion::where('organizacion_id', (string) $org->id)->whereIn('periodo_id', $p->getCollection()->pluck('id'))->get(['periodo_id'])->pluck('periodo_id')->unique()->all();

        return response()->json(['periodos' => $p->getCollection()->map(fn ($t) => $this->resumen($t, in_array((string) $t->id, $usados, true))), 'puede_editar' => Gate::allows('update', $org), 'page' => $p->currentPage(), 'last_page' => $p->lastPage()])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $r, string $id, PeriodosOrganizacion $s)
    {
        $org = $this->organizacion($r);
        $r->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $t = PeriodoOrganizacion::where('organizacion_id', (string) $org->id)->findOrFail($id);
        $p = RolOrganizacion::where('organizacion_id', (string) $org->id)->where('periodo_id', $id)->orderBy('creado_en', 'desc')->orderBy('_id')->paginate(20);

        return response()->json(['periodo' => $this->resumen($t, $s->enUso($t)), 'cargos' => $p->getCollection()->map(fn ($c) => [...$c->only(['id', 'usuario_id', 'slug_rol', 'fecha_inicio', 'fecha_fin', 'eliminado_en']), 'vigente' => $c->vigente]), 'page' => $p->currentPage(), 'last_page' => $p->lastPage()])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $r, PeriodosOrganizacion $s)
    {
        $org = $this->organizacion($r, true);
        $p = $s->crear($r->user(), $org, $this->datos($r, false));

        return $this->respuesta($p, $s, 'Periodo guardado.', $p->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $r, string $id, PeriodosOrganizacion $s)
    {
        $org = $this->organizacion($r, true);

        return $this->respuesta($s->editar($r->user(), $org, $id, $this->datos($r, true)), $s, 'Periodo actualizado.');
    }

    public function cancelar(Request $r, string $id, PeriodosOrganizacion $s)
    {
        $org = $this->organizacion($r, true);
        $d = $r->validate(['version' => ['required', 'integer', 'min:1'], 'motivo' => ['required', 'string', 'min:10', 'max:1000']]);

        return $this->respuesta($s->cancelar($r->user(), $org, $id, $d), $s, 'Periodo cancelado. Se conserva el registro.');
    }

    private function datos(Request $r, bool $editar): array
    {
        $d = $r->validate(['nombre' => ['required', 'string', 'min:3', 'max:150'], 'descripcion' => ['nullable', 'string', 'max:1000'], 'fecha_inicio' => ['required', 'date_format:Y-m-d'], 'fecha_fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'], ...($editar ? ['version' => ['required', 'integer', 'min:1']] : ['clave_solicitud' => ['required', 'uuid']])]);

        return [...$d, 'descripcion' => $d['descripcion'] ?? ''];
    }

    private function respuesta(PeriodoOrganizacion $p, PeriodosOrganizacion $s, string $mensaje, int $status = 200)
    {
        return response()->json(['message' => $mensaje, 'periodo' => $this->resumen($p, $s->enUso($p))], $status)->header('Cache-Control', 'private, no-store');
    }

    private function resumen(PeriodoOrganizacion $p, bool $usado): array
    {
        return [...$p->toArray(), 'situacion' => $p->situacion, 'en_uso' => $usado];
    }
}
