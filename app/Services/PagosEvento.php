<?php

namespace App\Services;

use App\Models\Evento;
use App\Models\Organizacion;
use App\Models\PagoReservaEvento;
use App\Models\RegistroEvento;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class PagosEvento
{
    public function __construct(private InscripcionesEvento $inscripciones, private ClienteModuloHttp $http) {}

    public function iniciar(string $eventoId, string $usuario, int $generacion): array
    {
        abort_unless(config('comunidad.eventos_pagos.enabled') === true, 404);
        $cliente = config('comunidad.eventos_pagos.client_id');
        abort_unless(is_string($cliente) && $cliente !== '', 503, 'Falta configurar la conexión con Finanzas.');

        $pago = $this->inscripciones->ejecutar($eventoId, function (Evento $evento) use ($usuario, $generacion, $cliente) {
            $reg = RegistroEvento::where('evento_id', (string) $evento->id)->where('usuario_id', $usuario)->firstOrFail();
            $this->inscripciones->sincronizar($evento);
            $reg->refresh();
            abort_unless($this->activo($evento) && max(1, (int) $reg->generacion) === $generacion
                && $reg->estado === 'confirmada' && $reg->estado_pago === 'pendiente' && $reg->monto_centavos > 0
                && $reg->moneda === 'MXN' && $reg->reserva_hasta?->isFuture(), 409, 'La inscripción no tiene un lugar de pago vigente.');
            $pago = PagoReservaEvento::firstOrCreate(['registro_id' => (string) $reg->id, 'generacion' => $generacion], [
                'estado' => 'pendiente', 'usuario_id' => $usuario, 'evento_id' => (string) $evento->id,
                'organizacion_id' => (string) $evento->organizacion_id, 'monto_centavos' => $reg->monto_centavos,
                'vence_en' => $reg->reserva_hasta, 'cliente_id' => $cliente,
            ]);
            abort_unless($pago->estado === 'pendiente' && ! $pago->cierre_solicitado_en, 409, 'El intento está en proceso de cierre.');
            $reg->update(['pago_estado' => 'pendiente']);

            return $pago;
        });

        $previo = $this->solicitar($pago, 'read');
        $result = $previo ?? $this->solicitar($pago, 'prepare');
        $estado = $this->aplicar($pago, $result);

        return ['estado' => $estado, 'confirmation_path' => $estado === 'pendiente' ? $result['confirmation_path'] : null];
    }

    public function recuperar(PagoReservaEvento $pago): string
    {
        if ($pago->estado === 'devolucion_pendiente') {
            $result = $this->solicitar($pago, 'read');

            return $result ? $this->aplicarDevolucion($pago, $result) : 'devolucion_pendiente';
        }
        if ($pago->estado !== 'pendiente') {
            return $pago->estado;
        }

        $accion = $pago->cierre_solicitado_en || $pago->vence_en->lte(now()) ? 'cancel' : 'read';
        $result = $this->solicitar($pago, $accion);

        return $result ? $this->aplicar($pago, $result) : 'pendiente';
    }

    private function aplicarDevolucion(PagoReservaEvento $pago, array $result): string
    {
        $receipt = $result['refund'] ?? null;
        if ($receipt === null) {
            return 'devolucion_pendiente';
        }
        abort_unless(is_array($receipt) && $result['status'] === 'CONFIRMED'
            && Str::isUuid($receipt['refund_id'] ?? null)
            && ($receipt['original_payment_id'] ?? null) === $result['payment_id']
            && ($receipt['amount_cents'] ?? null) === $pago->monto_centavos
            && ($receipt['currency'] ?? null) === 'MXN', 503, 'La devolución no coincide con el cobro de la reserva.');

        return $this->inscripciones->ejecutar($pago->evento_id, function (Evento $evento) use ($pago, $result, $receipt) {
            $pago->refresh();
            if ($pago->estado !== 'devolucion_pendiente') {
                return $pago->estado;
            }
            abort_unless($pago->comprobante === $result['payment_id'], 503, 'La devolución corresponde a otro cobro.');
            $pago->update(['estado' => 'devuelto', 'comprobante_devolucion' => $receipt['refund_id']]);
            RegistroEvento::whereKey($pago->registro_id)->where('evento_id', $pago->evento_id)
                ->where('usuario_id', $pago->usuario_id)->where('generacion', $pago->generacion)
                ->where('pago_estado', 'devolucion_pendiente')->update(['pago_estado' => 'devuelto']);
            $this->inscripciones->auditar($evento, null, 'evento_pago_devuelto', (string) $pago->id, null, [
                'registro_id' => $pago->registro_id, 'generacion' => $pago->generacion,
                'referencia_financiera' => $receipt['refund_id'],
            ]);

            return 'devuelto';
        });
    }

    private function solicitar(PagoReservaEvento $pago, string $accion): ?array
    {
        abort_unless(config('comunidad.eventos_pagos.enabled') === true
            && config('comunidad.eventos_pagos.client_id') === $pago->cliente_id, 503, 'La conexión original con Finanzas no está disponible.');
        $origen = '/api/v1/financial/community/event-reservations/'.$pago->registro_id.'/generations/'.$pago->generacion.'/payment';
        $snapshot = [
            'student_id' => $pago->usuario_id, 'event_id' => $pago->evento_id, 'organization_id' => $pago->organizacion_id,
            'amount_cents' => $pago->monto_centavos, 'currency' => 'MXN',
            'reservation_expires_at' => $pago->vence_en->copy()->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
        try {
            $http = $this->http->para('comunidad_finanzas', 'financial:event-payment:'.$accion)->withoutRedirecting();
            $response = match ($accion) {
                'read' => $http->get($origen),
                'prepare' => $http->withHeader('Idempotency-Key', $pago->referencia())->post($origen, $snapshot),
                'cancel' => $http->post($origen.'/cancel', []),
            };
        } catch (HttpClientException|RuntimeException) {
            abort(503, 'Finanzas no confirmó el resultado. Intenta consultarlo nuevamente.');
        }
        if ($accion === 'read' && $response->notFound()) {
            return null;
        }
        abort_unless($response->successful(), 503, 'Finanzas no confirmó el resultado. Intenta consultarlo nuevamente.');
        $data = $response->json('data');
        $status = $data['status'] ?? null;
        $requestId = $data['request_id'] ?? null;
        abort_unless(is_array($data) && ($data['source_reference'] ?? null) === $pago->referencia()
            && is_string($requestId) && Str::isUuid($requestId)
            && in_array($status, ['PENDING', 'CONFIRMED', 'CANCELLED', 'EXPIRED'], true), 503, 'Finanzas devolvió una referencia inválida.');
        $tombstone = $status === 'CANCELLED' && ($data['student_id'] ?? null) === null
            && ($data['event_id'] ?? null) === null && ($data['organization_id'] ?? null) === null
            && ($data['amount_cents'] ?? null) === null && ($data['reservation_expires_at'] ?? null) === null;
        foreach ($snapshot as $field => $expected) {
            abort_unless($tombstone || ($data[$field] ?? null) === $expected, 503, 'La evidencia financiera no coincide con la reserva.');
        }
        if ($status === 'CONFIRMED') {
            abort_unless(is_string($data['payment_id'] ?? null) && Str::isUuid($data['payment_id'])
                && is_string($data['confirmed_at'] ?? null) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z\z/', $data['confirmed_at']), 503);
        } else {
            abort_unless(($data['payment_id'] ?? null) === null, 503);
        }
        if ($status === 'PENDING') {
            abort_unless(($data['confirmation_path'] ?? null) === '/finanzas/eventos/solicitudes/'.$requestId, 503);
        }

        return $data;
    }

    private function aplicar(PagoReservaEvento $pago, array $result): string
    {
        return $this->inscripciones->ejecutar($pago->evento_id, function (Evento $evento) use ($pago, $result) {
            $pago->refresh();
            if ($pago->estado !== 'pendiente') {
                return $pago->estado;
            }
            if ($result['status'] === 'PENDING') {
                // Never expose the confirmation path to a browser after a concurrent close request.
                abort_if($pago->cierre_solicitado_en || $pago->vence_en->lte(now()), 409, 'La reserva está en proceso de cierre.');

                return 'pendiente';
            }
            $reg = RegistroEvento::find($pago->registro_id);
            $actual = $reg && $reg->evento_id === $pago->evento_id && $reg->usuario_id === $pago->usuario_id
                && max(1, (int) $reg->generacion) === $pago->generacion;
            $vigente = $actual && $reg->estado === 'confirmada' && $reg->estado_pago === 'pendiente'
                && $reg->reserva_hasta?->isFuture() && $reg->reserva_hasta->equalTo($pago->vence_en)
                && $reg->monto_centavos === $pago->monto_centavos && $reg->moneda === 'MXN'
                && (string) $evento->organizacion_id === $pago->organizacion_id && $this->activo($evento);
            if ($result['status'] === 'CONFIRMED') {
                $confirmado = Carbon::parse($result['confirmed_at']);
                abort_if($confirmado->isFuture(), 503, 'La fecha del comprobante requiere revisión.');
                $enPlazo = $confirmado->lt($pago->vence_en);
                $aplicar = $vigente && $enPlazo;
                $estado = $aplicar ? 'aplicado' : 'devolucion_pendiente';
                if ($aplicar) {
                    $reg->update(['estado_pago' => 'pagado', 'pago_estado' => $estado]);
                } elseif ($actual) {
                    $reg->update(['pago_estado' => $estado]);
                }
                $pago->update(['estado' => $estado, 'comprobante' => $result['payment_id'], 'confirmado_en' => $result['confirmed_at']]);
                $this->inscripciones->auditar($evento, null, 'evento_pago_'.$estado, (string) $pago->id, null, [
                    'registro_id' => $pago->registro_id, 'generacion' => $pago->generacion, 'referencia_financiera' => $result['payment_id'],
                ]);
                if (! $aplicar) {
                    $this->inscripciones->sincronizar($evento);
                }

                return $estado;
            }

            $pago->update(['estado' => 'cerrado', 'resultado_financiero' => $result['status']]);
            if ($actual && $reg->estado === 'confirmada' && $reg->estado_pago === 'pendiente' && $pago->cierre_solicitado_en && $reg->reserva_hasta?->isFuture()) {
                $this->inscripciones->cerrarReserva($evento, $reg);
            } else {
                $this->inscripciones->sincronizar($evento);
            }

            return 'cerrado';
        });
    }

    private function activo(Evento $evento): bool
    {
        return $evento->estado === 'publicado' && $evento->fecha_hora_fin?->isFuture()
            && Organizacion::whereKey($evento->organizacion_id)->where('estado', 'activa')->exists();
    }
}
