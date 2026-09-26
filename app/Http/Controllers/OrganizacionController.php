<?php

namespace App\Http\Controllers;

use App\Models\AuditoriaComunidad;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\RolOrganizacion;
use App\Models\StaffEvento;
use App\Models\User;
use App\Services\RegistroOrganizaciones;
use App\Services\ResponsabilidadesComunidad;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrganizacionController extends Controller
{
    public function index(Request $request)
    {
        $org = $request->attributes->get('organizacion');
        $miembros = $org ? MiembroOrganizacion::activos()->where('organizacion_id', (string) $org->id)->orderBy('creado_en')->get() : collect();
        $roles = $org ? RolOrganizacion::where('organizacion_id', (string) $org->id)->whereNull('eliminado_en')->get() : collect();
        $usuarios = User::whereIn('_id', $miembros->pluck('usuario_id')->merge($roles->pluck('usuario_id'))->unique())->get()->keyBy('id');
        $identidad = fn ($item) => ['nombre' => $usuarios->get($item->usuario_id)?->name ?? 'Usuario no disponible', 'matricula' => $usuarios->get($item->usuario_id)?->matricula];

        return response()->json([
            'organizaciones' => $request->attributes->get('organizaciones'),
            'organizaciones_suspendidas' => Organizacion::where('estado', 'suspendida')->whereIn('_id', MiembroOrganizacion::activos()->where('usuario_id', (string) $request->user()->id)->pluck('organizacion_id'))->get(['id', 'nombre']),
            'organizacion' => $org,
            'puede_editar' => $org && Gate::allows('update', $org),
            'miembros' => $miembros->map(fn ($m) => [...$m->toArray(), ...$identidad($m)]),
            'roles' => $roles->map(fn ($r) => [...$r->toArray(), ...$identidad($r), 'vigente' => $r->vigente]),
            'cargos' => RolOrganizacion::CARGOS,
        ]);
    }

    public function seleccionar(Request $request)
    {
        $data = $request->validate(['organizacion_id' => ['required', 'string', 'regex:/^[a-f0-9]{24}$/i']]);
        $org = Organizacion::findOrFail($data['organizacion_id']);
        Gate::authorize('view', $org);
        $request->session()->put('organizacion_id', (string) $org->id);

        return response()->json(['message' => 'Organización seleccionada.']);
    }

    public function updatePerfil(Request $request)
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'email' => ['required', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
        ]);

        return $this->modificar($request, function ($org) use ($request, $data) {
            if (! app(RegistroOrganizaciones::class)->nombreDisponible($data['nombre'], (string) $org->id)) {
                throw ValidationException::withMessages(['nombre' => 'El nombre debe ser identificable y no coincidir con otra organización.']);
            }
            $antes = $org->toArray();
            $org->update($data);
            $this->auditar($request, $org, 'perfil_actualizado', $org, $antes);
        });
    }

    public function addMiembro(Request $request)
    {
        $data = $request->validate([
            'matricula' => ['required', 'string', 'max:40'],
            'fecha_inicio' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        return $this->modificar($request, function ($org) use ($request, $data) {
            $usuario = User::where('matricula', $data['matricula'])->first();
            if (! $usuario) {
                throw ValidationException::withMessages(['matricula' => 'No existe un usuario con esa matrícula.']);
            }
            $miembro = MiembroOrganizacion::firstOrNew(['organizacion_id' => (string) $org->id, 'usuario_id' => (string) $usuario->id]);
            if ($miembro->exists && $miembro->estado === 'activo') {
                throw ValidationException::withMessages(['matricula' => 'Este estudiante ya es miembro activo.']);
            }
            $antes = $miembro->exists ? $miembro->toArray() : null;
            $miembro->fill(['fecha_inicio' => $data['fecha_inicio'], 'estado' => 'activo', 'eliminado_en' => null])->save();
            $this->auditar($request, $org, 'miembro_registrado', $miembro, $antes);
        }, 201);
    }

    public function assignRol(Request $request, ?string $id = null)
    {
        $data = $request->validate([
            'usuario_id' => ['required', 'string', 'regex:/^[a-f0-9]{24}$/i'],
            'slug_rol' => ['required', Rule::in(RolOrganizacion::CARGOS)],
            'fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'fecha_fin' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
        ]);

        return $this->modificar($request, function ($org) use ($request, $data, $id) {
            $rol = $id ? RolOrganizacion::where('organizacion_id', (string) $org->id)->whereNull('eliminado_en')->findOrFail($id) : new RolOrganizacion;
            if (! User::find($data['usuario_id']) || ! MiembroOrganizacion::activos()->where('organizacion_id', (string) $org->id)->where('usuario_id', $data['usuario_id'])->exists()) {
                throw ValidationException::withMessages(['usuario_id' => 'El cargo requiere un miembro activo de esta organización.']);
            }
            $ocupado = RolOrganizacion::where('organizacion_id', (string) $org->id)->where('slug_rol', $data['slug_rol'])->whereNull('eliminado_en');
            if ($id) {
                $ocupado->where('_id', '!=', $id);
            }
            if ($ocupado->exists()) {
                throw ValidationException::withMessages(['slug_rol' => 'Este cargo ya está asignado. Edita su titular o retíralo primero.']);
            }
            // Transferir la presidencia debe dejar un responsable vigente, no bloquear la administración.
            if ($rol->slug_rol === 'presidencia' && $rol->vigente && ($data['slug_rol'] !== 'presidencia' || $data['fecha_inicio'] > today()->toDateString() || (($data['fecha_fin'] ?? null) && $data['fecha_fin'] < today()->toDateString()))) {
                throw ValidationException::withMessages(['slug_rol' => 'Transfiere la presidencia a un miembro con vigencia actual.']);
            }
            $antes = $rol->exists ? $rol->toArray() : null;
            $rol->fill([...$data, 'fecha_fin' => $data['fecha_fin'] ?? null, 'organizacion_id' => (string) $org->id, 'otorgado_por' => (string) $request->user()->id, 'eliminado_en' => null])->save();
            $this->auditar($request, $org, 'cargo_asignado', $rol, $antes);
        }, $id ? 200 : 201);
    }

    public function removeMiembro(Request $request, string $id)
    {
        return $this->modificar($request, function ($org) use ($request, $id) {
            $miembro = MiembroOrganizacion::where('organizacion_id', (string) $org->id)->findOrFail($id);
            if (RolOrganizacion::vigentes()->where('organizacion_id', (string) $org->id)->where('usuario_id', $miembro->usuario_id)->where('slug_rol', 'presidencia')->exists()) {
                throw ValidationException::withMessages(['miembro' => 'Transfiere la presidencia antes de dar de baja a su titular.']);
            }
            $antes = $miembro->toArray();
            $miembro->update(['estado' => 'inactivo', 'eliminado_en' => now()]);
            foreach (RolOrganizacion::where('organizacion_id', (string) $org->id)->where('usuario_id', $miembro->usuario_id)->whereNull('eliminado_en')->get() as $rol) {
                $anterior = $rol->toArray();
                $rol->update(['eliminado_en' => now()]);
                $this->auditar($request, $org, 'cargo_retirado', $rol, $anterior);
            }
            foreach (StaffEvento::where('organizacion_id', (string) $org->id)->where('usuario_id', $miembro->usuario_id)->whereNull('eliminado_en')->get() as $staff) {
                $anterior = $staff->toArray();
                $staff->update(['eliminado_en' => now()]);
                $this->auditar($request, $org, 'staff_retirado_por_baja', $staff, $anterior);
            }
            $this->auditar($request, $org, 'miembro_baja', $miembro, $antes);
        });
    }

    public function removeRol(Request $request, string $id)
    {
        return $this->modificar($request, function ($org) use ($request, $id) {
            $rol = RolOrganizacion::where('organizacion_id', (string) $org->id)->whereNull('eliminado_en')->findOrFail($id);
            if ($rol->slug_rol === 'presidencia' && $rol->vigente) {
                throw ValidationException::withMessages(['cargo' => 'Edita el titular para transferir la presidencia sin dejarla vacante.']);
            }
            $antes = $rol->toArray();
            $rol->update(['eliminado_en' => now()]);
            $this->auditar($request, $org, 'cargo_retirado', $rol, $antes);
        });
    }

    private function modificar(Request $request, callable $operacion, int $status = 200)
    {
        $org = $this->organizacion($request, true);
        try {
            return app(ResponsabilidadesComunidad::class)->ejecutar(fn () => Cache::lock('organizacion:'.$org->id, 15)->block(5, function () use ($org, $operacion, $status) {
                Gate::authorize('update', $org); // Revalidar después de esperar el bloqueo.
                $operacion($org);

                return response()->json(['message' => 'Cambios guardados.'], $status);
            }));
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'Otra operación está en curso. Intenta nuevamente.'], 409);
        }
    }

    private function auditar(Request $request, Organizacion $org, string $accion, $entidad, ?array $antes): void
    {
        AuditoriaComunidad::create(['organizacion_id' => (string) $org->id, 'usuario_id' => (string) $request->user()->id, 'accion' => $accion, 'entidad_id' => (string) $entidad->id, 'antes' => $antes, 'despues' => $entidad->toArray()]);
    }
}
