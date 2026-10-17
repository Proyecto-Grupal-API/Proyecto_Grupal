<?php

namespace App\Services;

use App\Models\AuditoriaComunidad;
use App\Models\ConvocatoriaBeca;
use App\Models\MiembroOrganizacion;
use App\Models\Organizacion;
use App\Models\RecomendacionBeca;
use App\Models\SolicitudBeca;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ComiteBecas
{
    public function limitar($query, User $u): mixed
    {
        $id = (string) $u->id;

        return $query->whereNotIn('comite.retirados_ids', [$id])->where(fn ($q) => $q->where('comite.responsable_id', $id)->orWhere('comite.revisor_ids', $id));
    }

    public function participa(?User $u, ?Organizacion $org): bool
    {
        return $u && $org && ! $u->trashed() && Gate::forUser($u)->allows('view', $org)
            && $this->limitar(ConvocatoriaBeca::where('organizacion_id', (string) $org->id), $u)->exists();
    }

    public function funcion(User $u, ConvocatoriaBeca $c): ?string
    {
        $id = (string) $u->id;
        $org = Organizacion::find($c->organizacion_id);
        if ($u->trashed() || ! $org || ! Gate::forUser($u)->allows('view', $org) || in_array($id, $c->comite['retirados_ids'] ?? [], true)) {
            return null;
        }
        if (($c->comite['responsable_id'] ?? null) === $id) {
            return 'responsable';
        }

        return in_array($id, $c->comite['revisor_ids'] ?? [], true) ? 'revisor' : null;
    }

    public function puedeConsultar(User $u, ConvocatoriaBeca $c): bool
    {
        $org = Organizacion::find($c->organizacion_id);
        if (! $org) {
            return false;
        }

        return $c->comite === null ? Gate::forUser($u)->allows('revisarBecas', $org)
            : (Gate::forUser($u)->allows('update', $org) || $this->funcion($u, $c) !== null);
    }

    public function puedeDictaminar(User $u, ConvocatoriaBeca $c): bool
    {
        return $c->comite === null ? $this->puedeConsultar($u, $c) : $this->funcion($u, $c) === 'responsable';
    }

    public function configurar(User $u, ConvocatoriaBeca $c, array $d): void
    {
        Gate::forUser($u)->authorize('update', Organizacion::findOrFail($c->organizacion_id));
        abort_unless(! in_array($c->estado, ['finalizada', 'cancelada']), 422, 'La convocatoria está cerrada.');
        abort_unless($d['version'] === (int) ($c->comite['version'] ?? 0), 409, 'El comité cambió. Recarga antes de guardar.');
        $ids = [...$d['revisor_ids'], $d['responsable_id']];
        if (count(array_unique($ids)) !== count($ids) || MiembroOrganizacion::activos()->where('organizacion_id', $c->organizacion_id)->whereIn('usuario_id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['revisor_ids' => 'El responsable y los revisores deben ser integrantes activos distintos.']);
        }
        $antes = $c->comite;
        $c->update(['comite' => ['version' => $d['version'] + 1, 'responsable_id' => $d['responsable_id'], 'revisor_ids' => $d['revisor_ids'], 'retirados_ids' => [], 'motivo' => $d['motivo'], 'configurado_por' => (string) $u->id, 'configurado_en' => now()->toIso8601String()]]);
        $this->auditar($u, $c, 'beca_comite_configurado', (string) $c->id, $antes, $c->comite);
    }

    public function recomendar(User $u, SolicitudBeca $s, ConvocatoriaBeca $c, array $d): RecomendacionBeca
    {
        abort_unless($this->funcion($u, $c) === 'revisor', 403);
        abort_unless((string) $s->usuario_id !== (string) $u->id, 403, 'No puedes revisar tu propia solicitud.');
        abort_unless($s->enviado_en && in_array($s->estado, ['pendiente', 'en_revision']) && $this->revisionAbierta($c), 422, 'La recomendación requiere una solicitud enviada y recepción cerrada.');
        abort_unless($d['version_comite'] === $c->comite['version'], 409, 'El comité cambió. Recarga el expediente.');
        $r = RecomendacionBeca::firstOrNew(['solicitud_id' => (string) $s->id, 'version_comite' => $d['version_comite'], 'usuario_id' => (string) $u->id]);
        if ($r->exists && $r->recomendacion === $d['recomendacion'] && $r->motivo === $d['motivo']) {
            return $r;
        }
        abort_unless($d['revision'] === (int) $r->revision, 409, 'La recomendación cambió. Recarga antes de editarla.');
        $antes = $r->exists ? $r->toArray() : null;
        $r->fill(['organizacion_id' => $c->organizacion_id, 'convocatoria_id' => (string) $c->id, 'revision' => (int) $r->revision + 1, 'recomendacion' => $d['recomendacion'], 'motivo' => $d['motivo']])->save();
        $this->auditar($u, $c, 'beca_recomendacion', (string) $r->id, $antes, $r->toArray());

        return $r;
    }

    public function resumen(User $u, SolicitudBeca $s, ConvocatoriaBeca $c): ?array
    {
        if ($c->comite === null || (string) $u->id === (string) $s->usuario_id || ! $this->puedeConsultar($u, $c)) {
            return null;
        }
        $versionNotas = (int) ($s->dictamen_comite['version'] ?? $c->comite['version']);
        $rows = RecomendacionBeca::where('solicitud_id', (string) $s->id)->where('version_comite', $versionNotas)->get();
        $propia = $rows->firstWhere('usuario_id', (string) $u->id);
        $elegibles = array_values(array_diff($c->comite['revisor_ids'], [(string) $s->usuario_id]));
        $pendientes = array_values(array_diff($elegibles, $rows->pluck('usuario_id')->all()));
        $vigente = $this->integrantesVigentes($c);
        $supervisa = Gate::forUser($u)->allows('update', Organizacion::findOrFail($c->organizacion_id));

        return ['version' => $c->comite['version'], 'version_recomendaciones' => $versionNotas, 'dictamen_utilizado' => ($supervisa || $this->funcion($u, $c) === 'responsable') ? $s->dictamen_comite : null, 'funcion' => $this->funcion($u, $c), 'puede_recomendar' => $this->funcion($u, $c) === 'revisor' && $s->enviado_en && $this->revisionAbierta($c) && in_array($s->estado, ['pendiente', 'en_revision']),
            'puede_resolver' => $vigente && count($elegibles) > 0 && count($pendientes) === 0, 'integrantes_vigentes' => $vigente, 'revisiones_pendientes' => count($pendientes), 'recomendacion_propia' => $propia,
            'recomendaciones' => ($supervisa || $this->funcion($u, $c) === 'responsable') ? $rows : collect()];
    }

    public function validarResolucion(User $u, SolicitudBeca $s, ConvocatoriaBeca $c): ?array
    {
        if ($c->comite === null) {
            return null;
        }
        $resumen = $this->resumen($u, $s, $c);
        abort_unless($resumen && $resumen['puede_resolver'], 409, 'Faltan recomendaciones independientes vigentes o se necesita reemplazar el comité.');

        return ['version' => $c->comite['version'], 'responsable_id' => (string) $u->id, 'recomendaciones_ids' => $resumen['recomendaciones']->pluck('id')->all(), 'revisiones' => $resumen['recomendaciones']->map(fn ($r) => ['id' => (string) $r->id, 'revision' => $r->revision])->all()];
    }

    public function retirar(string $usuarioId, string $actorId, ?string $orgId = null): void
    {
        $q = ConvocatoriaBeca::where(fn ($q) => $q->where('comite.responsable_id', $usuarioId)->orWhere('comite.revisor_ids', $usuarioId));
        if ($orgId !== null) {
            $q->where('organizacion_id', $orgId);
        }
        foreach ($q->get() as $c) {
            $antes = $c->comite;
            if (in_array($usuarioId, $antes['retirados_ids'] ?? [], true)) {
                continue;
            }
            $c->update(['comite' => [...$antes, 'retirados_ids' => [...($antes['retirados_ids'] ?? []), $usuarioId]]]);
            AuditoriaComunidad::create(['organizacion_id' => $c->organizacion_id, 'usuario_id' => $actorId, 'accion' => 'beca_comite_baja', 'entidad_id' => (string) $c->id, 'antes' => $antes, 'despues' => $c->comite]);
        }
    }

    private function integrantesVigentes(ConvocatoriaBeca $c): bool
    {
        $ids = [...$c->comite['revisor_ids'], $c->comite['responsable_id']];

        return empty($c->comite['retirados_ids']) && MiembroOrganizacion::activos()->where('organizacion_id', $c->organizacion_id)->whereIn('usuario_id', $ids)->count() === count($ids);
    }

    private function revisionAbierta(ConvocatoriaBeca $c): bool
    {
        return $c->estado === 'en_revision' || ($c->estado === 'publicada' && $c->fecha_fin?->lte(now()));
    }

    private function auditar(User $u, ConvocatoriaBeca $c, string $accion, string $id, ?array $antes, array $despues): void
    {
        AuditoriaComunidad::create(['organizacion_id' => $c->organizacion_id, 'usuario_id' => (string) $u->id, 'accion' => $accion, 'entidad_id' => $id, 'antes' => $antes, 'despues' => $despues]);
    }
}
