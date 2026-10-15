<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class DelegacionOrganizacion extends Documento
{
    protected $table = 'delegaciones_organizacion';

    protected $fillable = ['organizacion_id', 'destinatario_id', 'delegante_id', 'cargo_origen_id', 'firma_origen', 'version_organizacion', 'capacidades', 'inicio', 'fin', 'motivo', 'clave_idempotencia', 'huella', 'revocada_en', 'revocada_por', 'motivo_revocacion'];

    protected $hidden = ['firma_origen', 'huella'];

    protected function casts(): array
    {
        return ['capacidades' => 'array', 'inicio' => 'datetime', 'fin' => 'datetime', 'revocada_en' => 'datetime', 'version_organizacion' => 'integer'];
    }

    public function scopeVigentes(Builder $query): void
    {
        $query->whereNull('revocada_en')->where('inicio', '<=', now())->where('fin', '>', now());
    }
}
