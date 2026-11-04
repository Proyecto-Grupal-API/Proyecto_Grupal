# Campus Digital — Integración Equipo 7 ↔ Equipo 4
## Reporte de Incongruencias y Definición Formal del Contrato de Integración (Inventarios, Auditoría y Recompensas)

**Para:** Equipo 4 (Inventarios, Proveedores, Compras y Abastecimiento)  
**De:** Equipo 7 (Recompensas Globales, Administración, Auditoría y Analítica)  
**Fecha:** Octubre 2026  
**Estado:** Implementado en Backend de Equipo 7 y listo para pruebas  

---

### Resumen Ejecutivo

Hola, compañeros del Equipo 4. Hemos revisado detalladamente sus requerimientos, su código fuente en la rama del Módulo 4 (`App\Api\Team7ApiController`, `App\Services\Team7AdapterService`, `routes/web.php` y `docs/eq7.md`), y hemos adaptado nuestro backend en Laravel para dar soporte integral a:

1. **Auditoría Transversal (7.8) y Observabilidad (7.10):** Nuestro webhook `POST /api/v1/integrations/ingest-event` ahora acepta y procesa con firma criptográfica HMAC-SHA256 todos sus eventos de Kardex, ajustes, costos y devoluciones.
2. **Reserva de Stock para Recompensas Físicas (7.5):** Implementamos el cliente HTTP `Team4InventoryService` en nuestro controlador de canjes (`RedemptionController`), el cual consulta y reserva existencias en su API antes de descontar puntos, confirma el consumo en la entrega y libera el stock ante cancelaciones.
3. **Restitución Automática por Expiración:** Procesamos el evento `inventory.reservation.expired` para cancelar el canje y reembolsar automáticamente los puntos al estudiante.

A continuación damos respuesta formal a sus dos solicitudes.

---

## PARTE 1. Reporte de Incongruencias y Observaciones (Módulo de Inventarios)

Tras la inspección técnica de su repositorio y su mensaje, detectamos cuatro observaciones clave que deben alinearse:

### 1. Incongruencia de Rutas en la API de Reservas (Mensaje vs Código Real)
- **En su mensaje nos indicaron:** `/api/equipo4/integration/reservations`.
- **En su código real (`routes/web.php` línea 269) y su documentación (`docs/eq7.md`):** La ruta oficial protegida por `Team4ServiceSignature` es:
  ```http
  POST /api/v1/inventory/reservations
  ```
  *(Su alias `/api/equipo4/inventario` solo expone `GET /availability` y `GET /reservations/{id}`, no el POST de reservas).*
- **Resolución adoptada:** Nuestro cliente HTTP se configuró para apuntar al path oficial `/api/v1/inventory/*` (`/availability`, `/reservations`, `/reservations/{id}/confirm`, `/reservations/{id}/release`).

### 2. Paradoja en el Momento del Débito de Puntos vs Expiración de Reserva
- **Observación:** En sus notas preliminares sugerían *"debitar puntos al momento de entregar la recompensa"*, pero a la vez programaron el comando `e7:expire` para *"devolver puntos si la reserva expira a los 5 minutos"*.
- **Riesgo:** Si no se debitan puntos al reservar, el estudiante podría gastar sus mismos puntos en compras o canjes digitales concurrentes mientras el producto físico está apartado en el almacén.
- **Resolución adoptada (Flujo Contable Estándar de Lealtad):**
  1. El estudiante solicita el canje físico en Equipo 7.
  2. Equipo 7 verifica saldo y solicita la reserva en Equipo 4 (`POST /api/v1/inventory/reservations`).
  3. Si Equipo 4 responde `409 insufficient_stock` o error, **no se descuenta ningún punto** y se notifica al alumno.
  4. Si Equipo 4 responde `201 reserved`, Equipo 7 debita los puntos en el ledger y guarda el `reservation_id` en `stock_reservation_reference`.
  5. Al entregarse físicamente en módulo/ventanilla, Equipo 7 confirma la reserva (`POST /api/v1/inventory/reservations/{id}/confirm`).
  6. Si el canje se cancela o si ustedes nos notifican que la reserva expiró (`inventory.reservation.expired`), Equipo 7 libera la reserva y **reembolsa de inmediato los puntos al alumno**.

### 3. Mapeo de Identificadores (`product_reference` vs Variantes)
- **Observación:** Sus documentos hacían mención a `RewardItem.linked_product_reference`.
- **Estructura real en Equipo 7:**
  - El identificador maestro del catálogo vive en `Reward.linked_product_reference` (ej. `SOUV-TAZA-001`).
  - Las variantes físicas (tallas, colores) viven en `RewardItem.sku_reference` (ej. `SOUV-TAZA-NEG`).
- **Resolución adoptada:** Validamos que su `Team7ApiController::reserve()` soporta tanto formato flat (`product_reference`) como `variant_sku`. Nuestro cliente envía ambos datos correctamente normalizados.

### 4. Mecanismo de Notificación del Outbox (`integration_outbox` vs Webhook HTTP)
- **Observación:** Su comando `E7ExpireCommand.php` inserta eventos en la colección MongoDB `integration_outbox` mediante `OutboxService.php`. Sin embargo, `OutboxService` no posee un despachador HTTP (solo inserta en BD) y sugería que nosotros consultáramos su colección directamente.
- **Riesgo:** En una arquitectura distribuida o contenerizada, un microservicio no debe consultar la base de datos de otro equipo directamente (acoplamiento fuerte de datos).
- **Resolución:** Para formalizar esto, su cron/scheduler solo debe enviar una petición HTTP POST a nuestro endpoint de ingesta con el evento `inventory.reservation.expired` firmado con HMAC.

---

## PARTE 2. Definición del Contrato de Integración de Eventos

### 1. Endpoint Receptor (Equipo 7)
```http
POST http://localhost:8000/api/v1/integrations/ingest-event
```

### 2. Cabeceras de Seguridad y Algoritmo HMAC Requerido
Cumpliendo con el estándar que ustedes definieron en `Team4ServiceSignature.php`, el endpoint admite autenticación directa por firma HMAC:

| Cabecera | Formato / Ejemplo | Descripción |
| :--- | :--- | :--- |
| `Content-Type` | `application/json` | Obligatorio |
| `Accept` | `application/json` | Obligatorio |
| `X-Team-Timestamp` | `1790000123` | Timestamp Unix Epoch en segundos (tolerancia máxima de 300 s / 5 min). |
| `X-Team-Signature` | `3f8a9b2c...` | Firma HMAC-SHA256 en minúsculas. |

#### Algoritmo de Firma Criptográfica:
```php
$timestamp = (string) time();
$bodyHash  = hash('sha256', $request->getContent());
$path      = '/api/v1/integrations/ingest-event'; // Path absoluto sin host ni query params
$payload   = $timestamp . '|POST|' . $path . '|' . $bodyHash;

$signature = hash_hmac('sha256', $payload, $SHARED_SECRET);
```
- **Secreto compartido en desarrollo:** `<secreto acordado fuera de banda>` (variable `TEAM4_SERVICE_SECRET`).
- *(Alternativa permitida: `Authorization: Bearer <token_sanctum>` si usan cuenta de servicio).*

---

### 3. Eventos Específicos que nos Interesa Recibir

Para los módulos **7.8 Auditoría Transversal**, **7.10 Observabilidad** y **7.5 Canje de Recompensas**, los eventos aceptados son:

#### A. Movimientos de Inventario y Kardex (`inventory.movement_created`)
Se almacena en la bitácora forense inmutable de auditoría con actor, fecha, almacén y cantidades.
```json
{
  "event_id": "evt_inv_mov_9812401",
  "event_type": "inventory.movement_created",
  "source_domain": "inventory",
  "occurred_at": "2026-10-26T18:30:00Z",
  "payload": {
    "business_id": "BUS-CD-SOUV-001",
    "movement_type": "IN",
    "product_reference": "SOUV-TAZA-001",
    "variant_sku": "SOUV-TAZA-NEG",
    "quantity": 50,
    "warehouse_id": "wh_central_01",
    "cost_unit": 65.00,
    "reason": "Recepción de orden de compra OC-2026-091",
    "actor_id": "STAFF-ALMACEN-01"
  }
}
```

#### B. Ajustes y Mermas por Conteos Físicos (`inventory.adjustment_made`)
```json
{
  "event_id": "evt_inv_adj_5129301",
  "event_type": "inventory.adjustment_made",
  "source_domain": "inventory",
  "occurred_at": "2026-10-26T19:00:00Z",
  "payload": {
    "business_id": "BUS-CD-SOUV-001",
    "product_reference": "SOUV-TERMO-002",
    "quantity_adjusted": -2,
    "warehouse_id": "wh_central_01",
    "reason": "Merma por pieza dañada en anaquel",
    "actor_id": "STAFF-AUDITOR-02"
  }
}
```

#### C. Expiración de Reserva de Recompensa (`inventory.reservation.expired`)
**Acción en Equipo 7:** Busca el canje asociado, lo marca como cancelado y **restituye automáticamente los puntos al saldo del estudiante**.
```json
{
  "event_id": "evt_inv_exp_01J88A72B",
  "event_type": "inventory.reservation.expired",
  "source_domain": "inventory",
  "occurred_at": "2026-10-26T20:00:00Z",
  "payload": {
    "reservation_id": "01J88A72B100234ABC",
    "origin_reference": "CJ-A9X1-K4L2",
    "origin_type": "REWARD",
    "product_reference": "SOUV-TAZA-001",
    "reason": "EXPIRED"
  }
}
```

#### D. Otros eventos soportados:
- `inventory.stock_updated` (actualización global de existencias).
- `inventory.cost_updated` (cambios en costos unitarios).
- `inventory.return_processed` (devoluciones a proveedor o de cliente).

---

### 4. Respuestas HTTP del Webhook

- **HTTP 201 Created:** Evento procesado y auditado con éxito.
  ```json
  {
    "success": true,
    "status": "processed",
    "event_id": "evt_inv_mov_9812401",
    "event_type": "inventory.movement_created",
    "message": "Movimiento de inventario registrado y auditado exitosamente en la bitácora global (7.8)."
  }
  ```
- **HTTP 200 OK (Idempotencia):** Si envían el mismo `event_id`, no se duplican registros.
  ```json
  {
    "success": true,
    "status": "already_processed",
    "event_id": "evt_inv_mov_9812401",
    "replayed": true
  }
  ```
- **HTTP 401 Unauthorized:** Firma HMAC ausente, timestamp con desfase > 300 s o firma inválida.
- **HTTP 422 Unprocessable Entity:** Formato de carga útil inválido o campos obligatorios faltantes.

---

## PARTE 3. Coordinación de Pruebas (Local y Docker)

### Entornos y Puertos:
| Servicio | URL Local | Descripción |
| :--- | :--- | :--- |
| **Equipo 7 API** | `http://localhost:8000` | Gateway de Recompensas, Auditoría y Webhooks |
| **Equipo 7 Frontend** | `http://localhost:5173` | SPA web |
| **Equipo 4 API** | `http://localhost:8004` | API de Inventarios y Almacenes |

### Para probar desde Docker en la misma máquina anfitriona:
- Cuando el contenedor de Equipo 7 llame a Equipo 4, usará: `http://host.docker.internal:8004/api/v1/inventory/*`.
- Cuando el contenedor de Equipo 4 llame a Equipo 7, usará: `http://host.docker.internal:8000/api/v1/integrations/ingest-event`.
- Variable de entorno en `.env`:
  ```ini
  INVENTORY_DRIVER=team4
  TEAM4_BASE_URL=http://localhost:8004
  TEAM4_SERVICE_SECRET=<secreto acordado fuera de banda>
  TEAM4_BUSINESS_ID=BUS-CD-SOUV-001
  ```

---

Con esto, nuestro sistema se encuentra 100% listo para conectarse con su módulo en cuanto descongelen la rama o corran sus pruebas locales aisladas.
Quedamos a su disposición para coordinar la prueba de integración de extremo a extremo.
