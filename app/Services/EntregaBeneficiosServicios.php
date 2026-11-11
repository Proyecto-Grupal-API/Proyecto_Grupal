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
                // Consultar también en el primer intento: el proceso pudo morir tras el POST de E5.
                $consulta = app(ClienteModuloHttp::class)->para('beneficios', 'services:benefits:read')
                    ->get('/api/v1/servicios/asignaciones', ['clave_idempotencia' => $contrato['clave_idempotencia']])->throw();
                $recibos = $consulta->json('data');
                $meta = $consulta->json('meta');
                if ($consulta->status() !== 200 || ! is_array($recibos) || ! array_is_list($recibos) || count($recibos) > 1
                    || ! is_array($meta) || ! array_key_exists('siguiente_cursor', $meta) || $meta['siguiente_cursor'] !== null) {
                    throw new \RuntimeException('Consulta de beneficios no concluyente.');
                }
                if ($recibos === []) {
                    $r = app(ClienteModuloHttp::class)->para('beneficios', 'services:benefits:write')
                        ->withHeader('Idempotency-Key', $contrato['clave_idempotencia'])
                        ->post('/api/v1/servicios/asignaciones', $contrato)->throw();
                    if (! in_array($r->status(), [200, 201], true)) { throw new \RuntimeException('Asignación no confirmada.'); }
                    $recibo = $r->json('data');
                } else {
                    $recibo = $recibos[0];
                }
                $this->validarRecibo($recibo, $contrato);
                $a->update(['estado' => 'asignada', 'recibo_servicios' => $recibo, 'entregado_en' => now(),
                    'proximo_intento_en' => null, 'error_integracion' => null]);
            } catch (\Throwable $e) {
                $attempt = (int) $a->intentos + 1;
                $a->update(['intentos' => $attempt, 'proximo_intento_en' => now()->addSeconds(min(3600, 30 * (2 ** min($attempt, 7)))),
                    'error_integracion' => 'Servicios no confirmó la asignación. Se reintentará con la misma clave.']);
                report($e);
            }
        });
    }

    /** Campos de ServiceBenefitService::present(); tipo/folio del request tienen otros nombres en el recibo. */
    private function validarRecibo(mixed $recibo, array $contrato): void
    {
        if (! is_array($recibo) || ! is_string($recibo['asignacion_id'] ?? null) || trim($recibo['asignacion_id']) === '') {
            throw new \RuntimeException('Respuesta de beneficio inválida.');
        }
        foreach (['clave_idempotencia', 'beneficiario_id', 'organizacion_id', 'convocatoria_id', 'solicitud_id'] as $campo) {
            $esperado = $contrato[$campo] ?? null;
            if (! is_string($esperado) || trim($esperado) === '' || ($recibo[$campo] ?? null) !== trim($esperado)) {
                throw new \RuntimeException('El recibo no corresponde a la beca solicitada.');
            }
        }
        $locker = $contrato['tipo'] === 'locker';
        $estado = $recibo['estado'] ?? null;
        $cantidad = $recibo['cantidad'] ?? null;
        if (($recibo['beneficio_id'] ?? null) !== $contrato['tipo']
            || ! array_key_exists('folio_origen', $recibo)
            || $recibo['folio_origen'] !== (isset($contrato['folio']) ? trim((string) $contrato['folio']) : null)
            || ! in_array($estado, $locker ? ['asignado', 'entregado'] : ['asignado', 'en_uso', 'consumido'], true)
            || ! array_key_exists('cancelacion', $recibo) || $recibo['cancelacion'] !== null
            || ! is_array($cantidad) || ($cantidad['unidad'] ?? null) !== ($locker ? 'periodo' : 'paginas')
            || ! is_int($cantidad['asignada'] ?? null) || $cantidad['asignada'] <= 0
            || $cantidad['asignada'] !== (int) ($contrato['cantidad'] ?? 0)
            || ! is_int($cantidad['consumida'] ?? null) || $cantidad['consumida'] < 0 || $cantidad['consumida'] > $cantidad['asignada']
            || ! is_int($cantidad['disponible'] ?? null)) {
            throw new \RuntimeException('El recibo no confirma el beneficio solicitado.');
        }
        if ($locker) {
            $recurso = $recibo['recurso'] ?? null;
            // E5 publica disponible=1 incluso tras el primer acceso; no es un saldo de páginas.
            if ($cantidad['asignada'] !== 1 || $cantidad['disponible'] !== 1
                || $cantidad['consumida'] !== ($estado === 'entregado' ? 1 : 0)
                || ! is_array($recurso) || ($recurso['tipo'] ?? null) !== 'locker'
                || ! is_string($recurso['codigo'] ?? null) || trim($recurso['codigo']) === ''
                || (isset($contrato['edificio']) && trim((string) $contrato['edificio']) !== ''
                    && ($recurso['edificio'] ?? null) !== trim((string) $contrato['edificio']))) {
                throw new \RuntimeException('El recibo no confirma el locker asignado.');
            }
        } elseif (! array_key_exists('recurso', $recibo) || $recibo['recurso'] !== null
            || $cantidad['disponible'] !== $cantidad['asignada'] - $cantidad['consumida']
            || $estado !== match (true) {
                $cantidad['disponible'] === 0 => 'consumido',
                $cantidad['consumida'] > 0 => 'en_uso',
                default => 'asignado',
            }) {
            throw new \RuntimeException('El recibo no confirma el saldo de impresiones.');
        }
        $vigencia = [];
        foreach (['inicio', 'fin_exclusiva'] as $campo) {
            $fecha = $recibo['vigencia'][$campo] ?? null;
            if (! is_string($fecha) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $fecha)) {
                throw new \RuntimeException('Vigencia del beneficio inválida.');
            }
            $vigencia[$campo] = \Carbon\CarbonImmutable::parse($fecha);
            if (\Carbon\CarbonImmutable::getLastErrors() !== false) { throw new \RuntimeException('Vigencia del beneficio inválida.'); }
        }
        $inicio = $vigencia['inicio'];
        $fin = $vigencia['fin_exclusiva'];
        $solicitadoInicio = \Carbon\CarbonImmutable::parse($contrato['vigencia_inicio']);
        $solicitadoFin = \Carbon\CarbonImmutable::parse($contrato['vigencia_fin_exclusiva']);
        // El locker usa su periodo real; impresiones conserva los instantes de la convocatoria.
        if ($inicio->greaterThanOrEqualTo($fin)
            || ($locker ? ($inicio->greaterThanOrEqualTo($solicitadoFin) || $fin->lessThanOrEqualTo($solicitadoInicio))
                : (! $inicio->equalTo($solicitadoInicio) || ! $fin->equalTo($solicitadoFin)))) {
            throw new \RuntimeException('La vigencia no corresponde al beneficio solicitado.');
        }
    }
}
