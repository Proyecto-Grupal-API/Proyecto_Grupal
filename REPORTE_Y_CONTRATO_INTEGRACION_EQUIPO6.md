# Reporte y Contrato de Integración — Equipo 7 ↔ Equipo 6

**Asunto:** Resolución a observaciones de integración E6 (Comunidad) ↔ E7 (Recompensas / Auditoría)  
**Fecha:** Noviembre 2026  
**De:** Equipo 7 — Recompensas, Puntos, Gobernanza y Observabilidad  
**Para:** Equipo 6 — Comunidad, Organizaciones Estudiantiles y Actividades  

---

## 1. Acuse de recibo y contexto

Hola, Equipo 6.

Agradecemos su mensaje y la evaluación que realizaron sobre el commit `e48031e`. Celebramos que las tres pruebas iniciales de recepción hayan aprobado exitosamente.

Respecto a las observaciones planteadas sobre los casos de Comunidad:
1. **Diferenciación de reintento vs. conflicto de contenido (código 409).**
2. **Preservación estricta de `source_domain = 'community'` en la bitácora transversal (evitando que caiga en `'services'`).**
3. **Mecanismo de autenticación independiente de Sanctum y de los clientes OAuth de Equipo 1.**

Confirmamos que **los tres puntos han sido completamente resueltos e implementados en nuestro código**, tanto en los endpoints de plataforma (`/api/v1/plataforma/*`) como en el receptor unificado (`/api/v1/integrations/ingest-event`).

A continuación detallamos las especificaciones técnicas y el contrato acordado.

---

## 2. Resoluciones Técnicas Implementadas

### 2.1 Conflicto de contenido: Código 409 vs. Código 200

**Comportamiento acordado e implementado:**
Para cada evento recibido, calculamos un hash criptográfico SHA-256 (`envelope_hash`) del sobre completo con claves ordenadas. Al recibir una petición con un `event_id` ya existente:

- **Reintento idéntico** (mismo contenido, mismo hash):  
  El receptor responde **`HTTP 200 OK`** con `{"estado": "duplicado", "event_id": "..."}`, confirmando la recepción sin duplicar el registro en la base de datos (idempotencia perfecta).
- **Contenido alterado** (mismo `event_id`, pero payload, aggregate o datos diferentes):  
  El receptor responde **`HTTP 409 Conflict`** con:
  ```json
  {
      "codigo": "conflicto_contenido",
      "error": "idempotency_conflict",
      "message": "El identificador ya existe con otro contenido.",
      "event_id": "<uuid>"
  }
  ```
  **Garantía:** Nunca se sobrescribe ni se altera un registro previamente auditado.

---

### 2.2 Origen de auditoría: `source_domain = 'community'`

**Problema anterior:** En versiones preliminares, `source_domain` se tomaba del cuerpo o se asignaba por defecto a `'services'`.  
**Solución implementada:**  
El campo `source_domain` en `audit_logs`, `analytics_events` e `integration_logs` se deriva **estrictamente de la identidad autenticada por la firma de la petición** (`request->attributes->get('platform_source')`), **ignorando cualquier valor que venga en el JSON**:

- Si la petición proviene de su emisor autenticado con dominio `community`, el registro se almacena de forma inmutable como:
  ```json
  {
      "source_domain": "community",
      "action": "community.organization.created",
      "entity_type": "organization"
  }
  ```
- No hay posibilidad de que quede registrado como `'services'` ni como ningún otro dominio.

---

### 2.3 Autenticación de Servicio y Aislamiento Modular

**Duda planteada por E6:** Las rutas iniciales requerían sesión Sanctum y permisos locales, y no se había validado compatibilidad con los tokens OAuth de E1.

**Resolución en E7:**  
**Este receptor NO utiliza Laravel Sanctum ni depende de los clientes OAuth de Equipo 1.**  
Implementamos autenticación Service-to-Service mediante **firma criptográfica HMAC-SHA256 con secreto compartido por dominio**:

1. No requiere base de datos de usuarios compartida.
2. No requiere solicitar tokens a Equipo 1.
3. Se aísla por completo el dominio de Comunidad de los permisos de usuarios locales.

#### Cabeceras requeridas para `/api/v1/plataforma/eventos` y `/api/v1/plataforma/auditoria`:

| Cabecera | Valor esperado |
|---|---|
| `X-Platform-Domain` | `community` |
| `X-Platform-Timestamp` | Unix timestamp de 10 dígitos (tolerancia ±300 segundos) |
| `X-Platform-Signature` | `hash_hmac('sha256', "{$ts}|POST|/api/v1/plataforma/...|{sha256(json_body)}", PLATFORM_COMMUNITY_SECRET)` |

*También es compatible con cabeceras `X-Team-*` si utilizan el endpoint `/api/v1/integrations/ingest-event`.*

---

## 3. Endpoints Disponibles y Contrato

### 3.1 Ingesta de Eventos de Comunidad

**Ruta:** `POST /api/v1/plataforma/eventos`  
*(o alias `/api/v1/integrations/ingest-event`)*

```json
{
  "event_id": "c1f72d4e-89a1-4321-9988-abcdef012345",
  "event_name": "community.activity.started.v1",
  "source_domain": "community",
  "aggregate_id": "act-torneo-ajedrez-2026",
  "occurred_at": "2026-11-04T22:00:00-06:00",
  "payload": {
    "activity_id": "act-torneo-ajedrez-2026",
    "organization_id": "org-club-ajedrez",
    "capacity": 32,
    "registered_count": 28
  }
}
```

### 3.2 Registro de Auditoría Sensible de Comunidad

**Ruta:** `POST /api/v1/plataforma/auditoria`

```json
{
  "event_id": "e2a1b3c4-1234-5678-9abc-def012345678",
  "actor_id": "usr-estudiante-presidencia-01",
  "actor_role": "admin_comunidad",
  "action": "community.organization.election_closed",
  "entity_type": "organization",
  "entity_id": "org-sociedad-alumnos-sistemas",
  "correlation_id": "corr-elec-2026-sistemas",
  "occurred_at": "2026-11-04T22:05:00-06:00",
  "after": {
    "status": "closed",
    "total_votes": 215,
    "elected_board_id": "board-2026-2027"
  }
}
```

> **Regla de Privacidad:** No enviar datos personales directos (`name`, `email`, `telefono`, etc.) en el payload; el receptor valida y rechaza con `422` si se detectan estos campos para proteger el principio de minimización de datos.

---

## 4. Tabla de Códigos de Respuesta

| Código HTTP | Significado | Comportamiento del emisor de E6 |
|---|---|---|
| **`202 Accepted`** | Evento nuevo recibido y auditado | Marcar como publicado / enviado exitosamente |
| **`200 OK`** | Evento duplicado idéntico (idempotente) | Confirmar entrega sin reintentar |
| **`409 Conflict`** | Mismo `event_id` con contenido diferente | Registrar error de integridad; no reintentar automáticamente |
| **`401 Unauthorized`** | Firma HMAC inválida, timestamp desfasado (>300s) o secreto erróneo | Revisar secreto y sincronización de reloj |
| **`422 Unprocessable`** | Esquema inválido o presencia de campos personales (PII) | Corregir estructura del payload |

---

## 5. Parámetros para Pruebas Locales

Para pruebas en entorno local o Docker:

```ini
# En el archivo .env de ambos equipos:
PLATFORM_INGEST_ENABLED=true
PLATFORM_COMMUNITY_SECRET=<secreto acordado fuera de banda>
```

- **URL base de E7:** `http://localhost:8000`
- **Ruta de eventos:** `POST /api/v1/plataforma/eventos`
- **Ruta de auditoría:** `POST /api/v1/plataforma/auditoria`

---

## 6. Archivos y Pruebas en el Repositorio de E7

- [`PlatformIngestController.php`](backend_respaldo/app/Http/Controllers/Api/PlatformIngestController.php): Controlador de plataforma con cálculo de `envelope_hash` y protección contra falso dominio.
- [`AuthenticatePlatformSource.php`](backend_respaldo/app/Http/Middleware/AuthenticatePlatformSource.php): Middleware HMAC independiente de Sanctum y OAuth.
- [`config/platform.php`](backend_respaldo/config/platform.php): Configuración de secretos por dominio.
- [`Team6IntegrationApiTest.php`](backend_respaldo/tests/Feature/Team6IntegrationApiTest.php): 5 pruebas automatizadas que verifican el 409 de conflicto, el origen `community` inmutable y la idempotencia.

Quedamos totalmente listos para realizar una prueba conjunta en cuanto tengan listo su emisor.

Saludos cordiales,  
**Equipo 7 — Campus Digital**

