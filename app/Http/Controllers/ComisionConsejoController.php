<?php

namespace App\Http\Controllers;

use App\Models\ComisionConsejo;
use App\Models\IntegranteComision;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Services\ComisionesConsejo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ComisionConsejoController extends Controller
{
    public function index(Request $r)
    {
        $org = $this->consejo($r);
        $r->validate(['estado' => ['nullable', Rule::in(['activa', 'cerrada'])], 'page' => ['nullable', 'integer', 'min:1']]);
        $p = ComisionConsejo::where('organizacion_id', (string) $org->id)->where('estado', $r->input('estado', 'activa'))->orderBy('creado_en', 'desc')->orderBy('_id')->paginate(10);

        return response()->json(['comisiones' => $p->getCollection()->map(fn ($c) => $this->resumen($c)), 'puede_editar' => Gate::allows('update', $org), 'page' => $p->currentPage(), 'last_page' => $p->lastPage()])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $r, string $id)
    {
        $org = $this->consejo($r);
        $r->validate(['historial' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1']]);
        $c = ComisionConsejo::where('organizacion_id', (string) $org->id)->findOrFail($id);
        $q = IntegranteComision::where('comision_id', $id)->where('organizacion_id', (string) $org->id);
        if (! $r->boolean('historial')) {
            $q->whereNull('retirado_en');
        }
        $p = $q->orderBy('creado_en', 'desc')->orderBy('_id')->paginate(20);
        $miembros = MiembroOrganizacion::activos()->where('organizacion_id', (string) $org->id)->pluck('usuario_id')->all();

        return response()->json(['comision' => $this->resumen($c), 'integrantes' => $p->getCollection()->map(fn ($a) => [...$a->toArray(), 'vigente' => ! $a->retirado_en && $c->situacion === 'vigente' && in_array($a->usuario_id, $miembros, true)]), 'page' => $p->currentPage(), 'last_page' => $p->lastPage()])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $r, ComisionesConsejo $s)
    {
        $org = $this->consejo($r, true);
        $d = $this->datos($r, false);
        $c = $s->crear($r->user(), $org, $d);

        return response()->json(['message' => 'Comisión guardada.', 'comision' => $this->resumen($c)], $c->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $r, string $id, ComisionesConsejo $s)
    {
        $org = $this->consejo($r, true);
        $c = $s->editar($r->user(), $org, $id, $this->datos($r, true));

        return response()->json(['message' => 'Comisión actualizada.', 'comision' => $this->resumen($c)]);
    }

    public function asignar(Request $r, string $id, ComisionesConsejo $s)
    {
        $org = $this->consejo($r, true);
        $d = $r->validate(['usuario_id' => ['required', 'string', 'regex:/^[a-f0-9]{24}$/i'], 'funcion' => ['required', Rule::in(['integrante', 'coordinacion'])], 'clave_solicitud' => ['required', 'uuid']]);
        $a = $s->asignar($r->user(), $org, $id, $d);

        return response()->json(['message' => 'Participación guardada.', 'integrante' => $a], $a->wasRecentlyCreated ? 201 : 200);
    }

    public function retirar(Request $r, string $id, string $asignacion, ComisionesConsejo $s)
    {
        $org = $this->consejo($r, true);
        $d = $r->validate(['motivo' => ['required', 'string', 'min:10', 'max:1000']]);
        $s->retirar($r->user(), $org, $id, $asignacion, $d['motivo']);

        return response()->json(['message' => 'Participación retirada. Se conserva el historial.']);
    }

    public function cerrar(Request $r, string $id, ComisionesConsejo $s)
    {
        $org = $this->consejo($r, true);
        $d = $r->validate(['version' => ['required', 'integer', 'min:1'], 'motivo' => ['required', 'string', 'min:10', 'max:1000']]);
        $c = $s->cerrar($r->user(), $org, $id, $d);

        return response()->json(['message' => 'Comisión cerrada. Se conserva su historial.', 'comision' => $this->resumen($c)]);
    }

    private function consejo(Request $r, bool $editar = false): Organizacion
    {
        $org = $this->organizacion($r, $editar);
        abort_unless($org->tipo === 'consejo', 404);

        return $org;
    }

    private function datos(Request $r, bool $editar): array
    {
        return [...$r->validate(['nombre' => ['required', 'string', 'min:3', 'max:150'], 'proposito' => ['required', 'string', 'min:10', 'max:2000'], 'fecha_inicio' => ['required', 'date_format:Y-m-d'], 'fecha_fin' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'], ...($editar ? ['version' => ['required', 'integer', 'min:1']] : ['clave_solicitud' => ['required', 'uuid']])]), 'fecha_fin' => $r->input('fecha_fin')];
    }

    private function resumen(ComisionConsejo $c): array
    {
        return [...$c->toArray(), 'situacion' => $c->situacion];
    }
}
