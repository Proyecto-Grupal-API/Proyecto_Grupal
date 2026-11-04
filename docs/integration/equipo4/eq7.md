# Inventarios E4 ↔ Plataforma E7: contrato adaptado en MERGE_COMPLETO

Revisión: 30 de octubre de 2026. Fuente E4: `Modulo_4`, `a3ea96d702b4c5a46012be1634dd65c784426c97`.
Implementación local: `InventoryModule` y `PlatformModule`. No se sustituyen los controladores de Identidad E1.

## Estado de esta entrega

El transporte HMAC y sus receptores están incorporados y se prueban en la base exclusiva de testing.
El nuevo transporte E4→E7 y el inbox E7→E4 permanecen deshabilitados en el alterno.
La recepción no concede puntos ni confirma pagos ni compensa automáticamente un canje.
Las auditorías E6→E7 autorizadas anteriormente conservan su contrato y configuración.

## E4 → E7: notificación analítica

`POST /api/v1/integrations/ingest-event`

Cabeceras: `Content-Type: application/json`, `Accept: application/json`,
`X-Team-Timestamp` (segundos Unix) y `X-Team-Signature` (hexadecimal SHA256).

Firma sobre los bytes JSON transmitidos:

```text
HMAC-SHA256(secreto_privado, timestamp + "|POST|" + ruta + "|" + SHA256(body))
```

La ruta proviene de la URL configurada; no se firma una ruta fija distinta del destino.
El receptor admite un desfase máximo de 300 segundos y sobres de hasta 64 KiB.
No hay secreto de respaldo en el código ni se siguen redirecciones HTTP.

```json
{
  "event_id": "evt_inv_0123456789abcdef01234567",
  "event_type": "inventory.reservation.expired",
  "source_domain": "inventory",
  "occurred_at": "2026-10-30T12:00:00+00:00",
  "payload": {
    "business_id": "BUS-CD-SOUV-001",
    "reservation_id": "reservation-123",
    "origin_reference": "redemption-123",
    "expired_at": "2026-10-30T12:00:00+00:00"
  }
}
```

El ID se deriva del `_id` persistente del outbox y `occurred_at` de su `created_at` original.
Ambos permanecen iguales durante reintentos, incluso si el primer acuse se pierde.
El `business_id` se toma del evento guardado, no de un campo reemplazable del payload.

Tipos reconocidos:

- `inventory.movement_created`
- `inventory.stock_updated`
- `inventory.adjustment_made`
- `inventory.cost_updated`
- `inventory.return_processed`
- `inventory.reservation.expired`

El publicador entrega esos tipos **cuando ya existen** en `integration_outbox`.
Esta entrega no agrega productores automáticos de los primeros cinco: el productor existente es la expiración de reservas E7.

Acuses verificados por el publicador:

```json
{ "status": "accepted", "event_id": "evt_inv_0123456789abcdef01234567" }
```

202 para recepción nueva; 200 con `status: duplicate` para el mismo sobre.
Mismo ID y negocio con contenido distinto: 409. Un 2xx genérico, un recibo de otro ID,
un timeout o un rechazo no se consideran entrega confirmada.
El worker conserva el claim de 30 segundos, límite de 100 eventos y espera de cinco minutos tras fallo.

El adaptador E7 conserva el sobre completo para comprobar privacidad y huella, deriva una UUIDv5
estable por negocio/ID y reutiliza la ingesta analítica existente. Añade `source_event_id` al payload
almacenado: el emisor no puede enviar ese campo reservado. No debe enviar nombres, correos,
contraseñas, tokens ni expedientes, tampoco anidados. El evento queda `processed=false`:
el acuse acredita recepción, no una devolución de puntos.

## API E7 → E4: reservas físicas

Se conservan las rutas existentes bajo `/api/v1/inventory`, con firma de **E7**:

| Método | Ruta | Función |
|---|---|---|
| GET/POST | `/availability` | Disponibilidad por `product_reference`/SKU o lote |
| POST | `/reservations` | Reservar con `origin_reference`, `origin_type`, productos e `idempotency_key` |
| GET | `/reservations/{id}` | Consultar estado |
| POST | `/reservations/{id}/confirm` | Confirmar entrega física, sin fabricar un pago |
| POST | `/reservations/{id}/release` | Liberar reserva |

No se registran nuevamente esas mismas rutas con el controlador E3: eso reemplazaría el contrato E7.
Los requests requieren una firma HMAC válida para el consumidor y el negocio configurados.
Sin firma válida: 401; integración no configurada: 503; negocio ajeno: 403.
Las restricciones y garantías del motor de reservas existente se mantienen; esta adaptación de
transporte no acredita concurrencia multiproceso ni el circuito completo de compensación.

E3 conserva `/api/equipo4/integration/*` y las rutas con ámbito
`/api/equipo4/businesses/{businessId}/integration/*`, firmadas con su propia clave.
`availability` de E3 conserva POST y el formato `items` con identificadores de producto.
`confirm` sigue exigiendo evidencia de E2; un prefijo `MANUAL-` no crea `PAID`.

## E7 → E4: inbox de notificaciones

`POST /api/v1/integrations/eq7-events`

Firma `X-Team-*` con la clave de E7 configurada en `INVENTARIO_EQ7_SECRETO`.
El secreto del emisor E4→E7 es independiente de esta credencial de recepción.
Campos: `event_id`, `event_type` (los seis tipos anteriores), `source_domain`, `occurred_at`,
`payload` incluyendo `business_id`. `source_domain` es información declarada;
la procedencia autorizada se guarda como `source_system: E7` desde el middleware.

El inbox valida el negocio, limita tamaño y rechaza campos personales anidados.
Índice único por negocio/origen/ID y huella del sobre completo:
202 `received`, 200 `already_received` idéntico, 409 si cambia su contenido.
Se guarda **RECEIVED**, nunca PROCESSED por solo registrar un log.
No ejecuta movimientos, devoluciones ni cambios de puntos. Acordar los manejadores de
negocio con E7 antes de añadirlos: la fuente original solo escribía mensajes en la bitácora.

## Preparación para una activación posterior

No se activó esta conexión ni se provisionaron secretos nuevos en `.env`.

1. Aplicar la migración de índice `2026_10_30_000001_create_inventory_inbox_index.php`.
2. Acordar con E7 el receptor, los acuses anteriores y los manejadores/compensaciones pendientes.
3. Generar credenciales privadas propias: `INVENTARIO_E7_SECRETO` en el emisor debe corresponder
   a `PLATFORM_INVENTORY_SECRET` en E7; `INVENTARIO_EQ7_SECRETO` es la clave independiente de E7→E4.
4. Configurar `TEAM7_WEBHOOK_URL` con el endpoint completo; dentro del alterno sería
   `http://app/api/v1/integrations/ingest-event`, no localhost de otro contenedor.
5. Habilitar por separado `INVENTARIO_E7_OUTBOX_HABILITADO` y, si se requiere recibir notificaciones,
   `INVENTARIO_E7_INBOX_HABILITADO`, limpiar configuración y verificar con datos controlados.

`PLATFORM_INGEST_ENABLED` es el interruptor general del receptor E7. Ya existe para E6;
no se cambió su valor. El nuevo receptor de inventario requiere además su propio secreto.
`INVENTARIO_E7_TOKEN` deja de utilizarse: este contrato emplea HMAC, no Bearer.
PHPUnit fuerza ambos interruptores nuevos a false y las pruebas los configuran explícitamente
solo dentro de la aplicación de testing.

## Pendientes funcionales

- Emisión automática de todos los tipos declarados en el contrato.
- Recuperación de una expiración cuya liberación de stock termine antes de guardar el outbox;
  la fuente existente no hace ambas acciones atómicas. No se activa el transporte como circuito completo.
- Vincular canje E7 ↔ reserva E4 con recuperación, concurrencia y compensación de puntos verificadas.
- Confirmar con E7 los acuses y el vocabulario de las notificaciones de retorno.
- Cierre financiero E2/E3/E4: autoridad, contraparte, consentimiento y evidencias de pago reales.
