<?php

namespace App\Services;

use App\Models\AuditoriaComunidad;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\RolOrganizacion;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegistroOrganizaciones
{
    public function crear(User $actor, array $datos): Organizacion
    {
        return app(ResponsabilidadesComunidad::class)->ejecutar(function () use ($actor, $datos) {
            Gate::forUser($actor)->authorize('gestionarRegistro', Organizacion::class);
            $firma = hash('sha256', json_encode(collect($datos)->except('clave_alta')->all()));
            $org = Organizacion::where('clave_alta', $datos['clave_alta'])->first();
            if ($org) {
                abort_unless($org->creado_por === (string) $actor->id && hash_equals($org->firma_alta, $firma), 409, 'La solicitud ya existe con otros datos.');

                return $org->estado === 'configurando' ? $this->completar($org, $actor) : $org;
            }
            $titular = User::where('matricula', $datos['matricula_presidencia'])->first();
            if (! $titular) {
                throw ValidationException::withMessages(['matricula_presidencia' => 'La presidencia debe corresponder a una matrícula registrada.']);
            }
            $slug = Str::slug($datos['nombre']);
            if (! $slug || ! $this->nombreDisponible($datos['nombre']) || Organizacion::where('slug', $slug)->exists()) {
                throw ValidationException::withMessages(['nombre' => 'El nombre debe ser identificable y no coincidir con otra organización, incluso suspendida.']);
            }
            $org = Organizacion::create([...collect($datos)->only(['nombre', 'tipo', 'descripcion', 'email', 'telefono'])->all(),
                'slug' => $slug, 'estado' => 'configurando', 'clave_alta' => $datos['clave_alta'], 'firma_alta' => $firma,
                'alta_datos' => ['usuario_id' => (string) $titular->id, 'fecha_fin' => $datos['fecha_fin_presidencia']], 'creado_por' => (string) $actor->id,
                'version_estado' => 0, 'historial_estados' => []]);

            return $this->completar($org, $actor);
        });
    }

    public function reanudar(User $actor, string $id): Organizacion
    {
        return app(ResponsabilidadesComunidad::class)->ejecutar(function () use ($actor, $id) {
            Gate::forUser($actor)->authorize('gestionarRegistro', Organizacion::class);
            $org = Organizacion::findOrFail($id);
            abort_unless($org->estado === 'configurando', 409, 'Esta organización ya completó su alta.');

            return $this->completar($org, $actor);
        });
    }

    private function completar(Organizacion $org, User $actor): Organizacion
    {
        $d = $org->alta_datos;
        if (! User::find($d['usuario_id']) || $d['fecha_fin'] < today()->toDateString()) {
            throw ValidationException::withMessages(['presidencia' => 'El titular ya no existe o el periodo inicial venció. Requiere revisión de mantenimiento.']);
        }
        MiembroOrganizacion::firstOrCreate(['organizacion_id' => (string) $org->id, 'usuario_id' => $d['usuario_id']], ['fecha_inicio' => today(), 'estado' => 'activo', 'eliminado_en' => null]);
        RolOrganizacion::firstOrCreate(['organizacion_id' => (string) $org->id, 'slug_rol' => 'presidencia', 'eliminado_en' => null], ['usuario_id' => $d['usuario_id'], 'fecha_inicio' => today(), 'fecha_fin' => $d['fecha_fin'], 'otorgado_por' => (string) $actor->id]);
        if (! $this->tienePresidencia($org)) {
            throw ValidationException::withMessages(['presidencia' => 'No se pudo confirmar una presidencia vigente. El alta no se publica.']);
        }
        $h = ['id' => $org->clave_alta, 'desde' => 'configurando', 'hasta' => 'activa', 'motivo' => 'Alta con presidencia inicial.', 'actor_id' => (string) $actor->id, 'fecha' => now()->toIso8601String(), 'version' => 1];
        $h = $this->auditar($org, $h);
        $org->update(['estado' => 'activa', 'version_estado' => 1, 'historial_estados' => [$h]]);

        return $org->fresh();
    }

    public function nombreDisponible(string $nombre, ?string $excepto = null): bool
    {
        $q = Organizacion::query();
        if ($excepto) {
            $q->where('_id', '!=', $excepto);
        }
        $normalizado = Str::slug($nombre);

        return $normalizado !== '' && ! $q->get(['nombre'])->contains(fn ($o) => Str::slug($o->nombre) === $normalizado);
    }

    public function tienePresidencia(Organizacion $org): bool
    {
        $rol = RolOrganizacion::vigentes()->where('organizacion_id', (string) $org->id)->where('slug_rol', 'presidencia')->first();

        return $rol && User::find($rol->usuario_id) && MiembroOrganizacion::activos()->where('organizacion_id', (string) $org->id)->where('usuario_id', $rol->usuario_id)->exists();
    }

    public function cambiarEstado(User $actor, string $id, array $d): Organizacion
    {
        return app(ResponsabilidadesComunidad::class)->ejecutar(function () use ($actor, $id, $d) {
            Gate::forUser($actor)->authorize('gestionarRegistro', Organizacion::class);
            $org = Organizacion::findOrFail($id);
            $historial = $org->historial_estados ?? [];
            $previo = collect($historial)->firstWhere('id', $d['clave_cambio']);
            if ($previo) {
                abort_unless($previo['hasta'] === $d['estado'] && $previo['motivo'] === $d['motivo'] && $previo['actor_id'] === (string) $actor->id, 409, 'Esta solicitud ya se utilizó con otros datos.');
                $this->auditar($org, $previo);

                return $org;
            }
            abort_unless(in_array($org->estado, ['activa', 'suspendida']) && (int) ($org->version_estado ?? 0) === (int) $d['version_estado'], 409, 'El estado cambió. Actualiza antes de continuar.');
            if ($org->estado === $d['estado']) {
                throw ValidationException::withMessages(['estado' => 'La organización ya tiene ese estado.']);
            }
            if ($d['estado'] === 'activa' && ! $this->tienePresidencia($org)) {
                throw ValidationException::withMessages(['presidencia' => 'Regulariza la presidencia desde mantenimiento antes de reactivar la organización.']);
            }
            $h = ['id' => $d['clave_cambio'], 'desde' => $org->estado, 'hasta' => $d['estado'], 'motivo' => $d['motivo'], 'actor_id' => (string) $actor->id, 'fecha' => now()->toIso8601String(), 'version' => $d['version_estado'] + 1];
            // Estado y su historial se guardan juntos en un único documento MongoDB.
            $org->update(['estado' => $d['estado'], 'version_estado' => $h['version'], 'historial_estados' => [...$historial, $h]]);
            $this->auditar($org, $h);

            return $org->fresh();
        });
    }

    private function auditar(Organizacion $org, array $h): array
    {
        return AuditoriaComunidad::firstOrCreate(['organizacion_id' => (string) $org->id, 'accion' => 'organizacion_estado', 'entidad_id' => $h['id']], ['usuario_id' => $h['actor_id'], 'antes' => ['estado' => $h['desde']], 'despues' => $h])->despues;
    }
}
