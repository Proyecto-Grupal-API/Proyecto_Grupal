<?php

namespace App\Http\Controllers;

use App\Models\Evento;
use App\Models\MiembroOrganizacion;
use App\Models\RegistroEvento;
use App\Models\StaffEvento;
use App\Models\User;
use App\Services\InscripcionesEvento;
use App\Services\ResponsabilidadesComunidad;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class StaffEventoController extends Controller
{
    public function vista(Request $r)
    {
        $this->organizacion($r);

        return Inertia::render('Modulo6/Staff');
    }

    public function index(Request $r)
    {
        $org = $this->organizacion($r);
        $r->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $q = Evento::where('organizacion_id', (string) $org->id)->where('estado', 'publicado')->where('fecha_hora_fin', '>', now());
        if (! Gate::allows('update', $org)) {
            $q->whereIn('_id', StaffEvento::where('organizacion_id', (string) $org->id)->where('usuario_id', (string) $r->user()->id)->whereNull('eliminado_en')->pluck('evento_id'));
        }
        $p = $q->orderBy('fecha_hora_inicio')->orderBy('_id')->paginate(12);

        return response()->json(['eventos' => $p->getCollection()->map(fn ($e) => $this->resumen($e)), 'page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'organizacion' => $org->nombre])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $r, string $id)
    {
        $org = $this->organizacion($r);
        $e = Evento::where('organizacion_id', (string) $org->id)->findOrFail($id);
        Gate::authorize('validarAcceso', $e);
        abort_unless($e->estado === 'publicado' && $e->fecha_hora_fin?->gt(now()), 404);

        return response()->json(['evento' => $this->resumen($e)])->header('Cache-Control', 'private, no-store');
    }

    private function resumen(Evento $e): array
    {
        $abre = $e->fecha_hora_inicio->copy()->subMinutes(config('comunidad.checkin_minutos_antes'));
        $q = RegistroEvento::where('evento_id', (string) $e->id)->where('estado', 'confirmada');

        return [...$e->only(['id', 'titulo', 'ubicacion', 'fecha_hora_inicio', 'fecha_hora_fin']), 'acceso_abre_en' => $abre,
            'acceso_abierto' => now()->gte($abre) && now()->lt($e->fecha_hora_fin),
            'asistencias' => (clone $q)->where('estado_asistencia', 'asistio')->count(),
            'por_ingresar' => (clone $q)->whereIn('estado_pago', ['pagado', 'exento'])->where('estado_asistencia', '!=', 'asistio')->count()];
    }

    public function asignados(Request $r, string $id)
    {
        $org = $this->organizacion($r, true);
        Evento::where('organizacion_id', (string) $org->id)->findOrFail($id);
        $asignados = StaffEvento::where('evento_id', $id)->whereNull('eliminado_en')->get();
        $ids = MiembroOrganizacion::activos()->where('organizacion_id', (string) $org->id)->pluck('usuario_id');
        $usuarios = User::whereIn('_id', $ids->merge($asignados->pluck('usuario_id'))->unique())->orderBy('name')->get();

        return response()->json(['integrantes' => $usuarios->filter(fn ($u) => $ids->contains((string) $u->id))->map(fn ($u) => $u->only(['id', 'name', 'matricula']))->values(),
            'staff' => $asignados->map(fn ($s) => ['id' => (string) $s->id, 'usuario_id' => $s->usuario_id, 'nombre' => $usuarios->firstWhere('id', $s->usuario_id)?->name ?? 'Usuario no disponible'])])->header('Cache-Control', 'private, no-store');
    }

    public function asignar(Request $r, string $id)
    {
        $d = $r->validate(['usuario_id' => ['required', 'string', 'regex:/^[a-f0-9]{24}$/i']]);

        return $this->modificar($r, $id, function ($e) use ($r, $d) {
            if ($e->estado === 'cancelado' || ! $e->fecha_hora_fin?->gt(now())) {
                throw ValidationException::withMessages(['staff' => 'El evento terminó o fue cancelado.']);
            }
            if (! User::find($d['usuario_id']) || ! MiembroOrganizacion::activos()->where('organizacion_id', $e->organizacion_id)->where('usuario_id', $d['usuario_id'])->exists()) {
                throw ValidationException::withMessages(['usuario_id' => 'Selecciona un integrante activo de esta organización.']);
            }
            $s = StaffEvento::firstOrNew(['evento_id' => (string) $e->id, 'usuario_id' => $d['usuario_id']]);
            if (! $s->exists || $s->eliminado_en !== null) {
                $antes = $s->exists ? $s->toArray() : null;
                $s->fill(['organizacion_id' => $e->organizacion_id, 'asignado_por' => (string) $r->user()->id, 'eliminado_en' => null])->save();
                app(InscripcionesEvento::class)->auditar($e, $r->user(), 'evento_staff_asignado', (string) $s->id, $antes, $s->toArray());
            }
        });
    }

    public function retirar(Request $r, string $id, string $staff)
    {
        return $this->modificar($r, $id, function ($e) use ($r, $staff) {
            $s = StaffEvento::where('evento_id', (string) $e->id)->findOrFail($staff);
            if ($s->eliminado_en === null) {
                $antes = $s->toArray();
                $s->update(['eliminado_en' => now()]);
                app(InscripcionesEvento::class)->auditar($e, $r->user(), 'evento_staff_retirado', (string) $s->id, $antes, $s->toArray());
            }
        });
    }

    private function modificar(Request $r, string $id, callable $accion)
    {
        $org = $this->organizacion($r, true);
        try {
            return app(ResponsabilidadesComunidad::class)->ejecutar(fn () => Cache::store(config('comunidad.eventos_lock_store'))->lock('evento:'.$id, 30)->block(5, function () use ($id, $org, $accion) {
                Gate::authorize('update', $org);
                $e = Evento::where('organizacion_id', (string) $org->id)->findOrFail($id);
                $accion($e);

                return response()->json(['message' => 'Personal de acceso actualizado.']);
            }));
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'Otra operación está en curso. Intenta nuevamente.'], 409);
        }
    }
}
