<?php

namespace App\Services;

use App\Models\AsignacionBeneficio;
use App\Models\ConvocatoriaBeca;
use App\Models\SolicitudBeca;

class BeneficiosBeca
{
    /** Contrato pendiente: no ejecuta pagos ni reserva recursos de otros equipos. */
    public function preparar(SolicitudBeca $s, ConvocatoriaBeca $c): AsignacionBeneficio
    {
        return AsignacionBeneficio::firstOrCreate(['solicitud_id' => (string) $s->id], [
            'organizacion_id' => (string) $c->organizacion_id, 'convocatoria_id' => (string) $c->id, 'usuario_id' => (string) $s->usuario_id, 'estado' => 'pendiente_integracion',
            'contrato' => [
                'version' => 1, 'clave_idempotencia' => 'beca:'.$s->id, 'equipo_destino' => $c->beneficio['equipo'],
                'beneficiario_id' => (string) $s->usuario_id, 'organizacion_id' => (string) $c->organizacion_id, 'convocatoria_id' => (string) $c->id,
                'solicitud_id' => (string) $s->id, 'folio' => $s->folio, 'tipo' => $c->beneficio['slug'], 'monto_centavos' => $c->monto_centavos, 'moneda' => 'MXN',
                'cantidad' => $c->cantidad, 'vigencia_inicio' => $c->vigencia_inicio->toIso8601String(), 'vigencia_fin_exclusiva' => $c->vigencia_fin->toIso8601String(),
                'reglas' => $c->requisitos,
            ],
        ]);
    }
}
