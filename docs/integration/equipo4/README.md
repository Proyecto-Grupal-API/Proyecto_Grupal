# Documentacion de Integraciones - Equipo 4

**Modulo**: Inventarios / Compras / Abastecimiento
**Business ID**: `BUS-CD-SOUV-001`
**Stack**: Laravel 11 + MongoDB
**Base URL (dev)**: `http://localhost:8004`

## Indice de documentos

| Documento | Equipo | Rol de Eq.4 |
|---|---|---|
| [eq1.md](./eq1.md) | Equipo 1 - Identidad | **Cliente** (consumimos su API) |
| [eq2.md](./eq2.md) | Equipo 2 - Wallet / Pagos | **Servidor** (exponemos webhook) |
| [eq3.md](./eq3.md) | Equipo 3 - Marketplace | **Servidor** (exponemos API) |
| [eq7.md](./eq7.md) | Equipo 7 - Recompensas | **Servidor** (exponemos API) |

## Firma HMAC compartida

```
payload   = "{timestamp}|{method}|{path}|{sha256(body)}"
signature = hash_hmac('sha256', payload, SHARED_SECRET)
```

Donde:
- timestamp = Unix epoch en segundos
- method    = GET | POST | PUT | PATCH | DELETE (uppercase)
- path      = ruta completa empezando con / (SIN query string)
- body      = string crudo del JSON (vacio si no hay body)

**Headers requeridos:**
- `X-Team-Timestamp: 1790000123`
- `X-Team-Signature: <hmac-hex-lowercase>`
- `Accept: application/json`
- `Content-Type: application/json`

**Tolerancia de reloj:** 300 segundos (+/-5 min)

## Respuestas de error comunes

| Codigo | Significado |
|---|---|
| 401 | Firma invalida o timestamp fuera de rango |
| 403 | Recurso prohibido o business_id mismatch |
| 404 | Recurso no encontrado |
| 409 | Conflicto de estado |
| 422 | Payload invalido |
| 500 | Error interno |

## Secretos por entorno

| Cliente | Variable .env | Default dev |
|---|---|---|
| Eq.1 (Identidad) | `TEAM1_SERVICE_SECRET` | (vacio - stub) |
| Eq.2/3/7 (compartido) | `TEAM_SERVICE_SHARED_SECRET` | `secreto_equipo4_eq3_2026` |

## Convenciones generales

- Fechas en ISO 8601 con timezone
- IDs de reserva son ULID (26 caracteres)
- Todos los `business_id` deben coincidir con `BUS-CD-SOUV-001`
- Endpoints de integracion NO usan CSRF
- Endpoints de integracion NO usan cookies (service-to-service)

## Resiliencia (Team1Client)

El cliente HTTP hacia Eq.1 reintenta con backoff **500ms -> 1500ms** en:
- Respuestas 5xx
- Errores de red / timeout

Los 4xx (excepto 5xx) NO se reintentan.

## Colecciones MongoDB relevantes

| Coleccion | Uso |
|---|---|
| `integration_idempotency` | Idempotencia (indice unico service + idempotency_key) |
| `payment_events` | Webhooks de Eq.2 |
| `team3_api_reservations` | Reservas creadas por Eq.3 y Eq.7 |
| `stock_reservations` | Reservas creadas manualmente (panel admin) |
| `cost_histories` | Historial de costos por producto |
| `stock_movements` | Kardex |
| `integration_outbox` | Eventos pendientes de entrega (E7 expire) |
