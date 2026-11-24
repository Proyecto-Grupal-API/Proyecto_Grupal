<?php

namespace App\Http\Controllers;

use App\Models\AuditoriaComunidad;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\PeriodoOrganizacion;
use App\Services\PeriodosOrganizacion;
use App\Models\RolOrganizacion;
use App\Models\StaffEvento;
use App\Models\User;
use App\Services\IdentidadComunidadApi;
use App\Services\RegistroOrganizaciones;
use App\Services\ResponsabilidadesComunidad;
use App\Services\DelegacionesComunidad;
use App\Models\DelegacionOrganizacion;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrganizacionController extends Controller
{
    public function index(Request $request)
    {
        $org = $request->attributes->get('organizacion');
        $miembros = $org ? MiembroOrganizacion::activos()->where('organizacion_id', (string) $org->id)->orderBy('creado_en')->get() : collect();
        $roles = $org ? RolOrganizacion::where('organizacion_id', (string) $org->id)->whereNull('eliminado_en')->get() : collect();
        $ids = $miembros->pluck('usuario_id')->merge($roles->pluck('usuario_id'))->unique()->values();
        $identidades = [];
        if ($org && $ids->isNotEmpty()) {
            Gate::authorize('view', $org);
            $identidades = $this->identidades($ids->all(), $org);
        }
        $identidad = fn ($item) => $identidades[$item->usuario_id] ?? ['nombre' => 'Usuario no disponible', 'matricula' => null];

        return response()->json([
            'organizaciones' => $request->attributes->get('organizaciones'),
            'organizaciones_suspendidas' => Organizacion::where('estado', 'suspendida')->whereIn('_id', MiembroOrganizacion::activos()->where('usuario_id', (string) $request->user()->id)->pluck('organizacion_id'))->get(['id', 'nombre']),
            'organizacion' => $org,
            'puede_editar' => $org && Gate::allows('update', $org),
            'miembros' => $miembros->map(fn ($m) => [...$m->toArray(), ...$identidad($m)]),
            'roles' => $roles->map(fn ($r) => [...$r->toArray(), ...$identidad($r), 'vigente' => $r->vigente]),
            'periodos' => $org ? PeriodoOrganizacion::where('organizacion_id', (string) $org->id)->where('estado', 'registrado')->orderBy('fecha_inicio', 'desc')->get(['id', 'nombre', 'fecha_inicio', 'fecha_fin']) : collect(),
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

        $resolver = config('identidad_comunidad.directorio_organizaciones')
            ? fn ($org) => app(IdentidadComunidadApi::class)->resolverMatricula($data['matricula'], (string) $org->id)
            : null;

        return $this->modificar($request, function ($org, ?string $usuarioId) use ($request, $data) {
            $usuarioId ??= User::where('matricula', $data['matricula'])->first()?->id;
            if ($usuarioId === null) {
                throw ValidationException::withMessages(['matricula' => 'No existe un usuario con esa matrícula.']);
            }
            $miembro = MiembroOrganizacion::firstOrNew(['organizacion_id' => (string) $org->id, 'usuario_id' => (string) $usuarioId]);
            if ($miembro->exists && $miembro->estado === 'activo') {
                throw ValidationException::withMessages(['matricula' => 'Este estudiante ya es miembro activo.']);
            }
            $antes = $miembro->exists ? $miembro->toArray() : null;
            $miembro->fill(['fecha_inicio' => $data['fecha_inicio'], 'estado' => 'activo', 'eliminado_en' => null])->save();
            $this->auditar($request, $org, 'miembro_registrado', $miembro, $antes);
        }, 201, $resolver);
    }

    public function assignRol(Request $request, ?string $id = null)
    {
        $data = $request->validate([
            'usuario_id' => ['required', 'string', 'regex:/^[a-f0-9]{24}$/i'],
            'slug_rol' => ['required', Rule::in(RolOrganizacion::CARGOS)],
            'fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'fecha_fin' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
            'periodo_id' => ['nullable', 'string', 'regex:/^[a-f0-9]{24}$/i'],
        ]);

        return $this->modificar($request, function ($org) use ($request, $data, $id) {
            $rol = $id ? RolOrganizacion::where('organizacion_id', (string) $org->id)->whereNull('eliminado_en')->findOrFail($id) : new RolOrganizacion;
            $data = app(PeriodosOrganizacion::class)->validarCargo($org, $data, $rol);
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
            if ($rol->exists) {
                app(DelegacionesComunidad::class)->revocarRegistros(DelegacionOrganizacion::where('cargo_origen_id', (string) $rol->id), (string) $request->user()->id, 'Se modificó el cargo que otorgó la delegación.');
            }
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
            if ($miembro->estado !== 'activo') {
                // Un reintento de la misma baja no vuelve a auditarse ni a retirar nada.
                throw ValidationException::withMessages(['miembro' => 'Este integrante ya fue dado de baja.']);
            }
            $antes = $miembro->toArray();
            $miembro->update(['estado' => 'inactivo', 'eliminado_en' => now()]);
            app(\App\Services\ComiteBecas::class)->retirar((string) $miembro->usuario_id, (string) $request->user()->id, (string) $org->id);
            app(\App\Services\ComisionesConsejo::class)->retirarRegistros(\App\Models\IntegranteComision::where('organizacion_id', (string) $org->id)->where('usuario_id', $miembro->usuario_id), (string) $request->user()->id, 'Baja del integrante de la organización.');
            app(DelegacionesComunidad::class)->revocarRegistros(DelegacionOrganizacion::where('organizacion_id', (string) $org->id)->where(fn ($q) => $q->where('destinatario_id', $miembro->usuario_id)->orWhere('delegante_id', $miembro->usuario_id)), (string) $request->user()->id, 'Baja de un integrante vinculado a la delegación.');
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

    /**
     * Incidencia E6 (20-nov-2026): historial de altas y bajas de integrantes de la organización activa. Reutiliza la
     * auditoría de Comunidad (las entradas se escriben en la misma transacción que el cambio, así que sólo hay registros
     * de operaciones confirmadas). Sólo lo consulta quien administra la organización; nunca muestra otras organizaciones.
     */
    public function historialMiembros(Request $request)
    {
        $org = $this->organizacion($request, true);
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $p = AuditoriaComunidad::where('organizacion_id', (string) $org->id)->whereIn('accion', ['miembro_registrado', 'miembro_baja'])
            ->orderBy('creado_en', 'desc')->orderBy('_id', 'desc')->paginate(20);
        $integrante = fn ($a) => (string) (data_get($a->despues, 'usuario_id') ?? data_get($a->antes, 'usuario_id'));
        $ids = $p->getCollection()->flatMap(fn ($a) => [$integrante($a), (string) $a->usuario_id])->filter()->unique()->values()->all();
        $identidades = $ids ? $this->identidades($ids, $org) : [];
        $persona = fn (string $id) => ['usuario_id' => $id, 'nombre' => $identidades[$id]['nombre'] ?? 'Usuario no disponible', 'matricula' => $identidades[$id]['matricula'] ?? null];

        return response()->json([
            'movimientos' => $p->getCollection()->map(fn ($a) => [
                'id' => (string) $a->id,
                'operacion' => $a->accion === 'miembro_registrado' ? 'incorporacion' : 'eliminacion',
                'organizacion_id' => (string) $org->id,
                'organizacion_nombre' => $org->nombre,
                'integrante' => $persona($integrante($a)),
                'realizado_por' => array_diff_key($persona((string) $a->usuario_id), ['matricula' => true]),
                'fecha' => $a->creado_en?->toIso8601String(),
                'estado_anterior' => data_get($a->antes, 'estado'),
                'estado_posterior' => data_get($a->despues, 'estado'),
                'fecha_inicio' => data_get($a->despues, 'fecha_inicio'),
            ])->values(),
            'page' => $p->currentPage(), 'last_page' => $p->lastPage(),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function identidades(array $ids, Organizacion $org): array
    {
        return config('identidad_comunidad.directorio_organizaciones')
            ? app(IdentidadComunidadApi::class)->identidadesOrganizacion($ids, (string) $org->id)
            : User::whereIn('_id', $ids)->get(['_id', 'name', 'matricula'])->mapWithKeys(
                fn ($user) => [(string) $user->id => ['nombre' => $user->name, 'matricula' => $user->matricula]],
            )->all();
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
            app(DelegacionesComunidad::class)->revocarRegistros(DelegacionOrganizacion::where('cargo_origen_id', (string) $rol->id), (string) $request->user()->id, 'Se retiró el cargo que otorgó la delegación.');
            $this->auditar($request, $org, 'cargo_retirado', $rol, $antes);
        });
    }

    private function modificar(Request $request, callable $operacion, int $status = 200, ?callable $preparar = null)
    {
        $org = $this->organizacion($request, true);
        try {
            return app(ResponsabilidadesComunidad::class)->ejecutar(fn () => Cache::lock('organizacion:'.$org->id, $preparar ? 30 : 15)->block(5, function () use ($org, $operacion, $status, $preparar) {
                Gate::authorize('update', $org); // Revalidar después de esperar el bloqueo.
                // Resolver dentro del bloqueo de responsabilidades, fuera de la transacción MongoDB.
                $resultado = $preparar ? $preparar($org) : null;
                DB::connection('mongodb')->transaction(fn () => $operacion($org, $resultado));

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
