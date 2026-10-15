<?php

namespace App\Models;

class Mensaje extends Documento
{
    protected $table = 'mensajes';

    protected $fillable = ['campaña_id', 'organizacion_id', 'usuario_id', 'asunto', 'cuerpo', 'emisor_nombre', 'accion_url', 'texto_accion', 'leido_en', 'primera_lectura_en', 'archivado_en', 'importante', 'eliminado_en', 'cliente_id', 'clave_idempotencia', 'huella', 'resultado_envio', 'estado_entrega', 'categoria', 'tipo_aviso', 'expira_en', 'retirada_en', 'motivo_retiro'];

    public function scopeVisibles($q)
    {
        return $q->whereNull('eliminado_en')->whereNull('retirada_en')
            ->where(fn ($q) => $q->whereNull('estado_entrega')->orWhere('estado_entrega', 'entregada'))
            ->where(fn ($q) => $q->whereNull('expira_en')->orWhere('expira_en', '>', now()));
    }

    protected function casts(): array
    {
        return ['leido_en' => 'datetime', 'primera_lectura_en' => 'datetime', 'archivado_en' => 'datetime', 'eliminado_en' => 'datetime', 'importante' => 'boolean', 'expira_en' => 'datetime', 'retirada_en' => 'datetime'];
    }
}
