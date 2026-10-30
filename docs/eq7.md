# Integración con Equipo 7 – Inventario, Recompensas y Auditoría

**Versión:** 1.0 (Formalizado tras auditoría del 29 de Octubre)

**Rol de Eq.4:** Proveedor de Inventario (Receptor de reservas y emisor de eventos de auditoría).

## 1. Contrato de Webhooks (Eq 4 → Eq 7)

Equipo 4 envía eventos de inventario al Equipo 7 para su bitácora y analítica.

*   **Endpoint receptor:** `POST http://localhost:8000/api/v1/integrations/ingest-event`
*   **Cabeceras requeridas:**
    *   `Content-Type: application/json`
    *   `Accept: application/json`
    *   `X-Team-Timestamp: <timestamp_unix_en_segundos>`
    *   `X-Team-Signature: <firma_hmac_sha256_hex_lowercase>`
*   **Algoritmo de firma:**
    *   `payload = "{timestamp}|POST|/api/v1/integrations/ingest-event|{sha256(body)}"`
    *   `signature = hash_hmac('sha256', payload, 'secreto_equipo4_eq3_2026')`

### Eventos Soportados
1.  `inventory.movement_created` (Kardex: entradas, salidas, mermas, transferencias)
2.  `inventory.stock_updated` (Actualización de niveles de stock)
3.  `inventory.adjustment_made` (Ajustes por conteos físicos)
4.  `inventory.cost_updated` (Actualizaciones de costo promedio)
5.  `inventory.return_processed` (Devoluciones a proveedor o cliente)
6.  `inventory.reservation.expired` (Expiración de reservas de canjes)

### Formato de Payload (Ejemplo: Movimiento de Kardex)
```json
{
  "event_id": "evt_inv_9812401",
  "event_type": "inventory.movement_created",
  "source_domain": "inventory",
  "occurred_at": "2026-10-26T18:30:00Z",
  "payload": {
    "business_id": "BUS-CD-SOUV-001",
    "movement_type": "IN",
    "product_reference": "SOUV-TAZA-001",
    "variant_sku": null,
    "quantity": 50,
    "warehouse_id": "wh_central_01",
    "cost_unit": 65.00,
    "reason": "Recepción de orden de compra",
    "actor_id": "STAFF-01"
  }
}
```

### Formato de Payload (Ejemplo: Reserva Expirada)
```json
{
  "event_id": "evt_inv_exp_01J88A",
  "event_type": "inventory.reservation.expired",
  "source_domain": "inventory",
  "occurred_at": "2026-10-26T20:00:00Z",
  "payload": {
    "reservation_id": "01JXXXX...",
    "origin_reference": "CJ-A9X1-K4L2",
    "origin_type": "REWARD",
    "product_reference": "SOUV-TAZA-001",
    "reason": "EXPIRED"
  }
}
```

## 2. API de Reservas (Eq 7 → Eq 4)

Equipo 7 consume nuestra API para reservar stock cuando un alumno canjea puntos.

*   **Ruta oficial:** `POST /api/v1/inventory/reservations`
*   **Rutas de soporte:**
    *   `GET /api/v1/inventory/availability`
    *   `POST /api/v1/inventory/reservations/{id}/confirm`
    *   `POST /api/v1/inventory/reservations/{id}/release`
*   **Nota:** El alias `/api/equipo4/inventario` solo expone GET (para testers) y no debe usarse para el flujo real.

### Lógica de Negocio (Acordada)
1.  **Solicitud de canje:** Eq 7 solicita reserva (`POST /reservations`).
2.  **Respuesta 409:** Si no hay stock, Eq 7 no debita puntos.
3.  **Respuesta 201:** Si hay stock, Eq 7 debita puntos y guarda el `reservation_id`.
4.  **Confirmación:** Al entregar físicamente, Eq 7 llama a `/confirm`.
5.  **Expiración:** Si la reserva expira, Eq 4 libera el stock y emite `inventory.reservation.expired` para que Eq 7 reembolse los puntos.

## 3. Implementación en Eq 4

*   **`HttpEventDispatcher`:** Servicio que construye el payload y envía la petición HTTP con la firma HMAC exacta al endpoint de Eq 7.
*   **`OutboxService::dispatchPending()`:** Método que lee los eventos pendientes de `integration_outbox` y los envía por red.
*   **`E7ExpireCommand`:** Comando que, al expirar una reserva, publica el evento en el outbox y dispara el `dispatchPending()` para notificar a Eq 7 de inmediato.