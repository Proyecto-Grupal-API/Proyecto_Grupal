<?php

namespace App\Services;

use App\Models\AuditoriaComunidad;
use App\Models\ComisionConsejo;
use App\Models\IntegranteComision;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ComisionesConsejo
{
    public function crear(User $actor, Organizacion $org, array $d): ComisionConsejo
    {
        return $this->modificar($actor, $org, function () use ($actor, $org, $d) {
            $huella = hash('sha256', json_encode([$d, (string) $actor->id], JSON_THROW_ON_ERROR));
            $previa = ComisionConsejo::where('organizacion_id', (string) $org->id)->where('clave_solicitud', $d['clave_solicitud'])->first();
            if ($previa) {
                abort_unless(hash_equals($previa->huella, $huella), 409, 'La clave ya fue utilizada con otros datos.');

                return $previa;
            }
            $clave = $this->nombre($org, $d['nombre']);
            $c = ComisionConsejo::create([...$d, 'nombre_clave' => $clave, 'organizacion_id' => (string) $org->id, 'estado' => 'activa', 'version' => 1, 'creado_por' => (string) $actor->id, 'huella' => $huella]);
            $this->auditar($c->organizacion_id, (string) $actor->id, 'comision_creada', (string) $c->id, null, $c->toArray());

            return $c;
        });
    }

    public function editar(User $actor, Organizacion $org, string $id, array $d): ComisionConsejo
    {
        return $this->modificar($actor, $org, function () use ($actor, $org, $id, $d) {
            $c = $this->buscar($org, $id);
            $this->abierta($c);
            abort_unless($c->version === (int) $d['version'], 409, 'La comisión cambió. Actualiza antes de editar.');
            $antes = $c->toArray();
            $c->update([...$d, 'nombre_clave' => $this->nombre($org, $d['nombre'], $id), 'version' => $c->version + 1]);
            $this->auditar($c->organizacion_id, (string) $actor->id, 'comision_editada', $id, $antes, $c->toArray());

            return $c;
        });
    }

    public function asignar(User $actor, Organizacion $org, string $id, array $d): IntegranteComision
    {
        return $this->modificar($actor, $org, function () use ($actor, $org, $id, $d) {
            $c = $this->buscar($org, $id);
            $huella = hash('sha256', json_encode([$d, (string) $actor->id], JSON_THROW_ON_ERROR));
            $previa = IntegranteComision::where('comision_id', $id)->where('clave_solicitud', $d['clave_solicitud'])->first();
            if ($previa) {
                abort_unless(hash_equals($previa->huella, $huella), 409, 'La clave ya fue utilizada con otros datos.');

                return $previa;
            }
            if ($c->situacion !== 'vigente') {
                throw ValidationException::withMessages(['comision' => 'Solo una comisión vigente admite integrantes nuevos.']);
            }
            if (! MiembroOrganizacion::activos()->where('organizacion_id', (string) $org->id)->where('usuario_id', $d['usuario_id'])->exists()) {
                throw ValidationException::withMessages(['usuario_id' => 'Se requiere un integrante activo de este Consejo.']);
            }
            $q = IntegranteComision::where('comision_id', $id)->whereNull('retirado_en');
            if ((clone $q)->where('usuario_id', $d['usuario_id'])->exists()) {
                throw ValidationException::withMessages(['usuario_id' => 'Esta persona ya participa. Retira la asignación antes de cambiar su función.']);
            }
            if ($d['funcion'] === 'coordinacion' && (clone $q)->where('funcion', 'coordinacion')->exists()) {
                throw ValidationException::withMessages(['funcion' => 'Retira la coordinación actual antes de sustituirla.']);
            }
            $a = IntegranteComision::create([...$d, 'organizacion_id' => (string) $org->id, 'comision_id' => $id, 'asignado_por' => (string) $actor->id, 'huella' => $huella, 'retirado_en' => null]);
            $this->auditar($a->organizacion_id, (string) $actor->id, 'comision_integrante_asignado', (string) $a->id, null, $a->toArray());

            return $a;
        });
    }

    public function retirar(User $actor, Organizacion $org, string $id, string $asignacion, string $motivo): void
    {
        $this->modificar($actor, $org, function () use ($actor, $org, $id, $asignacion, $motivo) {
            $this->buscar($org, $id);
            $a = IntegranteComision::where('comision_id', $id)->where('organizacion_id', (string) $org->id)->findOrFail($asignacion);
            $this->retirarRegistros(IntegranteComision::where('_id', (string) $a->id), (string) $actor->id, $motivo);
        });
    }

    public function cerrar(User $actor, Organizacion $org, string $id, array $d): ComisionConsejo
    {
        return $this->modificar($actor, $org, function () use ($actor, $org, $id, $d) {
            $c = $this->buscar($org, $id);
            if ($c->estado === 'cerrada') {
                return $c;
            }
            abort_unless($c->version === (int) $d['version'], 409, 'La comisión cambió. Actualiza antes de cerrar.');
            $antes = $c->toArray();
            $c->update(['estado' => 'cerrada', 'version' => $c->version + 1, 'cerrada_en' => now(), 'cerrada_por' => (string) $actor->id, 'motivo_cierre' => $d['motivo']]);
            $this->retirarRegistros(IntegranteComision::where('comision_id', $id), (string) $actor->id, 'Cierre de comisión: '.$d['motivo']);
            $this->auditar($c->organizacion_id, (string) $actor->id, 'comision_cerrada', $id, $antes, $c->toArray());

            return $c;
        });
    }

    // Usado también por las bajas de membresía/cuenta dentro de su transacción.
    public function retirarRegistros(Builder $q, ?string $actor, string $motivo): void
    {
        foreach ($q->whereNull('retirado_en')->get() as $a) {
            $antes = $a->toArray();
            $a->update(['retirado_en' => now(), 'retirado_por' => $actor, 'motivo_retiro' => $motivo]);
            $this->auditar($a->organizacion_id, $actor, 'comision_integrante_retirado', (string) $a->id, $antes, $a->toArray());
        }
    }

    private function modificar(User $actor, Organizacion $org, callable $fn): mixed
    {
        return app(ResponsabilidadesComunidad::class)->ejecutar(fn () => DB::connection('mongodb')->transaction(function () use ($actor, $org, $fn) {
            Gate::forUser($actor)->authorize('update', $org);
            abort_unless($org->tipo === 'consejo', 404);

            return $fn();
        }));
    }

    private function buscar(Organizacion $org, string $id): ComisionConsejo
    {
        return ComisionConsejo::where('organizacion_id', (string) $org->id)->findOrFail($id);
    }

    private function abierta(ComisionConsejo $c): void
    {
        if ($c->estado !== 'activa') {
            throw ValidationException::withMessages(['comision' => 'Una comisión cerrada conserva su historial y no admite cambios.']);
        }
    }

    private function nombre(Organizacion $org, string $nombre, ?string $id = null): string
    {
        $clave = Str::slug($nombre);
        $q = ComisionConsejo::where('organizacion_id', (string) $org->id)->where('estado', 'activa')->where('nombre_clave', $clave);
        if ($id) {
            $q->where('_id', '!=', $id);
        }
        if (! $clave || $q->exists()) {
            throw ValidationException::withMessages(['nombre' => 'Usa un nombre identificable y distinto al de otra comisión abierta.']);
        }

        return $clave;
    }

    private function auditar(string $org, ?string $actor, string $accion, string $id, ?array $antes, array $despues): void
    {
        AuditoriaComunidad::create(['organizacion_id' => $org, 'usuario_id' => $actor, 'accion' => $accion, 'entidad_id' => $id, 'antes' => $antes, 'despues' => $despues]);
    }
}
