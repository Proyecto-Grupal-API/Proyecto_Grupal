<?php

namespace App\Http\Controllers;

use App\Models\Organizacion;
use App\Models\RolOrganizacion;
use App\Models\User;
use App\Services\RegistroOrganizaciones;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class GestionOrganizacionesController extends Controller
{
    private function autorizar(): void
    {
        Gate::authorize('gestionarRegistro', Organizacion::class);
    }

    public function vista()
    {
        $this->autorizar();

        return Inertia::render('Modulo6/GestionOrganizaciones');
    }

    public function index(Request $r)
    {
        $this->autorizar();
        $d = $r->validate(['buscar' => ['nullable', 'string', 'max:120'], 'estado' => ['nullable', Rule::in(['activa', 'suspendida', 'configurando'])], 'page' => ['nullable', 'integer', 'min:1']]);
        $q = Organizacion::query();
        if ($r->filled('buscar')) {
            $q->where('nombre', 'like', '%'.$d['buscar'].'%');
        }
        if ($r->filled('estado')) {
            $q->where('estado', $d['estado']);
        }
        $p = $q->orderBy('nombre')->orderBy('_id')->paginate(12);

        return response()->json(['organizaciones' => $p->getCollection()->map(fn ($o) => $this->resumen($o)), 'page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()])->header('Cache-Control', 'private, no-store');
    }

    public function show(string $id)
    {
        $this->autorizar();
        $o = Organizacion::findOrFail($id);

        $historial = collect($o->historial_estados ?? []);
        $actores = User::whereIn('_id', $historial->pluck('actor_id')->unique())->get(['id', 'name'])->keyBy('id');

        return response()->json(['organizacion' => $this->resumen($o), 'historial' => $historial->map(fn ($h) => [...$h, 'actor_nombre' => $actores->get($h['actor_id'])?->name ?? 'Cuenta no disponible'])])->header('Cache-Control', 'private, no-store');
    }

    private function resumen(Organizacion $o): array
    {
        $rol = RolOrganizacion::where('organizacion_id', (string) $o->id)->where('slug_rol', 'presidencia')->whereNull('eliminado_en')->first();
        $user = $rol ? User::find($rol->usuario_id) : null;

        return [...$o->only(['id', 'nombre', 'tipo', 'descripcion', 'email', 'telefono', 'estado']), 'version_estado' => (int) ($o->version_estado ?? 0),
            'presidencia' => $rol ? ['nombre' => $user?->name ?? 'Usuario no disponible', 'matricula' => $user?->matricula, 'fecha_fin' => $rol->fecha_fin, 'vigente' => app(RegistroOrganizaciones::class)->tienePresidencia($o)] : null];
    }

    public function titular(Request $r)
    {
        $this->autorizar();
        $d = $r->validate(['matricula' => ['required', 'string', 'max:40']]);
        $u = User::where('matricula', $d['matricula'])->first();
        if (! $u) {
            throw ValidationException::withMessages(['matricula_presidencia' => 'No se encontró esa matrícula.']);
        }

        return response()->json(['titular' => $u->only(['name', 'matricula'])])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $r, RegistroOrganizaciones $registro)
    {
        $this->autorizar();
        $d = $r->validate(['clave_alta' => ['required', 'uuid'], 'nombre' => ['required', 'string', 'min:3', 'max:150'], 'tipo' => ['required', Rule::in(['asociacion', 'consejo'])], 'descripcion' => ['required', 'string', 'min:20', 'max:2000'], 'email' => ['required', 'email', 'max:255'], 'telefono' => ['nullable', 'string', 'max:30'], 'matricula_presidencia' => ['required', 'string', 'max:40'], 'fecha_fin_presidencia' => ['required', 'date_format:Y-m-d', 'after_or_equal:today']]);
        $d['telefono'] = $d['telefono'] ?? null;
        $o = $registro->crear($r->user(), $d);

        return response()->json(['message' => 'Organización creada con presidencia asignada.', 'organizacion' => $this->resumen($o)], 201);
    }

    public function completar(Request $r, string $id, RegistroOrganizaciones $registro)
    {
        $this->autorizar();
        $o = $registro->reanudar($r->user(), $id);

        return response()->json(['message' => 'Alta completada.', 'organizacion' => $this->resumen($o)]);
    }

    public function estado(Request $r, string $id, RegistroOrganizaciones $registro)
    {
        $this->autorizar();
        $d = $r->validate(['estado' => ['required', Rule::in(['activa', 'suspendida'])], 'motivo' => ['required', 'string', 'min:10', 'max:500'], 'version_estado' => ['required', 'integer', 'min:0'], 'clave_cambio' => ['required', 'uuid']]);
        $o = $registro->cambiarEstado($r->user(), $id, $d);

        return response()->json(['message' => 'Estado actualizado. Se conserva el historial.', 'organizacion' => $this->resumen($o)]);
    }
}
