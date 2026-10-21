# Integracion con Equipo 2 — Webhook de Pagos (Contrato Definitivo)

**Version:** 2.1 (Correcciones R1-R6 aplicadas tras auditoria del 21 de Octubre)
**Rol de Eq.4**: Servidor (receptor).

Implementamos todos los requerimientos tecnicos E4 01 a E4 07, mas las correcciones R1 a R6 de la auditoria del Equipo 2.

## 1. Endpoint
POST /api/equipo4/webhooks/payment-status

**Headers Requeridos:**
- X-Team-Timestamp: Unix epoch (segundos)
- X-Team-Signature: HMAC-SHA256 hex de timestamp|POST|/api/equipo4/webhooks/payment-status|sha256(body)
- Accept: application/json
- Content-Type: application/json

## 2. Estructura del Payload (Body)

Los campos amount, currency, occurred_at y refund_id (si aplica) son estrictamente obligatorios.

`json
{
  "event_id": "550e8400-e29b-41d4-a716-446655440000",
  "event_version": 3,
  "payment_intent_id": "PI-2026-ABCD1234",
  "refund_id": "REF-2026-XYZ",
  "order_id": "ORD-2026-XYZ",
  "business_id": "BUS-CD-SOUV-001",
  "status": "REFUNDED",
  "amount": 50.00,
  "currency": "MXN",
  "occurred_at": "2026-10-12T19:00:00-06:00",
  "items": [
    {
      "product_id": "6abffeaf31a8cb527504f753",
      "variant_id": null,
      "location_id": "6abffeaf31a8cb527504f754",
      "quantity": 1
    }
  ]
}
`",
    ",
    
1. event_id: UUID unico generado por Eq.2.
2. event_version: Entero incremental por payment_intent_id. Se verifica ANTES de procesar cualquier efecto (R4).
3. refund_id: Obligatorio si status es REFUNDED o PARTIALLY_REFUNDED (R6).
4. amount: Es el delta de la operacion (ej. 50.00 de un reembolso).
5. items: Opcional para PAID. Obligatorio si hay devolucion fisica.

## 3. Idempotencia y Concurrencia (R1, R2, R4)
- Concurrencia e Indice Unico (R1): Indice unico en event_id (uniq_payment_event_id). Dos requests simultaneos con el mismo ID reciben 202 idempotent: true en el segundo.
- Conflicto de ID (409) (R2): Mismo event_id con diferente contenido canonico (items, refund_id, etc.) se rechaza con 409 Conflict.
- Orden de eventos (R4): Si llega un event_version menor o igual al ultimo, se ignora con 409 ANTES de procesar efectos.
- Atomicidad (R3): Si hay devolucion de stock, el procesamiento es atomico (compensacion manual). Si falla, se revierte y el evento queda en FAILED.

## 4. Auto-Return y Validacion de Inventario (R5)
- Se ha eliminado el upsert.
- Se valida que product_id, variant_id y location_id existan exactamente (R5).
- Si no existen, el return_status pasa a FAILED sin crear inventario arbitrario.

## 5. Codigos de Error y Reintentos
- 202: Aceptado y persistido.
- 401: Firma HMAC invalida.
- 403: business_id mismatch.
- 409: Conflicto de version o ID.
- 422: Payload mal formado.
- 5xx: Error interno Eq.4 (Reintentar con backoff).

## 6. Indices Requeridos (R1)
Ejecutar: php artisan team4:init-indexes