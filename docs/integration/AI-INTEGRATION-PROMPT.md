# Prompt reutilizable para integrar Team 1

Estás integrando el snapshot del Equipo 1 con una rama que YA contiene trabajo del equipo consumidor.

Tu primera fase es **AUDITAR → PLANIFICAR → REPORTAR**. No ejecutes merge, checkout, cherry-pick ni modifiques conflictos automáticamente. Antes de cualquier cambio, inspecciona ambas ramas, sus contratos y tests; inventaría los archivos modificados por ambos equipos, clasifica conflictos textuales y semánticos, y presenta un plan para aprobación humana. Sólo tras esa aprobación aplica cambios acordados.

Lee `docs/integration/TEAM-1-INTEGRATION.md` y la guía del equipo consumidor. Conserva `User._id` como ID externo de estudiante en los contratos OAuth; no aceptes `StudentProfile._id` como sustituto. Respeta los scopes literales implementados `students:read`, `identity:qr:validate` e `identity:nfc:validate`; `identity:roles:check` **no está implementado**. No cambies contratos Team 1 para hacer pasar una integración local.

No reemplaces archivos enteros por la versión de un equipo ni uses “ours/theirs” como solución general. Preserva ambos dominios y resuelve cada conflicto semánticamente. La validación NFC OAuth y el reemplazo NFC web **sí existen localmente**. INT-1B.5 implementó check/read business y provisioning inicial restringido, sin endpoint `role-check` ni escritura genérica de roles; INT-1B.6 añade resolución institucional interna, no API OAuth institucional. Lee rutas/scopes/trust boundary exactos del contrato principal. Si falta un contrato requerido, **detente y reporta la dependencia**; no leas/escribas colecciones internas de Team 1 como workaround ni inventes endpoints.

No conviertas identificación QR/NFC en autorización de compra, servicio o acceso. `client_credentials` autentica al servicio, no al actor humano: no confíes en un `subject_id` arbitrario enviado por navegador. `RoleAssignment` es autoridad sin fallback a `User.roles`. La UI/API legacy conserva seis roles y sus cuatro tipos de contexto; foundation no los amplía. Business y resolución institucional local están separados; department no tiene fuente canónica y deniega. Elegibilidad y permisos finos siguen en cada consumidor. Referencia pública `7a30c3f12722a4e2c6d4caef870f90309a11ae62`: QR y estado académico publicados; NFC/business no publicados; institucional sólo interno/local. No confundir código local con entrega externa.

El transporte outbox de Equipo 1 está probado localmente, pero la integración externa con Equipo 7 sigue pendiente. No presupongas URL, autenticación, semántica de 409, cron de producción ni recepción real. La entrega es **at-least-once** con `event_id` estable y deduplicación por el consumidor; no prometas exactly-once ni orden de eventos. No permitas acceso directo a `event_outboxes`.

Superficie de conflicto **ALTO**:

| Archivo | Semántica Team 1 que debe preservarse |
| --- | --- |
| `app/Models/User.php` | Mongo/BSON, perfil, roles globales/contextuales, `displayIdentity()`, 2FA y bloqueo de cuentas pendientes |
| `app/Models/Role.php` | Catálogo cerrado de seis roles y tipos de contexto `business`, `association`, `service`, `council` |
| `routes/web.php` | Middleware de sesión/dispositivo, autorización administrativa, rutas de perfil, QR, NFC y roles |
| `routes/api.php` | Separación OAuth `students:read`/`identity:qr:validate`/`identity:nfc:validate` frente a Sanctum; sin role-check externo |
| `routes/auth.php` | Login, activación/reset y challenge TOTP existentes |
| `bootstrap/app.php` | Alias y orden de middleware de OAuth, roles contextuales, sesión activa y tracking |
| `app/Providers/AppServiceProvider.php` | Listener de eventos de dominio al outbox y revocación de sesión en logout |

Superficie **MEDIO**:

| Archivo | Semántica Team 1 que debe preservarse |
| --- | --- |
| `app/Http/Middleware/HandleInertiaRequests.php` | Props compartidas de auth/roles/estado de interfaz |
| `resources/js/Layouts/AuthenticatedLayout.vue` | Navegación y manejo de sesión/dispositivo |
| `database/seeders/DatabaseSeeder.php` | Catálogos disponibles; cuentas y datos demo sólo en local/testing |
| `database/seeders/RoleSeeder.php` | Catálogo de roles, distinto del bootstrap de una cuenta demo admin |

Tras la aprobación humana y la integración, ejecuta los tests del equipo consumidor **y** las regresiones relevantes de Team 1; comprueba rutas, scopes, IDs, eventos, build y `git diff --check`. Reporta cambios, pruebas y dependencias pendientes. No declares éxito basándote sólo en ausencia de conflictos Git.
