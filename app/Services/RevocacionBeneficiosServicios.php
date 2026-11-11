<?php

namespace App\Services;

use App\Models\AsignacionBeneficio;
use App\Models\AuditoriaComunidad;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RevocacionBeneficiosServicios
{
    private const ASIGNACIONES = '/api/v1/servicios/asignaciones';

    public function puedeSolicitar(AsignacionBeneficio $a): bool
    {
        if ($a->estado !== 'asignada' || $a->revocacion !== null) { return false; }
        try {
            $this->validarOriginal($a);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** La intención y su auditoría son locales; aquí nunca se llama a E5. */
    public function solicitar(AsignacionBeneficio $a, string $actor, string $motivo): void
    {
        $registrada = Cache::store('file')->lock('beca-entrega:'.$a->id, 45)->get(function () use ($a, $actor, $motivo) {
            $a->refresh();
            $clave = 'beca:'.$a->solicitud_id.':cancelacion';
            if ($a->revocacion !== null) {
                abort_unless(($a->revocacion['clave_idempotencia'] ?? null) === $clave
                    && ($a->revocacion['motivo'] ?? null) === $motivo, 409, 'La revocación ya tiene otro motivo registrado.');
                return true;
            }
            abort_unless($this->puedeSolicitar($a), 409, 'Solo se revocan servicios E5 ya asignados con recibo confirmado; BECA y dinero quedan fuera de este proceso.');
            // E5 limita motivo a 500 caracteres. E6 conserva íntegro el texto y su huella.
            $motivoE5 = mb_strlen($motivo) <= 500 ? $motivo
                : mb_substr($motivo, 0, 410).' [SHA-256 E6: '.hash('sha256', $motivo).']';
            $intencion = ['clave_idempotencia' => $clave, 'solicitante_id' => $actor,
                'motivo' => $motivo, 'solicitado_en' => now()->toIso8601String(), 'confirmacion' => true,
                'asignacion_id' => $a->recibo_servicios['asignacion_id'], 'intentos' => 0,
                'cuerpo' => ['motivo' => $motivoE5, 'referencia_origen' => $clave]];
            DB::connection('mongodb')->transaction(function () use ($a, $actor, $intencion) {
                $a->update(['estado' => 'revocacion_pendiente', 'revocacion' => $intencion,
                    'proximo_intento_en' => null, 'error_integracion' => null]);
                AuditoriaComunidad::create(['organizacion_id' => $a->organizacion_id, 'usuario_id' => $actor,
                    'accion' => 'beca_revocacion_solicitada', 'entidad_id' => $a->solicitud_id,
                    'antes' => ['estado' => 'asignada'], 'despues' => ['estado' => 'revocacion_pendiente', 'revocacion' => $intencion]]);
            });
            return true;
        });
        abort_unless($registrada, 409, 'La entrega o revocación del beneficio está en curso. Intenta nuevamente.');
    }

    public function procesar(AsignacionBeneficio $a): void
    {
        Cache::store('file')->lock('beca-entrega:'.$a->id, 45)->get(function () use ($a) {
            $a->refresh();
            if ($a->estado !== 'revocacion_pendiente' || $a->proximo_intento_en?->isFuture()) { return; }
            try {
                $this->validarOriginal($a);
                $intencion = $a->revocacion;
                if (! is_array($intencion) || ($intencion['asignacion_id'] ?? null) !== $a->recibo_servicios['asignacion_id']
                    || ($intencion['clave_idempotencia'] ?? null) !== 'beca:'.$a->solicitud_id.':cancelacion'
                    || ($intencion['cuerpo']['referencia_origen'] ?? null) !== $intencion['clave_idempotencia']
                    || ! is_string($intencion['cuerpo']['motivo'] ?? null) || $intencion['cuerpo']['motivo'] === '') {
                    throw new RuntimeException('La intención de revocación está incompleta.');
                }
                // Se consulta SIEMPRE la clave de la entrega, nunca la clave nueva de cancelación.
                $consulta = app(ClienteModuloHttp::class)->para('beneficios', 'services:benefits:read')
                    ->get(self::ASIGNACIONES, ['clave_idempotencia' => $a->contrato['clave_idempotencia']])->throw();
                $recibos = $consulta->json('data');
                $meta = $consulta->json('meta');
                if ($consulta->status() !== 200 || ! is_array($recibos) || ! array_is_list($recibos) || count($recibos) !== 1
                    || ! is_array($meta) || ! array_key_exists('siguiente_cursor', $meta) || $meta['siguiente_cursor'] !== null) {
                    throw new RuntimeException('Consulta de asignación no concluyente; no se cancela ni se reentrega.');
                }
                $actual = $recibos[0];
                $this->validarAsignacion($actual, $a);
                if (($actual['estado'] ?? null) === 'cancelado') {
                    $this->validarCancelacion($actual, $a);
                    $this->confirmar($a, $actual, 'consulta');
                    return;
                }
                if (($actual['cancelacion'] ?? null) !== null || ! array_key_exists('cancelacion', $actual)) {
                    throw new RuntimeException('Estado de cancelación ambiguo.');
                }
                $respuesta = app(ClienteModuloHttp::class)->para('beneficios', 'services:benefits:write')
                    ->withHeader('Idempotency-Key', $intencion['clave_idempotencia'])
                    ->post(self::ASIGNACIONES.'/'.rawurlencode($intencion['asignacion_id']).'/cancelacion', $intencion['cuerpo']);
                if ($respuesta->status() === 409 && in_array($respuesta->json('codigo'), ['no_cancelable', 'ya_cancelada', 'conflicto_idempotencia'], true)) {
                    $a->update(['estado' => 'revision_requerida', 'proximo_intento_en' => null,
                        'error_integracion' => 'E5 rechazó la cancelación. Se requiere revisión; se conservan consumo e historial.',
                        'revocacion' => [...$intencion, 'rechazo_e5' => $respuesta->json(), 'rechazado_en' => now()->toIso8601String()]]);
                    return;
                }
                $respuesta->throw();
                $recibo = $respuesta->json('data');
                if ($respuesta->status() !== 200 || ! is_array($recibo) || ! is_array($recibo['asignacion'] ?? null)) {
                    throw new RuntimeException('E5 no confirmó la cancelación.');
                }
                $this->validarAsignacion($recibo['asignacion'], $a);
                $this->validarCancelacion($recibo['asignacion'], $a);
                $cancelacion = $recibo['asignacion']['cancelacion'];
                foreach (['cancelacion_id', 'liberado', 'cantidad_liberada'] as $campo) {
                    if (($recibo[$campo] ?? null) !== $cancelacion[$campo]) { throw new RuntimeException('Acuse de cancelación inconsistente.'); }
                }
                if (($recibo['estado'] ?? null) !== 'cancelado'
                    || $recibo['asignacion']['cantidad']['consumida'] < $actual['cantidad']['consumida']) {
                    throw new RuntimeException('El acuse no conserva el consumo confirmado por E5.');
                }
                $this->confirmar($a, $recibo, 'cancelacion');
            } catch (\Throwable $e) {
                $intentos = (int) ($a->revocacion['intentos'] ?? 0) + 1;
                $a->update(['revocacion' => [...($a->revocacion ?? []), 'intentos' => $intentos],
                    'proximo_intento_en' => now()->addSeconds(min(3600, 30 * (2 ** min($intentos, 7)))),
                    'error_integracion' => 'E5 no confirmó la revocación. Sigue pendiente; se consultará de nuevo con la misma clave.']);
                report($e);
            }
        });
    }

    private function confirmar(AsignacionBeneficio $a, array $acuse, string $origen): void
    {
        // Conservar literalmente la evidencia recibida; nunca sobrescribir el recibo de entrega.
        $a->update(['estado' => 'revocada', 'recibo_cancelacion' => $acuse,
            'revocacion' => [...$a->revocacion, 'confirmada_en' => now()->toIso8601String(), 'origen_acuse' => $origen],
            'proximo_intento_en' => null, 'error_integracion' => null]);
    }

    private function validarOriginal(AsignacionBeneficio $a): void
    {
        $contrato = $a->contrato;
        if (! is_array($contrato) || ($contrato['equipo_destino'] ?? null) !== 5
            || ! in_array($contrato['tipo'] ?? null, ['locker', 'impresiones'], true)
            || ($contrato['monto_centavos'] ?? null) !== 0
            || ($contrato['clave_idempotencia'] ?? null) !== 'beca:'.$a->solicitud_id
            || ($contrato['organizacion_id'] ?? null) !== $a->organizacion_id
            || ($contrato['convocatoria_id'] ?? null) !== $a->convocatoria_id
            || ($contrato['solicitud_id'] ?? null) !== $a->solicitud_id
            || ($contrato['beneficiario_id'] ?? null) !== $a->usuario_id || $a->recibo_finanzas !== null) {
            throw new RuntimeException('La asignación no es un beneficio E5 de servicio.');
        }
        $original = $a->recibo_servicios;
        $this->validarAsignacion($original, $a);
        if (($original['cancelacion'] ?? null) !== null || ! array_key_exists('cancelacion', $original)
            || ! in_array($original['estado'], $contrato['tipo'] === 'locker' ? ['asignado', 'entregado'] : ['asignado', 'en_uso', 'consumido'], true)) {
            throw new RuntimeException('No hay un recibo original de entrega confirmado.');
        }
    }

    private function validarAsignacion(mixed $recibo, AsignacionBeneficio $a): void
    {
        $original = $a->recibo_servicios;
        $contrato = $a->contrato;
        if (! is_array($recibo) || ! is_array($original)
            || ! is_string($recibo['asignacion_id'] ?? null) || trim($recibo['asignacion_id']) === ''
            || $recibo['asignacion_id'] !== ($original['asignacion_id'] ?? null)) {
            throw new RuntimeException('La asignación E5 no coincide con el recibo original.');
        }
        foreach (['clave_idempotencia', 'beneficiario_id', 'organizacion_id', 'convocatoria_id', 'solicitud_id'] as $campo) {
            if (! is_string($contrato[$campo] ?? null) || trim($contrato[$campo]) === ''
                || ($recibo[$campo] ?? null) !== $contrato[$campo] || ($recibo[$campo] ?? null) !== ($original[$campo] ?? null)) {
                throw new RuntimeException('Los identificadores del acuse no corresponden al beneficio entregado.');
            }
        }
        $locker = $contrato['tipo'] === 'locker';
        $cantidad = $recibo['cantidad'] ?? null;
        $estado = $recibo['estado'] ?? null;
        if (($recibo['beneficio_id'] ?? null) !== $contrato['tipo']
            || ! array_key_exists('folio_origen', $recibo) || $recibo['folio_origen'] !== ($contrato['folio'] ?? null)
            || ! is_array($cantidad) || ($cantidad['unidad'] ?? null) !== ($locker ? 'periodo' : 'paginas')
            || ! is_int($cantidad['asignada'] ?? null) || $cantidad['asignada'] <= 0
            || $cantidad['asignada'] !== ($contrato['cantidad'] ?? null) || $cantidad['asignada'] !== ($original['cantidad']['asignada'] ?? null)
            || ! is_int($cantidad['consumida'] ?? null) || $cantidad['consumida'] < 0 || $cantidad['consumida'] > $cantidad['asignada']
            || $cantidad['consumida'] < ($original['cantidad']['consumida'] ?? 0)
            || ! is_int($cantidad['disponible'] ?? null)
            || ! in_array($estado, $locker ? ['asignado', 'entregado', 'finalizado', 'cancelado'] : ['asignado', 'en_uso', 'consumido', 'vencido', 'cancelado'], true)) {
            throw new RuntimeException('Estado o cantidades de la asignación E5 inválidos.');
        }
        if ($locker) {
            $recurso = $recibo['recurso'] ?? null;
            if ($cantidad['asignada'] !== 1 || ! is_array($recurso) || ($recurso['tipo'] ?? null) !== 'locker'
                || ! is_string($recurso['codigo'] ?? null) || trim($recurso['codigo']) === ''
                || $recurso !== ($original['recurso'] ?? null)
                || $cantidad['disponible'] !== (in_array($estado, ['asignado', 'entregado'], true) ? 1 : 0)
                || ($estado === 'asignado' && $cantidad['consumida'] !== 0) || ($estado === 'entregado' && $cantidad['consumida'] !== 1)) {
                throw new RuntimeException('El locker no corresponde al recurso original.');
            }
        } elseif (! array_key_exists('recurso', $recibo) || $recibo['recurso'] !== null
            || $cantidad['disponible'] !== ($estado === 'cancelado' ? 0 : $cantidad['asignada'] - $cantidad['consumida'])
            || ($estado === 'asignado' && $cantidad['consumida'] !== 0)
            || ($estado === 'en_uso' && ($cantidad['consumida'] === 0 || $cantidad['disponible'] === 0))
            || ($estado === 'consumido' && $cantidad['disponible'] !== 0)) {
            throw new RuntimeException('El saldo de impresiones no es consistente.');
        }
        foreach (['inicio', 'fin_exclusiva'] as $campo) {
            $fecha = $recibo['vigencia'][$campo] ?? null;
            $this->validarFecha($fecha);
            if ($fecha !== ($original['vigencia'][$campo] ?? null)) { throw new RuntimeException('La vigencia no coincide con la entrega original.'); }
        }
    }

    private function validarCancelacion(array $asignacion, AsignacionBeneficio $a): void
    {
        $cancelacion = $asignacion['cancelacion'] ?? null;
        $intencion = $a->revocacion;
        if ($asignacion['estado'] !== 'cancelado' || ! is_array($cancelacion)
            || ! is_string($cancelacion['cancelacion_id'] ?? null) || trim($cancelacion['cancelacion_id']) === ''
            || ($cancelacion['liberado'] ?? null) !== true
            // E5 publica referencia_origen, no su clave interna; enviamos nuestra clave como referencia.
            || ($cancelacion['referencia_origen'] ?? null) !== $intencion['clave_idempotencia']
            || ($cancelacion['motivo'] ?? null) !== $intencion['cuerpo']['motivo']
            || ! is_int($cancelacion['cantidad_liberada'] ?? null) || $cancelacion['cantidad_liberada'] <= 0
            || $cancelacion['cantidad_liberada'] !== ($a->contrato['tipo'] === 'locker' ? 1
                : $asignacion['cantidad']['asignada'] - $asignacion['cantidad']['consumida'])) {
            throw new RuntimeException('E5 no confirmó esta cancelación y la cantidad liberada.');
        }
        $this->validarFecha($cancelacion['cancelada_en'] ?? null);
    }

    private function validarFecha(mixed $fecha): void
    {
        if (! is_string($fecha) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $fecha)) {
            throw new RuntimeException('Fecha de evidencia E5 inválida.');
        }
        CarbonImmutable::parse($fecha);
        if (CarbonImmutable::getLastErrors() !== false) { throw new RuntimeException('Fecha de evidencia E5 inválida.'); }
    }
}
