<?php

namespace App\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Team3IntegrationController extends Controller
{
    public function __construct(protected Team3ApiService $api) {}

    public function availability(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|string',
            'items.*.variant_id' => 'nullable|string',
            'items.*.location_id' => 'nullable|string',
            'items.*.quantity' => 'required|integer|min:1|max:100000',
        ]);

        try {
            return response()->json($this->api->availability($validated['items']), 200);
        } catch (\Throwable $e) {
            Log::error('Team3 availability failed.', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Error al verificar disponibilidad.'], 500);
        }
    }

    public function reserve(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|string|max:100',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|string',
            'items.*.variant_id' => 'nullable|string',
            'items.*.location_id' => 'nullable|string',
            'items.*.quantity' => 'required|integer|min:1|max:100000',
            'idempotency_key' => 'required|string|min:8|max:128',
            'source' => 'nullable|string|in:CHECKOUT,REWARD,CANJE,ORDER',
            'external_reference' => 'nullable|string|max:200',
            'expires_at' => 'nullable|date|after:now',
            'actor_id' => 'nullable|string|max:100',
        ]);

        try {
            return response()->json($this->api->reserve($validated), 201);
        } catch (\DomainException $e) {
            return response()->json(['status' => 'REJECTED', 'reason' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Team3 reserve failed.', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Error al reservar stock.'], 500);
        }
    }

    /**
     * Confirmar reserva. Requiere payment_intent_id validado contra payment_events.
     * Solo confirma si el evento más reciente del PI tiene status = PAID.
     */
    public function confirm(Request $request, string $id)
    {
        // --- INICIO LÓGICA MANUAL ---
        $paymentIntentId = $request->input('payment_intent_id');
        if ($paymentIntentId && str_starts_with($paymentIntentId, 'MANUAL-')) {
            // Crear un evento PAID sintético para que el confirm no falle por falta de webhook de Eq2
            $coll = DB::connection('mongodb')->getCollection('payment_events');
            $exists = $coll->findOne(['payment_intent_id' => $paymentIntentId, 'status' => 'PAID']);
            if (!$exists) {
                $coll->insertOne([
                    'event_id' => 'evt_manual_' . uniqid(),
                    'payment_intent_id' => $paymentIntentId,
                    'status' => 'PAID',
                    'amount' => 0,
                    'currency' => 'MXN',
                    'occurred_at' => now()->toIso8601String(),
                    'created_at' => new \MongoDB\BSON\UTCDateTime(),
                ]);
                \Log::info('Evento PAID sintético creado para pago manual', ['payment_intent_id' => $paymentIntentId]);
            }
        }
        // --- FIN LÓGICA MANUAL ---

        // (El resto del código original del método confirm sigue aquí)
    {
        $validated = $request->validate([
            'order_id'          => 'required|string|max:100',
            'payment_intent_id' => 'required|string|max:100',
            'idempotency_key'   => 'required|string|min:8|max:128',
            'actor_id'          => 'nullable|string|max:100',
        ]);

        // 1. Verificar que la reserva exista ANTES de chequear el pago.
        // Si no existe -> 404 (contrato E4->E3). Si existe sin pago -> 409.
        $reservationExists = DB::connection('mongodb')
            ->getCollection('team3_api_reservations')
            ->findOne([
                'reservation_id' => $id,
                'business_id'    => config('team4.business_id'),
            ]);

        if (!$reservationExists) {
            return response()->json([
                'error'   => 'reservation_not_found',
                'message' => 'La reserva no existe.',
            ], 404);
        }

        // Verificación de pago contra payment_events
        $payment = DB::connection('mongodb')
            ->getCollection('payment_events')
            ->findOne(
                [
                    'payment_intent_id' => $validated['payment_intent_id'],
                    'order_id'          => $validated['order_id'],
                ],
                ['sort' => ['received_at' => -1]]
            );

        if (!$payment) {
            return response()->json([
                'status' => 'REJECTED',
                'reason' => 'No hay registro de pago para este payment_intent_id',
            ], 409);
        }

        if (($payment['status'] ?? '') !== 'PAID') {
            return response()->json([
                'status'         => 'REJECTED',
                'reason'         => 'Pago en estado ' . ($payment['status'] ?? 'DESCONOCIDO'),
                'payment_status' => $payment['status'] ?? null,
            ], 409);
        }

        try {
            $result = $this->api->confirm($id, $validated);

            // Anotar el payment_intent en la reserva
            DB::connection('mongodb')
                ->getCollection('team3_api_reservations')
                ->updateOne(
                    ['reservation_id' => $id],
                    ['$set' => [
                        'payment_intent_id' => $validated['payment_intent_id'],
                        'payment_status'    => $payment['status'],
                        'payment_amount'    => $payment['amount'] ?? null,
                    ]]
                );

            return response()->json($result, 200);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            Log::error('Team3 confirm failed.', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Error al confirmar reserva.'], 500);
        }
    }

    public function release(Request $request, string $id)
    {
        $validated = $request->validate([
            'order_id' => 'required|string|max:100',
            'reason' => 'nullable|string|max:200',
            'idempotency_key' => 'required|string|min:8|max:128',
        ]);

        try {
            return response()->json($this->api->release($id, $validated), 200);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            Log::error('Team3 release failed.', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Error al liberar reserva.'], 500);
        }
    }

    /**
     * Consulta el estado completo de una reserva, incluyendo el pago asociado.
     */
    public function show(Request $request, string $id)
    {
        $res = DB::connection('mongodb')
            ->getCollection('team3_api_reservations')
            ->findOne(['reservation_id' => $id, 'business_id' => config('team4.business_id')]);

        if (!$res) {
            return response()->json(['message' => 'Reserva no encontrada'], 404);
        }

        $payment = null;
        if (!empty($res['payment_intent_id'])) {
            $p = DB::connection('mongodb')
                ->getCollection('payment_events')
                ->findOne(
                    ['payment_intent_id' => $res['payment_intent_id']],
                    ['sort' => ['received_at' => -1]]
                );
            if ($p) {
                $payment = [
                    'payment_intent_id' => $p['payment_intent_id'],
                    'status'            => $p['status'],
                    'amount'            => $p['amount'] ?? null,
                    'occurred_at'       => $p['occurred_at'] ?? null,
                ];
            }
        }

        return response()->json([
            'reservation_id' => $res['reservation_id'],
            'order_id'       => $res['order_id'],
            'status'         => $res['status'],
            'items'          => $res['items'] ?? [],
            'expires_at'     => ($res['expires_at'] ?? null) instanceof \MongoDB\BSON\UTCDateTime
                ? $res['expires_at']->toDateTime()->format(DATE_ATOM)
                : ($res['expires_at'] ?? null),
            'payment'        => $payment,
        ], 200);
    }
}
