# Integracion con Equipo 2 — Webhook de Pagos (Contrato Definitivo)

**Version:** 2.0 (Alineado a la solicitud de Eq.2 del 12 de Octubre)
**Rol de Eq.4**: Servidor (receptor).

Implementamos todos los requerimientos tecnicos E4 01 a E4 07. A continuacion, el contrato actualizado.

## 1. Endpoint
`POST /api/equipo4/webhooks/payment-status`

**Headers Requeridos:**
- `X-Team-Timestamp`: Unix epoch (segundos)
- `X-Team-Signature`: HMAC-SHA256 hex de `timestamp|POST|/api/equipo4/webhooks/payment-status|sha256(body)`
- `Accept: application/json`
- `Content-Type: application/json`

## 2. Estructura del Payload (Body)

Los campos `amount`, `currency` y `occurred_at` ahora son **estrictamente obligatorios**.

```json
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
```

### Reglas de Negocio (E4 01 - E4 07)
1. **event_id**: UUID unico generado por Eq.2 por cada intento de webhook.
2. **event_version**: Entero incremental (1, 2, 3...) por `payment_intent_id`. Sirve para evitar desorden.
3. **refund_id**: Obligatorio si `status` es `REFUNDED` o `PARTIALLY_REFUNDED`.
4. **amount**: Es el **delta** de la operacion (ej. $50.00 de un reembolso, no el total de $150.00).
5. **items**: Opcional para `PAID`. Obligatorio si hay devolucion fisica de inventario.

## 3. Idempotencia y Concurrencia (E4 02, E4 03, E4 04)

- **Concurrencia**: Tenemos un indice unico en `event_id`. Si envias 2 requests simultaneos con el mismo ID, solo uno se procesa. El segundo recibe un `202 idempotent: true`.
- **Conflicto de ID (409)**: Si mandas el mismo `event_id` pero con diferente `status` o `amount`, el sistema lo rechaza con un `409 Conflict`.
- **Orden de eventos**: Si llega un `event_version` menor o igual al ultimo registrado, se ignora con `409` para evitar que un `PAID` retrasado sobrescriba un `REFUNDED`.
- **Atomicidad**: El webhook se guarda primero y se responde `202`. Si hay devolucion de stock, el evento queda con `return_status: PENDING`. Un worker interno lo procesa a `PROCESSED` o `FAILED`. Esto garantiza que el 202 signifique "recibido y guardado de forma duradera".

## 4. Auto-Return y Validacion de Inventario (E4 05)

- Se ha **eliminado el `upsert`**.
- Si un `REFUNDED` trae `items`, Eq.4 validara que el `product_id` y `location_id` existan.
- Si no existen, el evento se guarda, pero el `return_status` pasa a `FAILED` con un log de diagnostico.
- **No se crea inventario arbitrario**.

## 5. Confirmacion de Eq.3 (E4 06)

- Eq.4 sigue manejando `/confirm` exclusivamente para Eq.3.
- Si Eq.3 llama a `/confirm` y el pago no ha llegado via webhook (`PAID`), Eq.4 devolvera `409` indicando "No hay registro de pago".
- Si Eq.3 reintenta el `/confirm` con la misma `idempotency_key`, no habra doble descuento de stock.

## 6. Codigos de Error y Reintentos (E4 07)

| Codigo | Significado | Accion de Eq.2 |
| --- | --- | --- |
| 202 | Aceptado y persistido | Nada. Si es `idempotent:true`, es un reintento exitoso. |
| 401 | Firma HMAC invalida | Corregir credenciales. No reintentar a ciegas. |
| 403 | business_id mismatch | Corregir el body. |
| 409 | Conflicto de version o ID | Revisar la version o si el ID se reuso con otro body. No reintentar. |
| 422 | Payload mal formado | Corregir el body. No reintentar. |
| 5xx | Error interno Eq.4 | Reintentar con backoff: 5s, 30s, 5m, 30m, 2h. |

**Firma de Reintentos:** En cada reintento, deben generar un **nuevo timestamp y nueva firma**, pero conservar el mismo `event_id`, `event_version` y cuerpo del evento.
