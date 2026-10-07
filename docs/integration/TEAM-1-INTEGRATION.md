# Team 1 — Identity Integration Contract

Esta guía describe los contratos implementados por el Equipo 1 y sus límites para integrarlos con ramas que ya contienen trabajo propio. No es una instrucción de instalación limpia ni de merge automático. Las guías específicas están en este directorio.

## Estado del snapshot

- Evidencia fechada de INT-1C.1B-R (28-09-2026): 368 tests, 2502 assertions, 0 failures; lint, build y comprobación de diff correctos. No es un conteo permanente.
- Funcionalidad interna 1.1–1.9 cubierta; 1.3 incluye roles contextuales **internos**, no un contrato externo. QR de identificación y temporal, NFC y estado académico disponen de los contratos de consulta descritos abajo.
- 1.10 **parcial en publicación externa**: NFC/QR y estado académico por OAuth implementados; contratos business INT-1B.5 implementados localmente y pendientes de publicación. El transporte outbox local está implementado, pero su despliegue con Equipo 7 no está acordado ni probado.
- Deuda separada de la funcionalidad QR: existen rutas de compatibilidad con códigos plaintext legacy y quedan decisiones operativas sobre `purpose` y elegibilidad. No afirmar que todos los códigos históricos fueron migrados. No hay fecha comprometida.

| Requisito formal | Estado | Evidencia actual | Pendiente separado |
| --- | --- | --- | --- |
| 1.1 Cuentas y perfil | CUMPLIDO | `User`, `StudentProfile`, alta/edición/importación y UI `/students` | Deudas de datos/seeders no equivalen a falta de la función |
| 1.2 Autenticación | CUMPLIDO | Fortify, 2FA por política de rol, sesiones y recuperación | Configuración segura de cada despliegue |
| 1.3 Roles contextuales | CUMPLIDO | `Role::VALID_ROLES`, `User::hasRole()`, Policies/Gates y administración web internos | Contrato externo INT-1B en 1.10 |
| 1.4 Registro NFC | CUMPLIDO | Registro web, UID canónico, unicidad, propietario e historial | Ninguno funcional identificado |
| 1.5 Ciclo NFC | CUMPLIDO | Bloqueo, suspensión, pérdida, reactivación y reemplazo enlazado | Ninguno funcional identificado |
| 1.6 Identidad QR | CUMPLIDO | QR de identificación y dinámico/temporal, validación web/OAuth | Compatibilidad plaintext legacy y reglas operativas futuras |
| 1.7 Dispositivos y sesiones | CUMPLIDO | Dispositivos, sesiones, revocación, eventos y reautenticación | Operación de retención en despliegue |
| 1.8 Condición estudiantil | CUMPLIDO | Perfil/historial y API OAuth `students:read` | Elegibilidad de beneficios pertenece al consumidor |
| 1.9 Consentimientos y preferencias | CUMPLIDO | UI y API Sanctum, versiones/historial y outbox transaccional | No es una API OAuth interequipos |
| 1.10 Servicio de identidad | PARCIAL | API OAuth académica, QR y NFC; business INT-1B.5 local; outbox local | Publicación business y entrega externa a Equipo 7 pendientes |

## Regla de identificadores

El identificador externo de estudiante es **`User._id`**. En los contratos OAuth académicos, `{studentId}`, `data.student_id` y `data.user_id` (cuando existe) son ese mismo ID. En `student.profile.changed.v1` y `student.consent.changed.v1`, `payload.student_id` también es `User._id`. `StudentProfile._id` se usa internamente para relaciones e historial; no enviarlo como `{studentId}` a la API OAuth. La matrícula es un atributo, no el identificador de ruta.

La API Sanctum de consentimientos/preferencias conserva una búsqueda histórica que acepta tanto `User._id` como `StudentProfile._id` y puede devolver el segundo como `student_id`. **Esa ambigüedad no define el contrato interequipos**; no extrapolarla a OAuth ni basar integraciones nuevas en ella.

## OAuth service authentication

`POST /api/oauth/token` acepta `grant_type=client_credentials`, `client_id`, `client_secret` y `scope` (cadena de scopes separados por espacios). Devuelve `access_token`, `token_type=Bearer`, `expires_in` y `scope`. Los scopes solicitados deben estar asignados al cliente; una solicitud con alguno no permitido devuelve `400 invalid_scope`. Credenciales inválidas devuelven 401. Un token sin el scope exigido por la ruta recibe 403; sin Bearer válido, 401.

Scopes de servicio usados por las rutas públicas: **`students:read`**, **`identity:qr:validate`** e **`identity:nfc:validate`**. Son nombres literales; no existe alias con puntos. Cada cliente debe recibir explícitamente los scopes que necesita. No compartir ni registrar secretos de cliente. Este OAuth de servicios es independiente del login web, de Sanctum y de los roles embebidos de usuario.

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

El flujo **web** bajo `/nfc-cards` permite registro por admin global para un usuario con `StudentProfile`, UID canónico (trim + uppercase), historial inicial, bloqueo, reporte explícito de pérdida, suspensión, reactivación y `POST /nfc-cards/{nfcCard}/replace`. El reemplazo crea una tarjeta nueva para el mismo `User._id`, enlaza ambas credenciales y hereda `active`, `blocked` o `suspended` sin reactivar implícitamente. `active`, `blocked`, `suspended`, `replaced` son los estados persistidos; `replaced` es terminal y el endpoint genérico no lo crea. El dueño puede ver sus tarjetas/historial; admin puede administrar. Registro, cambios y reemplazo persisten tarjetas, historial con motivos y outbox en una transacción MongoDB.

`POST /api/v1/identity/nfc-validate` es el contrato **interequipos** de consulta. Exige Bearer OAuth `client_credentials` con **`identity:nfc:validate`**, independiente de `students:read` e `identity:qr:validate`, y tiene límite de 30 solicitudes/minuto. Envíe JSON `{"credential_uid":"04AABBCC"}`; el UID se normaliza con trim + uppercase y se admiten representaciones legacy equivalentes. No coloque el UID en la URL.

Una tarjeta `active` con propietario activo y `StudentProfile` devuelve HTTP 200 y, por ejemplo, `{"ok":true,"result":"valid","credential":{"status":"active"},"student_id":"{USER_ID}","identity":{"user_id":"{USER_ID}","name":"Nombre de ejemplo","student":{"enrollment_number":"ABC-123","campus":null,"academic_program":null,"academic_status":"active"}}}`. `student_id` y `identity.user_id` son **`User._id`**, nunca `StudentProfile._id`. La proyección `identity` usa `User::displayIdentity()`; `academic_status` es información de estado, no una decisión de elegibilidad.

Una credencial no usable responde HTTP 422 con `ok=false`, `student_id=null` e `identity=null`. UID desconocido o ambiguo: `result=not_found`, `credential=null`; `blocked`, `suspended` o `replaced`: `result` y `credential.status` indican el estado, **sin** identidad del titular; propietario inexistente/desactivado o perfil inexistente: `result=revoked`, `credential=null`. Payload vacío, no textual o mayor a 255 caracteres produce 422 de validación. Sin Bearer válido: 401; sin el scope NFC: 403. El estado concreto se comunica sólo al cliente OAuth autorizado para que distinga una tarjeta bloqueada/suspendida/reemplazada; no se publica identidad en esos casos. No se devuelve UID ni identificador interno de tarjeta.

Esta consulta registra el resultado, el cliente autenticado y, cuando se encontró una tarjeta, su `NfcCard._id` interno en `security_events` **sin guardar el UID**. No altera la tarjeta, no crea `CredentialEvent` ni publica `identity.credential.changed.v1`. **Identificación NFC no es autorización del servicio consumidor:** Equipo 1 confirma identidad y estado de credencial, no saldo, compra, beneficio, acceso físico ni permisos contextuales. La consulta/validación externa de roles y permisos queda para un contrato separado; no inferirlos de `identity.student` ni del scope OAuth. No derivar titularidad de eventos ni consultar `nfc_cards` desde otro equipo.

## Roles y contextos

Catálogo **legacy implementado**: `admin`, `maestro`, `estudiante`, `servicio_cafeteria`, `consejo_estudiantil`, `student_manager`. Este último permanece por compatibilidad; no se ha eliminado. `User::assignRole()`/`hasRole()` admiten asignación global (`scope_type=null`, `scope_id=null`) o contextual con ambos valores. `hasRole()` contextual exige coincidencia exacta de rol, tipo e ID: un rol global no satisface automáticamente una consulta contextual. Tipos legacy válidos: `business`, `association`, `service`, `council`; no exponen los scopes institucionales foundation descritos abajo. Las rutas administrativas actuales evalúan autorización en backend; la UI no es la autoridad.

Desde INT-1B.3, `RoleAssignment` es la autoridad; `User.roles` es histórico sin fallback. La administración legacy sigue limitada a seis roles. El motor business INT-1B.4 exige membership y asignación efectivas en el mismo negocio; `admin` global no evita ese requisito. Roles comerciales: `business_owner`, `business_manager`, `cashier`, `inventory_manager`, `buyer`. No existe alias `business_cashier` ni scope `identity:roles:check`.

## Contratos business INT-1B.5 — IMPLEMENTED_NOT_YET_PUBLISHED

Implementación **local**, no AVAILABLE_NOW. Referencia publicada sin cambios: `7a30c3f12722a4e2c6d4caef870f90309a11ae62`; no se ha realizado push. Las declaraciones históricas de disponibilidad en otras secciones no amplían este contrato.

| Método | Ruta | Scope requerido | Restricción adicional |
| --- | --- | --- | --- |
| POST | `/api/v1/identity/authorization/check` | `identity:authorization:check` | Cliente activo con grant persistente vigente |
| GET | `/api/v1/identity/assignments` | `identity:assignments:read` | Cliente activo con grant persistente vigente |
| POST | `/api/v1/identity/business-owner/provision` | `identity:business-owner:provision` | Además, cliente Team 3 en `OAUTH2_BUSINESS_OWNER_CLIENTS` |

Reutilizan `POST /api/oauth/token`, `client_credentials` y Bearer. Los tres scopes son independientes y requieren concesión explícita al cliente. La allowlist de provisioning está vacía por defecto: configurar IDs exactos verificados de Team 3 separados por comas. No conceder por nombre descriptivo del cliente. Cada ruta aplica 30 solicitudes/minuto. Sin token válido: 401; sin scope/grant/cliente permitido: 403; campos inválidos: 422; límite: 429. No registrar tokens ni secretos.

**Trust model:** `subject_id` es el sujeto objetivo, **no** un actor humano autenticado. OAuth autentica al servicio. `X-User-ID`, `X-Actor-ID` y `X-Employee-ID` no conceden autoridad. El consumidor debe vincular confiablemente su sesión humana antes de aplicar una decisión. No hay endpoints OAuth genéricos para asignar/revocar roles.

### Authorization check

JSON: `{"subject_id":"{USER_ID}","capability":"business.manage","scope_type":"business","scope_id":"{BUSINESS_ID}"}`. Respuesta 200 exactamente `{"authorized":true}` o `{"authorized":false}`. Consulta sintácticamente válida con sujeto inexistente, falta de membership/grant, capability desconocida o contexto no soportado: misma decisión negativa, sin enumeración de identidad. Campos requeridos: strings sin espacios/control, máximo 100. Delega en `BusinessAuthorizationService`; no replica RBAC.

### Assignment read

Query: `subject_id={USER_ID}&scope_type=business&scope_id={BUSINESS_ID}`. Respuesta 200: `{"subject_id":"{USER_ID}","scope_type":"business","scope_id":"{BUSINESS_ID}","membership_status":"active","roles":[{"role_key":"cashier","status":"active","starts_at":null,"ends_at":null,"assigned_at":"2026-10-06T12:00:00+00:00"}]}`. Fechas ISO-8601 o null. `membership_status` es el último estado persistido: active/pending/suspended/revoked, o null si no existe. `roles` sólo incluye asignaciones efectivas actuales con vigencia de membership y rol y cuenta elegible. Membership active futura/expirada puede tener roles vacíos. No expone historial, IDs internos de registros, revisiones, fingerprints, nombres o emails. Sujeto inexistente o sin vínculo: estado null y roles vacíos. Contexto no-business: 422. Negocio A nunca devuelve B.

### Initial Owner provisioning (sólo Team 3)

JSON: `{"business_id":"{BUSINESS_ID}","subject_id":"{REQUESTER_USER_ID}","operation_id":"{STABLE_OPERATION_ID}"}`. Tras aprobación/creación del negocio, Team 3 determina al **solicitante original**, no al administrador aprobador, como Owner. E1 no mantiene el catálogo comercial: confía en ese cliente explícitamente autorizado para este workflow estrecho.

201 al registrar una nueva operación; 200 para retry exacto. Ambos: `{"subject_id":"{REQUESTER_USER_ID}","business_id":"{BUSINESS_ID}","result":"provisioned"}`. Retry devuelve el resultado original, no reactiva ni garantiza efectividad tras una revocación posterior; consultar estado actual con read/check.

`operation_id` es único globalmente. Recibo vinculado a cliente/sujeto/negocio: reutilización distinta devuelve 409. Mantenerlo estable tras errores de red. Reserva única por negocio impide dos propietarios iniciales concurrentes. Mismo Owner válido con otra operación registra recibo sin duplicar membership/rol/generaciones. Owner diferente o previo no efectivo, membership pending/suspended/revoked/futura/expirada: 409 sin reactivación ni transferencia. Sujeto inexistente/eliminado/no elegible: 422. Membership activa y Owner válido existentes se conservan.

Reserva, membership, asignación y recibo se confirman en una **transacción MongoDB**; requiere replica set y migrations authorization más `2026_10_06_000100_create_business_owner_provision_indexes`. Schema de provisioning ausente/incompatible: 503 sin escrituras. La migration idempotente sólo crea índices únicos en dos colecciones nuevas, sin migrar datos legacy. El recibo audita cliente/sujeto/negocio/operación/resultado/fecha, sin credenciales OAuth, y no es autoridad. No hay eventos nuevos ni outbox. Ownership transfer deferred; administración interna continúa rechazando `business_owner`.

Equipo 2 sigue `PENDING_CLARIFICATION`, sin capabilities Bonos. Permisos finos de inventario/ventas pertenecen a Equipo 4; las ocho capabilities E1 y trece mappings no conceden permisos comerciales adicionales.

## Autorización institucional INT-1B.6 — interna/local, no publicada

`InstitutionalAuthorizationService` reutiliza `EffectiveRoleAssignments`, `RoleAssignment` y mappings persistentes sin modificar los contratos business. `organization_manager` concede `organizations.institutional.manage` sólo en `campus=string(Campus._id)`; `career_coordinator` concede `academic.program.coordinate` sólo en `academic_program=string(AcademicProgram._id)`. No usar code/name ni StudentProfile._id como sustitutos. Campus/programa deben existir y estar activos; el programa y su asignación deben conservar un campus coherente y activo.

Usuario elegible, asignación active/current vigente y Permission/RolePermission canónicos persistentes son obligatorios. No hay bypass admin, herencia entre scopes ni membresía institucional inventada. `department_head`/`academic.department.manage` permanecen definidos pero no asignables/resolubles positivamente: fuente Department **UNRESOLVED**. Autoridad institucional otorgante **DEFERRED**; ninguna nueva API/UI de escritura. Los seis roles legacy asignables no cambian.

Team 6 conserva organizaciones, memberships, cargos internos, delegaciones, historia y elegibilidad; autoridad institucional campus no equivale a administración interna de organizaciones. Su vínculo organización↔scope es dependencia externa. Véase [Team 6](TEAM-6.md). La resolución institucional es interna: las rutas OAuth check/read existentes siguen business-only. No anunciar un contrato institucional externo ni acceso directo a MongoDB.

## Consentimientos y preferencias

`/api/v1/students/{studentId}/consents` y `/preferences` son rutas `auth:sanctum`; las rutas `/student-services` usan sesión web. **No** están protegidas por OAuth de servicios ni son un contrato OAuth interequipos. No asumir acceso con `students:read` ni con `identity:qr:validate`. La compatibilidad dual de IDs de estas rutas es deuda separada, no una excepción a la regla OAuth.

## Eventos publicados

`StoreDomainEvent` almacena los eventos en la colección interna `event_outboxes`; `events:publish` puede entregar al sink configurado un sobre con `event_id`, `event_name`, `aggregate_id`, `occurred_at`, `payload`. **No existe aún un sink externo acordado con Equipo 7**; la persistencia interna no es una API para consumidores. Los payloads actuales son:

| Evento | Productor/cuándo | Payload | ID del sujeto / límite |
| --- | --- | --- | --- |
| `student.profile.changed.v1` | Alta/edición de perfil, cambio académico y preferencias | `student_id`, `operation`, `changed_fields`, `actor_id` | `student_id=User._id`; `operation` actual: `created`, `updated`, `academic_status_changed` o `communication_preferences_changed`; no contiene perfil completo ni valor nuevo de estado |
| `student.consent.changed.v1` | Aceptación/revocación de consentimiento | `student_id`, `consent_id`, `status`, `version`, `actor_id` | `student_id=User._id`; no es copia del registro de consentimiento |
| `identity.credential.changed.v1` | Registro NFC, cambio ordinario/pérdida y reemplazo | `credential_id`, `credential_type`, `operation`, `status`, `actor_id` | `credential_id=NfcCard._id` interno de la credencial, **no** `User._id`; `credential_type=nfc`. Un reemplazo emite `status_changed/replaced` para la vieja y `registered/{estado heredado}` para la nueva dentro de la misma transacción; no contiene UID, titular ni enlace old/new. Las otras operaciones actuales incluyen `lost`; no usar eventos aislados como consulta de titularidad. |

`CredentialEvent`, `QrValidation` y `SecurityEvent` son historial/auditoría, **no** eventos de integración. El consumidor debe tolerar campos adicionales futuros, deduplicar por `event_id` y no inferir orden global. Solicitar contrato de consulta si el payload no basta.

**Transporte local implementado, despliegue externo pendiente.** La migración `2026_09_27_000100_add_event_outbox_delivery_indexes.php` crea el índice único de `event_id`; en development figura `Ran`, batch 7 (28-09-2026), sin implicar estado de producción. Cada evento se reclama mediante una operación atómica con lease recuperable. El scheduler registra `events:publish` cada minuto con `withoutOverlapping(30)`, pero la infraestructura debe ejecutar `php artisan schedule:run` cada minuto; no hay evidencia de cron de producción. Sin `EVENTS_SINK_URL` no se envía nada. `EVENTS_SINK_TOKEN` permite Bearer estático provisional; URL, autenticación y semántica de HTTP 409 requieren acuerdo con Equipo 7. Cualquier 2xx confirma el evento; 409 sigue siendo error. El transporte es **at-least-once**: si el ACK se pierde, el mismo `event_id` puede llegar varias veces. Equipo 7 debe deduplicarlo. No hay garantía de orden global ni por sujeto, ni prueba de entrega a un sink de Equipo 7. Los fallos HTTP guardan `http_<status>`; red/timeout guardan `connection_error`; errores inesperados, `transport_error`, sin persistir cuerpos de respuesta ni excepciones crudas. 429/5xx/red aplican backoff de `min(60, 2^attempts)` segundos; otros no-2xx esperan 300 segundos y requieren revisión, sin descartar eventos. El estado operativo puede inspeccionarse en pendientes, `attempts`, `last_error`, `next_attempt_at` y `occurred_at` dentro del dominio de Equipo 1.

## Estado de integración por consumidor

| Consumidor | Contrato implementado por Equipo 1 | Estado / pendiente |
| --- | --- | --- |
| Equipo 2 | Estado académico OAuth; identificación QR/NFC cuando se presenta credencial | IMPLEMENTED para identidad; saldo, pago, recarga y retiro son decisiones de Equipo 2 |
| Equipos 3/4 | Identidad QR/NFC; check/read business y Owner inicial restringido | Contratos business locales IMPLEMENTED_NOT_YET_PUBLISHED; ver INT-1B.5 |
| Equipo 5 | Validación QR y NFC OAuth | IMPLEMENTED para identificación; acceso/beneficio es autorización de Equipo 5 |
| Equipo 6 | Estado/historial académico OAuth y evento `student.profile.changed.v1` persistido | PARTIAL: consumo externo de eventos pendiente; cargos con vigencia/delegación son de Equipo 6 o requieren acuerdo |
| Equipo 7 | Sobre versionado, outbox y publicador local probado con HTTP real | EXTERNAL DEPLOYMENT PENDING: URL, auth, 409, scheduler y prueba contra su sink |

## DO NOT DEPEND DIRECTLY ON TEAM 1 STORAGE

No consultar ni escribir directamente `users`, `student_profiles`, `academic_status_history`, `nfc_cards`, `credential_events`, `qr_tokens`, `qr_validations`, `security_events`, roles embebidos ni `event_outboxes` como workaround de integración. Son persistencia de Team 1; sus índices, documentos y procesos de backfill pueden evolucionar sin ser API pública. Si falta una operación (por ejemplo, roles contextuales para servicios), **registrar la dependencia con Team 1 y detener esa parte**.

## Integración con trabajo existente

Cada equipo debe comparar su rama y el snapshot antes de modificar. Preservar la semántica de ambos dominios; no reemplazar `User.php`, rutas, providers ni seeders completos y no resolver conflictos globalmente con “ours/theirs”. Usar [AI-INTEGRATION-PROMPT.md](AI-INTEGRATION-PROMPT.md) para preparar una auditoría y plan; integrar sólo después de revisión humana.
