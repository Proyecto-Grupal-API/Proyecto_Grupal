<?php

namespace App\Services;

use App\Models\AuditoriaComunidad;
use App\Models\DelegacionOrganizacion;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\RolOrganizacion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DelegacionesComunidad
{
    public function otorgar(User $actor, Organizacion $org, array $datos): DelegacionOrganizacion
    {
        return app(ResponsabilidadesComunidad::class)->ejecutar(fn () => DB::connection('mongodb')->transaction(function () use ($actor, $org, $datos) {
            Gate::forUser($actor)->authorize('update', $org);
            $inicio = Carbon::createFromFormat('!Y-m-d\TH:i', $datos['inicio'], 'America/Mexico_City');
            $fin = Carbon::createFromFormat('!Y-m-d\TH:i', $datos['fin'], 'America/Mexico_City');
            $capacidades = $datos['capacidades'];
            sort($capacidades);
            $contenido = ['destinatario_id' => $datos['destinatario_id'], 'capacidades' => $capacidades, 'inicio' => $inicio->toIso8601String(), 'fin' => $fin->toIso8601String(), 'motivo' => $datos['motivo'], 'delegante_id' => (string) $actor->id];
            $huella = hash('sha256', json_encode($contenido, JSON_THROW_ON_ERROR));
            $existente = DelegacionOrganizacion::where('organizacion_id', (string) $org->id)->where('clave_idempotencia', $datos['clave_idempotencia'])->first();
            if ($existente) {
                abort_unless(hash_equals($existente->huella, $huella), 409, 'La clave ya fue utilizada con otros datos.');

                return $existente;
            }
            $cargo = RolOrganizacion::vigentes()->where('organizacion_id', (string) $org->id)->where('usuario_id', (string) $actor->id)->where('slug_rol', 'presidencia')->firstOrFail();
            if ($datos['destinatario_id'] === (string) $actor->id || ! $this->esMiembro($org, $datos['destinatario_id'])) {
                throw ValidationException::withMessages(['destinatario_id' => 'Elige otro integrante activo de esta organización.']);
            }
            if ($inicio->lt(now()->startOfMinute()) || ! $fin->gt($inicio) || ! $fin->gt(now())
                || ($cargo->fecha_fin && $fin->gt($cargo->fecha_fin->copy()->startOfDay()->addDay()))) {
                throw ValidationException::withMessages(['fin' => 'El periodo debe ser futuro, con inicio anterior al fin, y no exceder la vigencia de presidencia.']);
            }
            $d = DelegacionOrganizacion::create([...$contenido, 'inicio' => $inicio, 'fin' => $fin, 'organizacion_id' => (string) $org->id,
                'cargo_origen_id' => (string) $cargo->id, 'firma_origen' => $this->firma($cargo), 'version_organizacion' => (int) ($org->fresh()->version_estado ?? 0),
                'clave_idempotencia' => $datos['clave_idempotencia'], 'huella' => $huella]);
            $this->auditar($d, (string) $actor->id, 'delegacion_otorgada', null);

            return $d;
        }));
    }

    public function revocar(User $actor, Organizacion $org, string $id, string $motivo): DelegacionOrganizacion
    {
        return app(ResponsabilidadesComunidad::class)->ejecutar(fn () => DB::connection('mongodb')->transaction(function () use ($actor, $org, $id, $motivo) {
            Gate::forUser($actor)->authorize('update', $org);
            $d = DelegacionOrganizacion::where('organizacion_id', (string) $org->id)->findOrFail($id);
            $this->revocarRegistros(DelegacionOrganizacion::where('_id', $id), (string) $actor->id, $motivo);

            return $d->fresh();
        }));
    }

    // Los llamadores de mantenimiento/bajas ya comparten bloqueo y transacción.
    public function revocarRegistros(Builder $consulta, ?string $actor, string $motivo): void
    {
        foreach ($consulta->whereNull('revocada_en')->get() as $d) {
            $antes = $d->toArray();
            $d->update(['revocada_en' => now(), 'revocada_por' => $actor, 'motivo_revocacion' => $motivo]);
            $this->auditar($d, $actor, 'delegacion_revocada', $antes);
        }
    }

    public function vigente(DelegacionOrganizacion $d, Organizacion $org): bool
    {
        if ($d->organizacion_id !== (string) $org->id || $d->revocada_en || ! $d->inicio?->lte(now()) || ! $d->fin?->gt(now())
            || $org->estado !== 'activa' || (int) ($org->version_estado ?? 0) !== $d->version_organizacion
            || ! $this->esMiembro($org, $d->delegante_id) || ! $this->esMiembro($org, $d->destinatario_id)) {
            return false;
        }
        $cargo = RolOrganizacion::vigentes()->where('organizacion_id', (string) $org->id)->where('usuario_id', $d->delegante_id)->where('slug_rol', 'presidencia')->find($d->cargo_origen_id);

        return $cargo && hash_equals($d->firma_origen, $this->firma($cargo));
    }

    public function resumen(DelegacionOrganizacion $d, Organizacion $org): array
    {
        $estado = $d->revocada_en ? 'revocada' : (! $d->fin->gt(now()) ? 'vencida' : ($d->inicio->gt(now()) ? 'programada' : ($this->vigente($d, $org) ? 'vigente' : 'sin_efecto')));

        return [...$d->toArray(), 'estado' => $estado];
    }

    private function firma(RolOrganizacion $cargo): string
    {
        return hash('sha256', json_encode([$cargo->id, $cargo->usuario_id, $cargo->slug_rol, $cargo->fecha_inicio?->toIso8601String(), $cargo->fecha_fin?->toIso8601String(), $cargo->otorgado_por, $cargo->actualizado_en?->toIso8601String()], JSON_THROW_ON_ERROR));
    }

    private function esMiembro(Organizacion $org, string $usuario): bool
    {
        return MiembroOrganizacion::activos()->where('organizacion_id', (string) $org->id)->where('usuario_id', $usuario)->exists();
    }

    private function auditar(DelegacionOrganizacion $d, ?string $actor, string $accion, ?array $antes): void
    {
        AuditoriaComunidad::create(['organizacion_id' => $d->organizacion_id, 'usuario_id' => $actor, 'accion' => $accion, 'entidad_id' => (string) $d->id, 'antes' => $antes, 'despues' => $d->toArray()]);
    }
}
