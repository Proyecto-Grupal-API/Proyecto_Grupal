# Reporte y Contrato de Integración — Equipo 7 ↔ Equipo 1

**Asunto:** Confirmación técnica y contrato de integración E1 ↔ E7 verificado contra codebase de Equipo 1  
**Fecha:** 2026-11-04  
**De:** Equipo 7 — Recompensas, Puntos y Bitácora Global  
**Para:** Equipo 1 — Identidad, Acceso, NFC/QR y Perfil del Estudiante

---

## 1. Inspección técnica realizada

Hola, Equipo 1.

Revisamos a fondo el repositorio de su proyecto (`app/Console/Commands/PublishDomainEvents.php`, `config/events.php`, `app/Services/OutboxDelivery.php` y su especificación `docs/integration/TEAM-7.md`).

A partir de su implementación real de Outbox y su comando `events:publish`, **adaptamos nuestro receptor para que sea 100% compatible con su código existente sin requerir ninguna modificación por su parte.**

---

## 2. Acuerdos técnicos implementados y verificados

### 2.1 Compatibilidad de nombres de eventos y estructura del sobre

Inspeccionamos su `PublishDomainEvents.php`:
```php
$response = $request->post($sink, [
    'event_id'     => $event->event_id,
    'event_name'   => $event->event_name,
    'aggregate_id' => $event->aggregate_id,
    'occurred_at'  => $event->occurred_at?->toDateTime()->format('Y-m-d\TH:i:s.u\Z'),
    'payload'      => $event->payload?->getArrayCopy() ?? [],
]);
```

**Resolución en E7:**
- Nuestro endpoint acepta indistintamente **`event_name`** (como lo envía su Outbox) y `event_type`.
- Acepta **`aggregate_id`** y lo correlaciona con la entidad correspondiente.
- Mapea de forma transparente los eventos históricos con prefijo `student.` (`student.profile.changed.v1`, `student.consent.changed.v1`) hacia `source_domain = 'identity'`, sin exigir que comiencen con el prefijo de dominio.

| `event_name` de E1 | `source_domain` en E7 | Acción en `audit_logs` |
|---|---|---|
| `student.profile.changed.v1` | `identity` | `identity.student.profile.changed` |
| `student.consent.changed.v1` | `identity` | `identity.student.consent.changed` |
| `identity.credential.changed.v1` | `identity` | `identity.credential.changed` (marcado sensible) |

---

### 2.2 Autenticación — Soporte nativo para `EVENTS_SINK_TOKEN`

En su código (`PublishDomainEvents.php` y `config/events.php`), observamos que su publicador envía:
```php
if ($token = config('events.sink_token')) {
    $request = $request->withToken($token);
}
```
Esto genera la cabecera HTTP:
`Authorization: Bearer <EVENTS_SINK_TOKEN>`

**Resolución en E7:**
Nuestro middleware `EnsureIntegrationAuthorized` ahora soporta verificación directa contra el token estático configurado (`services.team1.service_token` / `TEAM1_SERVICE_TOKEN`), además de HMAC y Sanctum.

**Configuración lista en Equipo 1 (`.env`):**
Basta con configurar en su `.env`:
```ini
EVENTS_SINK_URL=http://localhost:8000/api/v1/integration/events
EVENTS_SINK_TOKEN=<secreto acordado fuera de banda>
```
*(o el puerto donde corra el backend de E7 en su red).*

---

### 2.3 Endpoint y URL habilitadas

En su documento `docs/integration/TEAM-7.md` mencionan:
> `"/api/v1/integration/events es el receiver propuesto y todavía pendiente de implementación/especificación final de Team 7: EXTERNAL_TEAM_DEPENDENCY, no una ruta de Team 1."`

**Resolución en E7:**
Habilitamos en `routes/api.php` las siguientes rutas coincidentes con el mismo middleware:
- **`POST /api/v1/integration/events`** (la ruta exacta que ustedes documentaron)
- **`POST /api/v1/integrations/ingest-event`** (nuestra ruta unificada estándar)
- **`POST /api/v1/integrations/events`** (alias adicional)

Cualquiera de ellas es válida para recibir sus publicaciones.

---

### 2.4 Idempotencia y ciclo de vida de Outbox

En su comando `PublishDomainEvents.php`:
```php
if ($response->successful()) {
    $delivery->acknowledge($event, $claimToken); // Marca published_at = now()
} else {
    $status = $response->status();
    $retryable = $status === 429 || $status >= 500;
    $delivery->fail($event, $claimToken, 'http_'.$status, $retryable);
}
```

**Comportamiento garantizado por E7:**
1. **Nuevo evento:** Retorna **`HTTP 202 Accepted`** (`$response->successful() = true`), lo cual activa `$delivery->acknowledge()` en su Outbox y marca el evento como publicado.
2. **Reintento con mismo `event_id` (duplicado):** Retorna **`HTTP 200 OK`** (`$response->successful() = true`), confirmando la entrega sin generar duplicados en nuestra base de datos.
3. **Mismo `event_id` pero diferente `event_name` (conflicto):** Retorna **`HTTP 200 OK`** para no bloquear su Outbox, y registra una advertencia en nuestra auditoría interna (`action = integration.event_id_conflict`).
4. **Fallo transitorio interno en E7:** Retorna **`HTTP 202 Accepted`** y almacena el evento en nuestra cola de pendientes (`webhook_events`). De este modo, **una falla interna de E7 nunca interrumpe la operación de identidad de E1 ni produce errores 5xx hacia su publicador.**

---

### 2.5 Privacidad y sanitización de PII

Confirmamos lo indicado en su `TEAM-7.md`:
> `"No se incluyen UID, nombres, correos, tokens ni datos personales adicionales... los operadores deben evitar incorporar información sensible en él."`

Aun así, nuestro receptor aplica una capa preventiva de sanitización (`sanitizeIdentityPayload`):
- Se filtran campos como `email`, `phone`, `full_name`, `address`, `password`.
- Para credenciales (`identity.credential.changed.v1`), se omiten `nfc_uid`, `pin`, `otp`, `biometric_hash` en caso de que vinieran en el payload.
- Se preservan intactos los identificadores de trazabilidad (`student_id`, `credential_id`, `actor_id`, `old_status`, `new_status`, `reason`).

---

## 3. Ejemplo de prueba end-to-end con `php artisan events:publish`

Una vez configuradas las variables en el `.env` de Equipo 1:
```ini
EVENTS_SINK_URL=http://localhost:8000/api/v1/integration/events
EVENTS_SINK_TOKEN=<secreto acordado fuera de banda>
```

Al ejecutar desde el directorio de Equipo 1:
```bash
php artisan events:publish --limit=10
```

El flujo será:
1. `PublishDomainEvents` reclama los eventos pendientes del Outbox en MongoDB.
2. Realiza el POST HTTP con el Bearer token `<secreto acordado fuera de banda>`.
3. El receptor de E7 valida el token, procesa el evento y responde `202 Accepted`.
4. El Outbox de E1 ejecuta `$delivery->acknowledge()` marcando `published_at`.
5. Salida en consola de E1: `Published: X; failed: 0; inspected: X.`

---

## 4. Resumen de archivos actualizados en Equipo 7

| Archivo | Ajuste realizado |
|---|---|
| `app/Http/Controllers/Api/IntegrationController.php` | Soporte dual `event_name` / `event_type`, soporte `aggregate_id`, sanitización de PII, respuestas 202/200 para compatibilidad con Outbox |
| `app/Http/Middleware/EnsureIntegrationAuthorized.php` | Validación directa de Bearer token estático (`EVENTS_SINK_TOKEN`) + HMAC + Sanctum |
| `routes/api.php` | Rutas `/api/v1/integration/events` y `/api/v1/integrations/ingest-event` |
| `config/services.php` | Configuración `team1` con secretos por defecto |
| `tests/Feature/Team1IntegrationApiTest.php` | 7 pruebas unitarias y de integración que validan el sobre de E1, la autenticación por token y la idempotencia |

Quedamos a su disposición para realizar una prueba conjunta en entorno local o contenedor Docker cuando lo indiquen.

Saludos cordiales,  
**Equipo 7 — Recompensas, Puntos y Bitácora Global**
