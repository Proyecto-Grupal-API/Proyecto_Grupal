# Team 1 — Identity Integration Contract

Esta guía describe el **working tree actual** del Equipo 1 para integrarlo con ramas que ya contienen trabajo propio. No es una instrucción de instalación limpia ni de merge automático. Las guías específicas están en este directorio.

## Estado del snapshot

- Baseline verificado antes de esta documentación: 234 tests, 1449 assertions, 0 failures; build y comprobación de diff correctos.
- Disponibles: cuentas y perfiles estudiantiles (1.1); autenticación/2FA y roles contextuales; registro NFC (1.4); transiciones NFC ordinarias y pérdida; estado académico OAuth; validación QR OAuth y web con expiración, revocación, código corto y consumo único; eventos versionados publicados mediante outbox.
- Parciales: ciclo NFC (1.5: falta reemplazo real) e identidad QR (1.6: quedan fases de secretos legacy y reglas operativas). No declarar esos requisitos terminados.
- Pendientes para consumidores: API de validación NFC, reemplazo NFC old→new, QR-B.4B/retirada final de plaintext legacy, uso autorizativo de `purpose`, elegibilidad académica integrada en QR y contextos operativos QR. No hay fecha comprometida.

## Regla de identificadores

El identificador externo de estudiante es **`User._id`**. En los contratos OAuth académicos, `{studentId}`, `data.student_id` y `data.user_id` (cuando existe) son ese mismo ID. En `student.profile.changed.v1` y `student.consent.changed.v1`, `payload.student_id` también es `User._id`. `StudentProfile._id` se usa internamente para relaciones e historial; no enviarlo como `{studentId}` a la API OAuth. La matrícula es un atributo, no el identificador de ruta.

La API Sanctum de consentimientos/preferencias conserva una búsqueda histórica que acepta tanto `User._id` como `StudentProfile._id` y puede devolver el segundo como `student_id`. **Esa ambigüedad no define el contrato interequipos**; no extrapolarla a OAuth ni basar integraciones nuevas en ella.

## OAuth service authentication

`POST /api/oauth/token` acepta `grant_type=client_credentials`, `client_id`, `client_secret` y `scope` (cadena de scopes separados por espacios). Devuelve `access_token`, `token_type=Bearer`, `expires_in` y `scope`. Los scopes solicitados deben estar asignados al cliente; una solicitud con alguno no permitido devuelve `400 invalid_scope`. Credenciales inválidas devuelven 401. Un token sin el scope exigido por la ruta recibe 403; sin Bearer válido, 401.

Scopes de servicio actualmente usados por las rutas públicas: **`students:read`** e **`identity:qr:validate`**. Son nombres literales; no existe alias con puntos. No compartir ni registrar secretos de cliente. Este OAuth de servicios es independiente del login web, de Sanctum y de los roles embebidos de usuario.

## Estado académico

| Método y ruta | Autenticación | ID de entrada | Respuesta |
| --- | --- | --- | --- |
| `GET /api/v1/students/{studentId}/status` | OAuth Bearer, `students:read` | `User._id` | `data` con proyección académica; `meta.request_id`, `meta.api_version=v1` |
| `GET /api/v1/students/{studentId}/status/history` | OAuth Bearer, `students:read` | `User._id` | `data.student_id=User._id`, `data.items[]`; mismo `meta` |

`status.data` contiene exactamente `student_id`, `user_id`, `name`, `enrollment`, `program`, `semester`, `campus`, `status`, `status_label`, `effective_from`, `status_reason`, `restrictions`, `benefits_eligible`. Los dos últimos son **null**, no decisiones de elegibilidad. El historial contiene `from_status`, `status`, `reason`, `actor_id`, `effective_from`, `recorded_at`; no expone `student_profile_id`. Un `User._id` sin perfil no se resuelve (404); `StudentProfile._id` tampoco sustituye al ID externo.

Estados académicos: `active`, `inactive`, `suspended`, `restricted`, `leave`, `graduated`. **Estado académico ≠ estado de credencial NFC.**

## QR validation

`POST /api/v1/identity/qr-validate` exige OAuth Bearer con **`identity:qr:validate`** y aplica límite de 30 solicitudes/minuto. JSON de entrada: `code` (string obligatorio, máximo 120) y `context` (string opcional, máximo 150). `code` puede ser payload QR, código completo o código corto dinámico. `context` es una etiqueta de auditoría, **no** autorización operativa.

Respuesta pública: `{ "ok": boolean, "result": string, "identity": object|null }`. `200` si `result=valid`; `422` para resultados de validación no válidos (`invalid_signature`, `not_found`, `expired`, `consumed`, `revoked`); 401 sin token y 403 sin scope. Un QR dinámico válido se consume una sola vez; el de identificación es reutilizable mientras siga vigente. La identidad del validador de servicio deriva del `client_id` autenticado, no de un campo enviado en el body.

En éxito, `identity` procede de `User::displayIdentity()` y contiene sólo `user_id`, `name` y `student`. `student` es null si no hay perfil; si lo hay, contiene `enrollment_number`, `campus` (`code`, `name` o null), `academic_program` (`code`, `name` o null) y `academic_status`. Un QR válido **no concede automáticamente un beneficio, servicio ni acceso**. El consumidor aplica sus propias reglas de negocio.

No son contrato público: `code_hash`, `short_code_hash`, `code_encrypted`, `short_code_claimed`, `QrLookupHash`, `QrLegacySecretBackfill`, `IssuedQrToken`, `qr_tokens` ni su esquema.

La web autenticada ofrece `/identidad/qr` para mostrar/generar QR e historial. La validación ajena del simulador web requiere rol global `admin` por Policy; esa regla web no reemplaza la autorización OAuth de servicios.

## NFC

Disponible **sólo como flujo web**, bajo `/nfc-cards`: registro por admin global para un usuario con `StudentProfile`, UID canónico (trim + uppercase), historial inicial, bloqueo, reporte explícito de pérdida, suspensión y reactivación. `active`, `blocked`, `suspended`, `replaced` son los estados persistidos; `replaced` es terminal y el endpoint genérico no lo crea. El dueño puede ver sus tarjetas/historial; admin puede administrar. Los cambios persisten tarjeta, historial y outbox en una transacción MongoDB.

**Pendiente:** reemplazo real old→new y API interequipos para validar UID/propietario/estado. No hay ruta `/api/v1/.../nfc-validate` ni equivalente. No derivar la titularidad actual a partir de eventos ni consultar `nfc_cards` desde otro equipo.

## Roles y contextos

Catálogo: `admin`, `maestro`, `estudiante`, `servicio_cafeteria`, `consejo_estudiantil`, `student_manager`. `User::assignRole()`/`hasRole()` admiten asignación global (`scope_type=null`, `scope_id=null`) o contextual con ambos valores. Tipos válidos: `business`, `association`, `service`, `council`. **No existe contexto `campus`**. Las rutas administrativas actuales evalúan autorización en backend; la UI no es la autoridad.

No hay API OAuth pública para consultar/asignar roles contextuales. La representación `roles` embebida en `users` es interna; solicitar a Team 1 el contrato necesario, no copiar/escribir esa estructura.

## Consentimientos y preferencias

`/api/v1/students/{studentId}/consents` y `/preferences` son rutas `auth:sanctum`; las rutas `/student-services` usan sesión web. **No** están protegidas por OAuth de servicios ni son un contrato OAuth interequipos. No asumir acceso con `students:read` ni con `identity:qr:validate`. La compatibilidad dual de IDs de estas rutas es deuda separada, no una excepción a la regla OAuth.

## Eventos publicados

`StoreDomainEvent` almacena los eventos en `event_outbox`; `events:publish` entrega al sink configurado un sobre con `event_id`, `event_name`, `aggregate_id`, `occurred_at`, `payload`. El consumidor recibe eventos **publicados** por el transporte acordado; no consulta `event_outbox` directamente. Los payloads actuales son:

| Evento | Productor/cuándo | Payload | ID del sujeto / límite |
| --- | --- | --- | --- |
| `student.profile.changed.v1` | Alta/edición de perfil, cambio académico y preferencias | `student_id`, `operation`, `changed_fields`, `actor_id` | `student_id=User._id`; `operation` actual: `created`, `updated`, `academic_status_changed` o `communication_preferences_changed`; no contiene perfil completo ni valor nuevo de estado |
| `student.consent.changed.v1` | Aceptación/revocación de consentimiento | `student_id`, `consent_id`, `status`, `version`, `actor_id` | `student_id=User._id`; no es copia del registro de consentimiento |
| `identity.credential.changed.v1` | Registro NFC y cambio ordinario/pérdida | `credential_id`, `credential_type`, `operation`, `status`, `actor_id` | `credential_id=NfcCard._id` interno de la credencial, **no** `User._id`; `credential_type=nfc`, `operation` actual: `registered`, `status_changed` o `lost`; no contiene UID ni titular actual |

`CredentialEvent`, `QrValidation` y `SecurityEvent` son historial/auditoría, **no** eventos de integración. El consumidor debe tolerar campos adicionales futuros, deduplicar por `event_id` y no inferir orden global. Solicitar contrato de consulta si el payload no basta.

## DO NOT DEPEND DIRECTLY ON TEAM 1 STORAGE

No consultar ni escribir directamente `users`, `student_profiles`, `academic_status_history`, `nfc_cards`, `credential_events`, `qr_tokens`, `qr_validations`, `security_events`, roles embebidos ni `event_outbox` como workaround de integración. Son persistencia de Team 1; sus índices, documentos y procesos de backfill pueden evolucionar sin ser API pública. Si falta una operación (por ejemplo, validación NFC o roles contextuales para servicios), **registrar la dependencia con Team 1 y detener esa parte**.

## Integración con trabajo existente

Cada equipo debe comparar su rama y el snapshot antes de modificar. Preservar la semántica de ambos dominios; no reemplazar `User.php`, rutas, providers ni seeders completos y no resolver conflictos globalmente con “ours/theirs”. Usar [AI-INTEGRATION-PROMPT.md](AI-INTEGRATION-PROMPT.md) para preparar una auditoría y plan; integrar sólo después de revisión humana.
