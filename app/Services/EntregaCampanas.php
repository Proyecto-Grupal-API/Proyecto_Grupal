<?php

namespace App\Services;

use App\Models\AuditoriaComunidad;
use App\Models\Campana;
use App\Models\Mensaje;
use App\Models\Organizacion;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class EntregaCampanas
{
    public function lote(string $id, string $ejecucion): bool
    {
        return Cache::store('file')->lock('campana:'.$id, 60)->block(5, function () use ($id, $ejecucion) {
            $c = Campana::find($id);
            if (! $c || $c->ejecucion !== $ejecucion || ! in_array($c->estado, ['en_cola', 'enviando'])) {
                return false;
            }
            $actor = User::find($c->enviado_por);
            $org = Organizacion::find($c->organizacion_id);
            if (! $actor || ! $org || ! Gate::forUser($actor)->allows('update', $org)) {
                $c->update(['estado' => 'pausada', 'error' => 'Se suspendió el envío porque cambiaron los permisos de la organización.']);

                return false;
            }
            $audiencia = app(AudienciaCampana::class);
            $permitidos = array_flip($audiencia->ids($c));
            $lote = array_slice($c->destinatarios ?? [], $c->procesados ?? 0, 100);
            $omitidos = $c->omitidos ?? 0;
            foreach ($lote as $usuario) {
                // Una entrega ya creada jamás se sobrescribe al reintentar, ni se restauran mensajes eliminados.
                if (Mensaje::where('campaña_id', $id)->where('usuario_id', $usuario)->exists()) {
                    continue;
                }
                if (! isset($permitidos[$usuario])) {
                    $omitidos++;

                    continue;
                }
                Mensaje::firstOrCreate(['campaña_id' => $id, 'usuario_id' => $usuario], [
                    'organizacion_id' => $c->organizacion_id, 'emisor_nombre' => $c->emisor_nombre, 'asunto' => $c->asunto, 'cuerpo' => $c->cuerpo,
                    'accion_url' => $audiencia->enlace($c, $usuario), 'texto_accion' => $c->texto_accion, 'leido_en' => null, 'primera_lectura_en' => null, 'archivado_en' => null, 'eliminado_en' => null, 'importante' => false,
                ]);
            }
            $procesados = ($c->procesados ?? 0) + count($lote);
            $fin = $procesados >= count($c->destinatarios ?? []);
            $c->update(['procesados' => $procesados, 'omitidos' => $omitidos, 'estado' => $fin ? 'enviada' : 'enviando', 'enviado_en' => $fin ? now() : null, 'error' => null]);
            if ($fin) {
                AuditoriaComunidad::create(['organizacion_id' => $c->organizacion_id, 'usuario_id' => $c->enviado_por, 'accion' => 'campana_entregada', 'entidad_id' => $id, 'antes' => null, 'despues' => ['procesados' => $procesados, 'omitidos' => $omitidos]]);
            }

            return ! $fin;
        });
    }

    public function fallo(string $id, string $ejecucion): void
    {
        Campana::where('_id', $id)->where('ejecucion', $ejecucion)->whereIn('estado', ['en_cola', 'enviando'])->update(['estado' => 'error', 'error' => 'No se completó el envío. Puedes reintentar los destinatarios pendientes.']);
    }
}
