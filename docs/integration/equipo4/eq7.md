# Integracion con Equipo 7 - Recompensas

**Rol de Eq.4**: Servidor (exponemos API a Eq.7)
**Controller**: `App\Api\Team7ApiController`
**Adapter**: `App\Services\Team7AdapterService`
**Base path**: `/api/v1/inventory`

## Contexto

Eq.7 canjea recompensas fisicas. Necesita reservar stock cuando un usuario aplica una recompensa, confirmar el consumo cuando se entrega, y liberar cuando expira.

Eq.4 expone una API adaptada al vocabulario de Eq.7 (SKUs en lugar de IDs de Mongo, `origin_reference` en lugar de `order_id`, etc.) que internamente reusa el mismo motor de reservas de Eq.3.

## Endpoints expuestos (5)

Todos requieren firma HMAC-SHA256 con `TEAM_SERVICE_SHARED_SECRET`.

### 1. GET /availability

Query params:
- `product_reference` (required): SKU o ObjectId
- `variant_sku` (optional)
- `location_id` (optional)
- `quantity` (optional, default 1)
- `business_id` (optional)

Response 200:
```json
{
  "available": true,
  "available_quantity": 48,
  "warehouse_id": "6abf...",
  "items": [{
    "product_reference": "SOUV-TAZA-001",
    "variant_sku": null,
    "requested": 2,
    "available": 48,
    "has_enough": true,
    "warehouse_id": "6abf..."
  }]
}
```

Response 404 (SKU inexistente):
```json
{ "error": "product_not_found", "message": "product_reference no encontrado: XXX" }
```

Tambien hay `POST /availability` con body `{ items: [...] }` para bulk.

### 2. POST /reservations

Acepta body flat (spec E7):
```json
{
  "origin_reference": "RWD-2026-ABC123",
  "origin_type": "REWARD",
  "product_reference": "SOUV-TAZA-001",
  "variant_sku": null,
  "location_id": null,
  "quantity": 1,
  "idempotency_key": "e7-reserve-RWD-2026-ABC123",
  "expires_at": "2026-10-02T21:00:00-06:00"
}
```

Tambien acepta `items: [...]` (bulk).

Response 201:
```json
{
  "reservation_id": "01JXXXX...",
  "status": "reserved",
  "origin_reference": "RWD-2026-ABC123",
  "items": [],
  "expires_at": "..."
}
```

Response 404 (SKU no existe):
```json
{ "error": "product_not_found", "message": "..." }
```

Response 409 (sin stock):
```json
{ "error": "insufficient_stock", "reason": "Existencia insuficiente..." }
```

Mapeo de `origin_type`:
- `REWARD` / `reward` / `recompensa` / `reward_redemption` -> `REWARD`
- `CANJE` / `redemption` -> `CANJE`

### 3. POST /reservations/{id}/confirm

**NO requiere `payment_intent_id`** - Eq.7 es canje, no pago.

Request:
```json
{ "delivered_by": "STAFF-001", "delivered_at": "...", "idempotency_key": "e7-confirm-..." }
```

Response 200:
```json
{
  "reservation_id": "01JXXXX...",
  "status": "consumed",
  "stock_movement_id": "6712abc...",
  "confirmed_at": "..."
}
```

Efecto: reduce `on_hand` y `reserved`, registra movimiento SALE.

### 4. POST /reservations/{id}/release

Request:
```json
{ "reason": "CANCELLED", "idempotency_key": "e7-release-..." }
```

Response 200:
```json
{ "reservation_id": "01JXXXX...", "status": "released", "released_at": "..." }
```

### 5. GET /reservations/{id}

Response 200:
```json
{
  "reservation_id": "01JXXXX...",
  "status": "reserved | consumed | released | expired | rejected",
  "origin_reference": "RWD-2026-ABC123",
  "quantity": 1,
  "items": [],
  "expires_at": "..."
}
```

Mapeo de estados internos -> publicos:

| Interno | Publico (Eq.7) |
|---|---|
| RESERVED | reserved |
| CONFIRMED | consumed |
| RELEASED (reason=EXPIRED) | expired |
| RELEASED (otro reason) | released |
| REJECTED | rejected |

## Expiracion automatica

Eq.4 corre un scheduler (`campus-eq4-scheduler`) que ejecuta `php artisan e7:expire` cada 5 minutos. Detecta reservas E7 vencidas (`origin_system = 'E7'` y `expires_at < now`), las libera (status = RELEASED, release_reason = EXPIRED), y publica un evento en `integration_outbox`:

```json
{
  "topic": "inventory.reservation.expired",
  "payload": {
    "reservation_id": "01JXXXX...",
    "origin_reference": "RWD-2026-ABC123",
    "origin_type": "REWARD",
    "expired_at": "2026-10-02T20:00:00-06:00"
  },
  "status": "PENDING"
}
```

**Pendiente**: definir con Eq.7 si quieren recibir el evento via webhook (como Eq.2) o si van a hacer polling sobre `integration_outbox`.

## Errores comunes

| Codigo | Cuando |
|---|---|
| 401 | Firma HMAC invalida |
| 404 | Reserva no encontrada o product_reference no resuelto |
| 409 | Estado invalido o sin stock |
| 422 | Payload invalido |

## Idempotencia

Misma `idempotency_key` -> mismo `reservation_id` y mismo `stock_movement_id`. Mismo mecanismo que Eq.3 (indice unico en `integration_idempotency`).

## Pendientes con Eq.7

1. Confirmar que el path `/api/v1/inventory/*` es el acordado
2. Definir webhook vs polling para el evento `inventory.reservation.expired`
3. Confirmar si usaran solo SKUs en `product_reference` (hoy soportamos SKU y ObjectId)
