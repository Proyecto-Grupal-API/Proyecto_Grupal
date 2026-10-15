<?php
namespace App\Services;

use App\Models\AsignacionBeneficio;
use Illuminate\Support\Facades\Cache;

class EntregaBeneficiosServicios
{
    public function entregar(AsignacionBeneficio $a): void
    {
        if (($a->contrato['equipo_destino'] ?? null) !== 5 || ! in_array($a->contrato['tipo'] ?? null, ['locker', 'impresiones'], true)) { return; }
        Cache::store('file')->lock('beca-entrega:'.$a->id, 45)->get(function () use ($a) {
            $a->refresh();
            if ($a->estado !== 'pendiente_integracion') { return; }
            try {
                $contrato = $a->contrato;
                $r = app(ClienteModuloHttp::class)->para('beneficios', 'services:benefits:write')
                    ->withHeader('Idempotency-Key', $contrato['clave_idempotencia'])
                    ->post('/api/v1/servicios/asignaciones', $contrato)->throw();
                $recibo = $r->json('data');
                if (! is_array($recibo) || ! isset($recibo['asignacion_id'])) { throw new \RuntimeException('Respuesta de beneficio inválida.'); }
                $a->update(['estado' => 'asignada', 'recibo_servicios' => $recibo, 'entregado_en' => now(), 'error_integracion' => null]);
            } catch (\Throwable $e) {
                $attempt = (int) $a->intentos + 1;
                $a->update(['intentos' => $attempt, 'proximo_intento_en' => now()->addSeconds(min(3600, 30 * (2 ** min($attempt, 7)))),
                    'error_integracion' => 'Servicios no confirmó la asignación. Se reintentará con la misma clave.']);
                report($e);
            }
        });
    }
}
