# Equipo 7 — Eventos de identidad

Snapshot 2 publicado: `f41c78066cb8e6dde2a1605233e832395cb20841`. QR, NFC y estado/historial académico OAuth están **AVAILABLE_NOW**. Team 7 conserva íntegramente su elegibilidad y permisos locales de rewards/audit/analytics; no se incorporan al catálogo E1.

Esta guía describe el sobre y el publicador **implementados localmente por Equipo 1**, no una entrega externa ya acordada. Antes del consumo real faltan URL, autenticación, semántica de 409, ejecución del scheduler en deployment y prueba contra el sink de Equipo 7. **No** consulte `event_outboxes` directamente. Véase [contrato principal](TEAM-1-INTEGRATION.md).

Sobre publicado: `event_id`, `event_name`, `aggregate_id`, `occurred_at`, `payload`. Versiones existentes:

| event_name | payload actual | Semántica |
| --- | --- | --- |
| `student.profile.changed.v1` | `student_id`, `operation`, `changed_fields`, `actor_id` | `student_id=User._id`; aviso de cambio, no snapshot |
| `student.consent.changed.v1` | `student_id`, `consent_id`, `status`, `version`, `actor_id` | `student_id=User._id`; no incluye documento de consentimiento |
| `identity.credential.changed.v1` | `credential_id`, `credential_type`, `operation`, `status`, `actor_id`; extensión local: `student_id`, `old_status`, `new_status`, `reason` | NFC; `credential_id=NfcCard._id`; `student_id=User._id`. X9 CLOSED internamente; extensión no publicada, sin UID |

El productor local entrega **at-least-once** mediante un POST JSON por evento. Sólo un HTTP 2xx confirma y marca `published_at`; un ACK perdido puede causar reenvío con el **mismo `event_id`**. Equipo 7 debe registrar ese ID y procesarlo idempotentemente. HTTP 409 **no** es ACK hasta que se acuerde explícitamente su significado; permanece pendiente con error. 429/5xx/red se reintentan con backoff de 2 a 60 segundos, y otros no-2xx esperan 5 minutos para revisión operativa; ningún fallo descarta automáticamente el evento. No se garantiza orden global ni por sujeto.

El scheduler Laravel registra `events:publish` cada minuto con `withoutOverlapping(30)`, pero la infraestructura debe ejecutar `php artisan schedule:run` cada minuto; no está probada la operación de producción. El sink URL y la autenticación definitivos **no están acordados**. `EVENTS_SINK_TOKEN` sólo ofrece un Bearer estático configurable y provisional; sin URL el comando falla sin publicar. Las pruebas locales con HTTP real no demuestran que Equipo 7 reciba eventos. Equipo 7 **no** debe consultar `event_outboxes` directamente.

Tolerar campos adicionales futuros y no inferir titular actual de una tarjeta desde un evento aislado. `CredentialEvent`, `QrValidation` y `SecurityEvent` son registros de historial/auditoría, no publicaciones interequipos. Si Equipo 7 requiere datos de identidad en una operación, puede evaluar la API QR OAuth (`identity:qr:validate`) cuando exista un QR presentado; para otras consultas, plantear un contrato nuevo en vez de leer colecciones de Team 1.

## Extensión local del productor de credenciales — X9 cerrado internamente

La remediación E7-EVENT-R en `68b1fa50da5df8004d3abfb85f135ceef3e0fcb5` añade esos cuatro campos al productor local, sin eliminar ni reinterpretar los cinco existentes. No está publicada; el último commit público es `86111548a7659ffa3dab4fd6ee441bb70160b3d9`, que ya incluye consentimientos 1.9. X9 está CLOSED internamente, sin acreditar aceptación bilateral adicional ni integración operativa. No se permite publicar E7 por separado. El nombre permanece `identity.credential.changed.v1`. El sobre completo conserva `event_id` (UUID estable durante reintentos), `event_name`, `aggregate_id` (ID de credencial), `occurred_at` (fecha ISO 8601 capturada al almacenar el evento en la transacción) y `payload`:

| Campo payload | Significado y nulabilidad |
| --- | --- |
| `credential_id` | `NfcCard._id`, string, igual a `aggregate_id`; no identifica al estudiante |
| `credential_type` | `nfc` en los emisores actuales |
| `operation` | `registered`, `status_changed` o `lost`; se conserva su significado anterior |
| `status` | Estado resultante existente; no se reemplaza |
| `actor_id` | Actor original de la operación, `User._id` o null si no existe; nunca sustituido por un cliente o titular supuesto |
| `student_id` | `User._id` del titular capturado durante la mutación, no `StudentProfile._id`, matrícula ni UID. No nulo en los cinco emisores de producción actuales |
| `old_status` | Estado persistido anterior; null sólo en altas sin estado previo |
| `new_status` | Siempre igual a `status`; estado resultante, no estado académico |
| `reason` | Motivo registrado en el historial; null si no existe, sin textos de relleno |

| Operación | old_status → new_status | Motivo / actor |
| --- | --- | --- |
| Registro | null → active | `Registro inicial de tarjeta NFC`, ya registrado en historial; administrador autenticado |
| Cambio ordinario | Estado previo → destino permitido | Motivo validado; administrador autenticado |
| Pérdida | active/suspended → blocked | Motivo validado; administrador autenticado |
| Reemplazo, antigua | active/blocked/suspended → replaced | Motivo del reemplazo; administrador autenticado |
| Reemplazo, nueva | null → estado heredado de la antigua | Mismo motivo/actor; mismo titular, ID de credencial diferente |
| Baja de cuenta | active/suspended → blocked | `Baja de cuenta del propietario`, ya registrado; actor conservado del flujo de baja (titular) |

Reemplazar produce dos eventos distintos dentro de la misma transacción que ambas tarjetas y sus historiales. No hay estado `deleted`, ni evento adicional para tarjetas ya blocked/replaced en la baja. No se introduce enlace entre los eventos ni garantía de orden; no inferirlo por llegada. El titular se captura al mutar, sin consultarlo al entregar un evento pendiente. No se incluyen UID, nombres, correos, tokens ni datos personales adicionales; el motivo es el texto ya registrado por la operación y los operadores deben evitar incorporar información sensible en él.

La firma anterior del constructor sigue disponible: invocaciones antiguas sin contexto producen student_id/old_status/reason null, sin inventar información. Los emisores actuales suministran explícitamente el contexto completo. Esto no autoriza nuevos emisores sin titular canónico.

Los eventos v1 ya almacenados **no se reescriben ni enriquecen**: pueden carecer de los cuatro campos nuevos y se entregan con su payload original. Ausencia histórica de un campo no equivale a null explícito de una creación. No reconstruir titular/transición desde datos actuales. **X9 CLOSED internamente:** la nulabilidad, extensión aditiva v1 y convivencia con históricos son decisiones internas resueltas, no una nueva negociación pendiente. La compatibilidad del consumidor real y su recepción deben verificarse por separado; no afirmar aceptación bilateral adicional ni entrega operativa. La publicación requiere autorización posterior dentro del candidato integral, no una publicación E7 separada. Los eventos de roles y fallos de login siguen diferidos; esta remediación no los implementa.

`/api/v1/integration/events` es el receiver propuesto y todavía pendiente de implementación/especificación final de **Team 7**: **EXTERNAL_TEAM_DEPENDENCY**, no una ruta de Team 1. La propuesta 202 nuevo/200 duplicado no demuestra un sink operativo ni reemplaza el acuerdo de autenticación/URL y aceptación end-to-end.
