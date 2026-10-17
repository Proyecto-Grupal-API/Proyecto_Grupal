<?php

namespace App\Services;

use App\Models\AuditoriaComunidad;
use App\Models\Organizacion;
use App\Models\PeriodoOrganizacion;
use App\Models\RolOrganizacion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PeriodosOrganizacion
{
    public function crear(User $actor, Organizacion $org, array $d): PeriodoOrganizacion
    {
        return $this->modificar($actor, $org, function () use ($actor, $org, $d) {
            $huella = hash('sha256', json_encode([$d, (string) $actor->id], JSON_THROW_ON_ERROR));
            $previo = PeriodoOrganizacion::where('organizacion_id', (string) $org->id)->where('clave_solicitud', $d['clave_solicitud'])->first();
            if ($previo) {
                abort_unless(hash_equals($previo->huella, $huella), 409, 'La clave ya fue utilizada con otros datos o responsable.');

                return $previo;
            }
            $nombre = $this->validarRegistro($org, $d);
            $p = PeriodoOrganizacion::create([...$d, 'nombre_clave' => $nombre, 'organizacion_id' => (string) $org->id, 'estado' => 'registrado', 'version' => 1, 'creado_por' => (string) $actor->id, 'huella' => $huella]);
            $this->auditar($p, $actor, 'periodo_creado', null);

            return $p;
        });
    }

    public function editar(User $actor, Organizacion $org, string $id, array $d): PeriodoOrganizacion
    {
        return $this->modificar($actor, $org, function () use ($actor, $org, $id, $d) {
            $p = $this->buscar($org, $id);
            $this->editable($p, $d['version']);
            if (($p->fecha_inicio !== $d['fecha_inicio'] || $p->fecha_fin !== $d['fecha_fin']) && $this->enUso($p)) {
                throw ValidationException::withMessages(['fecha_inicio' => 'Las fechas de un periodo con cargos vinculados se conservan para el historial.']);
            }
            $nombre = $this->validarRegistro($org, $d, $id);
            $antes = $p->toArray();
            $p->update([...$d, 'nombre_clave' => $nombre, 'version' => $p->version + 1]);
            $this->auditar($p, $actor, 'periodo_editado', $antes);

            return $p;
        });
    }

    public function cancelar(User $actor, Organizacion $org, string $id, array $d): PeriodoOrganizacion
    {
        return $this->modificar($actor, $org, function () use ($actor, $org, $id, $d) {
            $p = $this->buscar($org, $id);
            if ($p->estado === 'cancelado') {
                return $p;
            }
            $this->editable($p, $d['version']);
            if ($this->enUso($p)) {
                throw ValidationException::withMessages(['periodo' => 'Este periodo tiene cargos o historial vinculados y debe conservarse.']);
            }
            $antes = $p->toArray();
            $p->update(['estado' => 'cancelado', 'version' => $p->version + 1, 'cancelado_en' => now(), 'cancelado_por' => (string) $actor->id, 'motivo_cancelacion' => $d['motivo']]);
            $this->auditar($p, $actor, 'periodo_cancelado', $antes);

            return $p;
        });
    }

    // El llamador ya comparte bloqueo y transacción con edición/cancelación del periodo.
    public function validarCargo(Organizacion $org, array $datos, RolOrganizacion $actual): array
    {
        $anterior = $actual->periodo_id;
        $id = array_key_exists('periodo_id', $datos) ? $datos['periodo_id'] : $anterior;
        if (! $id) {
            if ($anterior) {
                throw ValidationException::withMessages(['periodo_id' => 'El periodo vinculado se conserva para el historial del cargo.']);
            }

            return [...$datos, 'periodo_id' => null];
        }
        $p = $this->buscar($org, $id);
        $id = (string) $p->id;
        if ($anterior && $anterior !== $id) {
            throw ValidationException::withMessages(['periodo_id' => 'El cargo ya pertenece a otro periodo. Conserva su registro histórico.']);
        }
        abort_unless($p->estado === 'registrado', 422, 'Un periodo cancelado no admite cargos.');
        if (empty($datos['fecha_fin']) || $datos['fecha_inicio'] < $p->fecha_inicio || $datos['fecha_fin'] > $p->fecha_fin) {
            throw ValidationException::withMessages(['fecha_fin' => 'Indica inicio y fin del cargo dentro de las fechas del periodo seleccionado.']);
        }

        return [...$datos, 'periodo_id' => $id];
    }

    public function enUso(PeriodoOrganizacion $p): bool
    {
        return RolOrganizacion::where('organizacion_id', $p->organizacion_id)->where('periodo_id', (string) $p->id)->exists();
    }

    private function modificar(User $actor, Organizacion $org, callable $fn): mixed
    {
        return app(ResponsabilidadesComunidad::class)->ejecutar(fn () => DB::connection('mongodb')->transaction(function () use ($actor, $org, $fn) {
            Gate::forUser($actor)->authorize('update', $org);

            return $fn();
        }));
    }

    private function buscar(Organizacion $org, string $id): PeriodoOrganizacion
    {
        return PeriodoOrganizacion::where('organizacion_id', (string) $org->id)->findOrFail($id);
    }

    private function editable(PeriodoOrganizacion $p, int $version): void
    {
        abort_unless($p->estado === 'registrado', 422, 'Un periodo cancelado conserva su historial y no admite cambios.');
        abort_unless($p->version === $version, 409, 'El periodo cambió. Vuelve a cargar sus datos antes de editar.');
    }

    private function validarRegistro(Organizacion $org, array $d, ?string $id = null): string
    {
        $q = PeriodoOrganizacion::where('organizacion_id', (string) $org->id)->where('estado', 'registrado');
        if ($id) {
            $q->where('_id', '!=', $id);
        }
        $nombre = Str::slug($d['nombre']);
        if (! $nombre || (clone $q)->where('nombre_clave', $nombre)->exists()) {
            throw ValidationException::withMessages(['nombre' => 'Elige un nombre identificable y distinto a otro periodo registrado.']);
        }
        if ($q->where('fecha_inicio', '<=', $d['fecha_fin'])->where('fecha_fin', '>=', $d['fecha_inicio'])->exists()) {
            throw ValidationException::withMessages(['fecha_inicio' => 'Las fechas se superponen con otro periodo de esta organización. El último día es inclusivo.']);
        }

        return $nombre;
    }

    private function auditar(PeriodoOrganizacion $p, User $actor, string $accion, ?array $antes): void
    {
        AuditoriaComunidad::create(['organizacion_id' => $p->organizacion_id, 'usuario_id' => (string) $actor->id, 'accion' => $accion, 'entidad_id' => (string) $p->id, 'antes' => $antes, 'despues' => $p->toArray()]);
    }
}
