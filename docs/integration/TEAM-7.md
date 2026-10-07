# Equipo 7 — Eventos de identidad

Auditoría INT-1B.6: en la referencia pública `7a30c3f12722a4e2c6d4caef870f90309a11ae62` existen QR OAuth y estado/historial académico; NFC OAuth es **IMPLEMENTED_NOT_YET_PUBLISHED**. Team 7 conserva íntegramente su elegibilidad y permisos locales de rewards/audit/analytics; no se incorporan al catálogo E1.

Esta guía describe el sobre y el publicador **implementados localmente por Equipo 1**, no una entrega externa ya acordada. Antes del consumo real faltan URL, autenticación, semántica de 409, ejecución del scheduler en deployment y prueba contra el sink de Equipo 7. **No** consulte `event_outboxes` directamente. Véase [contrato principal](TEAM-1-INTEGRATION.md).

Sobre publicado: `event_id`, `event_name`, `aggregate_id`, `occurred_at`, `payload`. Versiones existentes:

| event_name | payload actual | Semántica |
| --- | --- | --- |
| `student.profile.changed.v1` | `student_id`, `operation`, `changed_fields`, `actor_id` | `student_id=User._id`; aviso de cambio, no snapshot |
| `student.consent.changed.v1` | `student_id`, `consent_id`, `status`, `version`, `actor_id` | `student_id=User._id`; no incluye documento de consentimiento |
| `identity.credential.changed.v1` | `credential_id`, `credential_type`, `operation`, `status`, `actor_id` | NFC actual; `credential_id=NfcCard._id`, no ID del estudiante ni UID |

El productor local entrega **at-least-once** mediante un POST JSON por evento. Sólo un HTTP 2xx confirma y marca `published_at`; un ACK perdido puede causar reenvío con el **mismo `event_id`**. Equipo 7 debe registrar ese ID y procesarlo idempotentemente. HTTP 409 **no** es ACK hasta que se acuerde explícitamente su significado; permanece pendiente con error. 429/5xx/red se reintentan con backoff de 2 a 60 segundos, y otros no-2xx esperan 5 minutos para revisión operativa; ningún fallo descarta automáticamente el evento. No se garantiza orden global ni por sujeto.

El scheduler Laravel registra `events:publish` cada minuto con `withoutOverlapping(30)`, pero la infraestructura debe ejecutar `php artisan schedule:run` cada minuto; no está probada la operación de producción. El sink URL y la autenticación definitivos **no están acordados**. `EVENTS_SINK_TOKEN` sólo ofrece un Bearer estático configurable y provisional; sin URL el comando falla sin publicar. Las pruebas locales con HTTP real no demuestran que Equipo 7 reciba eventos. Equipo 7 **no** debe consultar `event_outboxes` directamente.

Tolerar campos adicionales futuros y no inferir titular actual de una tarjeta desde un evento aislado. `CredentialEvent`, `QrValidation` y `SecurityEvent` son registros de historial/auditoría, no publicaciones interequipos. Si Equipo 7 requiere datos de identidad en una operación, puede evaluar la API QR OAuth (`identity:qr:validate`) cuando exista un QR presentado; para otras consultas, plantear un contrato nuevo en vez de leer colecciones de Team 1.

## Gap contractual identificado, no implementado en INT-1B.6

La solicitud de Team 7 para `identity.credential.changed.v1` requiere `student_id`, `old_status`, `new_status` y `reason`, ausentes en el payload actual. `status` informa el estado resultante, sin transición old/new explícita. `event_id` y fecha (`occurred_at`) ya existen en el sobre; `credential_type` y actor (`actor_id`) existen en el payload. No hay UID NFC ni debe agregarse. El enriquecimiento/versionado es **DEFERRED**, no se modifica el evento en esta fase.

`/api/v1/integration/events` es el receiver propuesto y todavía pendiente de implementación/especificación final de **Team 7**: **EXTERNAL_TEAM_DEPENDENCY**, no una ruta de Team 1. La propuesta 202 nuevo/200 duplicado no demuestra un sink operativo ni reemplaza el acuerdo de autenticación/URL y aceptación end-to-end.
