<?php

namespace App\Services;

use App\Models\AuditoriaComunidad;
use App\Models\AvisoGeneral;
use App\Models\Mensaje;
use App\Models\User;

/**
 * Entrega por lotes de un aviso general a la bandeja interna (Mensaje) de cada cuenta vigente. Reanudable e idempotente:
 * avanza por _id y nunca duplica ni sobrescribe un mensaje ya entregado. Sin correo ni SMS.
 */
class EntregaAvisoGeneral
{
    public const LOTE = 200;

    /** @return bool true si quedan cuentas por procesar */
    public function lote(string $id): bool
    {
        $aviso = AvisoGeneral::findOrFail($id);
        $q = User::query()->orderBy('_id')->limit(self::LOTE); // sin bajas lógicas (SoftDeletes)
        if ($aviso->ultimo_usuario) {
            $q->where('_id', '>', new \MongoDB\BSON\ObjectId($aviso->ultimo_usuario));
        }
        $usuarios = $q->get(['_id']);
        $nuevos = 0;
        foreach ($usuarios as $usuario) {
            $m = Mensaje::firstOrCreate(['clave_idempotencia' => 'aviso-general:'.$id, 'usuario_id' => (string) $usuario->getKey()], [
                'organizacion_id' => null, 'emisor_nombre' => $aviso->autor_nombre, 'asunto' => $aviso->asunto, 'cuerpo' => $aviso->cuerpo,
                'categoria' => 'institucional', 'tipo_aviso' => 'aviso_general', 'importante' => true,
                'leido_en' => null, 'primera_lectura_en' => null, 'archivado_en' => null, 'eliminado_en' => null,
            ]);
            $m->wasRecentlyCreated && $nuevos++;
        }
        $fin = $usuarios->count() < self::LOTE;
        $aviso->update(['entregados' => Mensaje::where('clave_idempotencia', 'aviso-general:'.$id)->count(),
            'ultimo_usuario' => $usuarios->isEmpty() ? $aviso->ultimo_usuario : (string) $usuarios->last()->getKey(),
            'estado' => $fin ? 'enviado' : 'enviando', 'enviado_en' => $fin ? now() : null, 'error' => null]);
        if ($fin && $nuevos > 0) {
            AuditoriaComunidad::create(['organizacion_id' => null, 'usuario_id' => $aviso->autor_id, 'accion' => 'aviso_general_entregado',
                'entidad_id' => $id, 'antes' => null, 'despues' => ['entregados' => $aviso->entregados]]);
        }

        return ! $fin;
    }
}
