# Integracion con Equipo 3 - Marketplace

**Rol de Eq.4**: Servidor (exponemos API a Eq.3)
**Controller**: `App\Api\Team3IntegrationController`
**Base path**: `/api/equipo4/integration`

## Contexto

Eq.3 vende productos de Eq.4. Antes de cerrar una venta, Eq.3 consulta disponibilidad, reserva stock, confirma el descuento cuando el pago pasa, y libera si se cancela.

Ademas, Eq.3 nos notifica devoluciones, transferencias y ajustes de inventario.

## Endpoints expuestos (8)

Todos requieren firma HMAC-SHA256 con `TEAM_SERVICE_SHARED_SECRET`.

### 1. POST /availability

Request:
```json
{ "items": [ { "product_id": "...", "variant_id": null, "location_id": "...", "quantity": 2 } ] }
```

Response 200:
```json
{
  "available": true,
  "items": [{
    "product_id": "...", "variant_id": null, "location_id": "...",
    "requested": 2, "available": 125, "has_enough": true
  }]
}
```

### 2. POST /reservations

Request:
```json
{
  "order_id": "ORD-2026-ABC",
  "items": [{ "product_id": "...", "variant_id": null, "location_id": "...", "quantity": 2 }],
  "idempotency_key": "reserve-ORD-2026-ABC-v1",
  "source": "CHECKOUT",
  "expires_at": "2026-10-02T21:00:00-06:00"
}
```

Response 201:
```json
{ "reservation_id": "01JXXXX...", "status": "RESERVED", "items": [], "expires_at": "..." }
```

Response 409 (sin stock):
```json
{ "status": "REJECTED", "reason": "Existencia insuficiente para product_id=..." }
```

Idempotencia: misma `idempotency_key` -> mismo `reservation_id`.

Estado inicial: Eq.4 crea la reserva directamente en RESERVED (sin PENDING).

### 3. POST /reservations/{id}/confirm

Request:
```json
{ "order_id": "ORD-2026-ABC", "payment_intent_id": "PI-2026-ABCD1234", "idempotency_key": "confirm-..." }
```

Response 200 (pago OK):
```json
{
  "reservation_id": "01JXXXX...",
  "status": "CONFIRMED",
  "movements": [{ "product_id": "...", "quantity": -2, "type": "SALE" }]
}
```

Response 404 (reserva no existe):
```json
{ "error": "reservation_not_found", "message": "La reserva no existe." }
```

Response 409 (existe pero sin pago registrado o en estado distinto a PAID):
```json
{ "status": "REJECTED", "reason": "No hay registro de pago para este payment_intent_id" }
```

Verificacion: Eq.4 valida que exista un evento PAID con ese `payment_intent_id` en `payment_events` (poblado via webhook de Eq.2).

### 4. POST /reservations/{id}/release

Request:
```json
{ "order_id": "ORD-2026-ABC", "reason": "CUSTOMER_CANCELLED", "idempotency_key": "release-..." }
```

Response 200:
```json
{ "reservation_id": "01JXXXX...", "status": "RELEASED", "released_at": "..." }
```

### 5. GET /reservations/{id}

Response 200:
```json
{
  "reservation_id": "01JXXXX...",
  "status": "RESERVED | CONFIRMED | RELEASED",
  "items": [],
  "expires_at": "...",
  "payment": { "payment_intent_id": "PI-...", "status": "PAID", "confirmed_at": "..." }
}
```

### 6. POST /returns

Request (cuerpo plano, un solo item):
```json
{
  "order_id": "ORD-2026-ABC",
  "product_id": "...", "variant_id": null, "location_id": "...",
  "quantity": 1, "reason": "DEFECT",
  "idempotency_key": "return-ORD-2026-ABC-1"
}
```

Tambien acepta `items: [...]` (bulk) para multiples productos.

Response 201:
```json
{
  "order_id": "ORD-2026-ABC",
  "status": "RETURNED",
  "items": [ { "product_id": "...", "variant_id": null, "location_id": "...", "quantity": 1 } ],
  "processed_at": "2026-10-02T20:00:00-06:00"
}
```

### 7. POST /transfers

Request:
```json
{
  "from_location_id": "...", "to_location_id": "...",
  "items": [{ "product_id": "...", "quantity": 5 }],
  "idempotency_key": "transfer-XXX"
}
```

### 8. POST /adjustments

Request:
```json
{
  "product_id": "...", "variant_id": null, "location_id": "...",
  "new_quantity": 42,
  "reason": "SHRINKAGE",
  "idempotency_key": "adjust-XXX"
}
```

Tambien acepta `items: [...]` (bulk) para multiples productos.

## Errores comunes

| Codigo | Cuando |
|---|---|
| 401 | Firma HMAC invalida |
| 403 | business_id distinto a BUS-CD-SOUV-001 |
| 404 | Reserva no encontrada |
| 409 | Estado invalido (ya confirmada, ya liberada, sin stock, sin pago) |
| 422 | Payload invalido |

## Idempotencia

Todos los POST aceptan `idempotency_key`. Eq.4 guarda el resultado en `integration_idempotency` con indice unico `(service, idempotency_key)`. Misma key -> mismo resultado, sin efectos duplicados.

## Pendiente con Eq.3

1. Confirmar que incluiran `payment_intent_id` en el body del `/confirm` y de donde lo obtienen (se los pasa Eq.2?)
2. Documentar que Eq.4 no usa PENDING - la reserva se crea directamente en RESERVED
