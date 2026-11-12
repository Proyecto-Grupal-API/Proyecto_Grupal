# Eventos: notas del fragmento OpenAPI 3.1

Revisión estática del checkout `/home/daniel/campus_digital_integracion_e1`, 2026-11-11. Sólo documentación; no se ejecutaron PHP, Artisan, Pest, Docker, peticiones HTTP ni consultas de BD. No se realizaron operaciones Git. No se modificó el catálogo global.

## Integración con el padre

- Fragmento deliberadamente no autónomo: `openapi: 3.1.0`, `paths` y `components.schemas`. No contiene `info`, `servers` ni catálogo global. Fusionar sus paths y schemas en un padre OpenAPI 3.1; todos los paths ya llevan `/api/v1/comunidad/portal`, sin volver a añadirlo desde un servidor con ese mismo base path.
- El padre debe aportar `components.schemas.Error`, `ValidationError`, `Message` y el esquema de seguridad `sessionCookie`. Se referencia el nombre de cookie del esquema padre, no se fija una cookie propia ni se agrega Bearer/OAuth.
- Los esquemas propios usan exclusivamente el prefijo `Evt`. Los `$ref` de componentes compartidos se resolverán al integrar; el catálogo existente, todavía 3.0.3 durante esta lectura, no se convierte ni se edita aquí.
- Se heredan sesión y CSRF. Para JSON enviar `Accept: application/json` y omitir `X-Inertia`; los errores de excepción del portal son JSON independientemente de Accept, pero los middleware de seguridad que devuelven directamente una respuesta sí dependen de esa negociación.
- En escrituras, usar `X-CSRF-TOKEN` o `X-XSRF-TOKEN` según la sesión del padre. Los dos headers se documentan opcionales individualmente porque son alternativas, no requisitos simultáneos. Pagos no admite `_token` en el body: su lista de claves permitidas sólo incluye `generacion`.
- `X-Organization-Id` es opcional y compara, no selecciona, organización. En cada escritura su discrepancia produce 409 incluso antes del controlador. No se le impone patrón ObjectId: el middleware sólo compara cadenas (si no hay organización, compara con cadena vacía).

## Cobertura de rutas

El fragmento cubre **19 operaciones en 16 paths** de `routes/comunidad.php` vinculadas a los tres controladores. No incluye vistas Inertia (`vista`) ni inventa GET/DELETE `/eventos/{id}`. Las rutas legadas `/api/...` se omiten intencionalmente; se documenta sólo el prefijo solicitado.

| Método y path bajo el prefijo | Acción | Estado de contrato | Códigos documentados |
| --- | --- | --- | --- |
| `GET /eventos` | `EventoController::index` | partial | 200, 302, 401, 403, 422, 423, 500 |
| `POST /eventos` | `EventoController::store` | partial | 201, 302, 401, 403, 409, 419, 422, 423, 500 |
| `PUT /eventos/{id}` | `EventoController::update` | reviewed | 200, 302, 401, 403, 404, 409, 419, 422, 423, 500 |
| `POST /eventos/{id}/publicar` | `EventoController::publicar` | reviewed | 200, 302, 401, 403, 404, 409, 419, 422, 423, 500 |
| `POST /eventos/{id}/cancelar` | `EventoController::cancelarEvento` | reviewed | 200, 302, 401, 403, 404, 409, 419, 422, 423, 500 |
| `POST /eventos/{id}/inscripcion` | `EventoController::inscribir` | reviewed | 200, 302, 401, 404, 409, 419, 422, 423, 429, 500 |
| `DELETE /eventos/{id}/inscripcion` | `EventoController::cancelarInscripcion` | reviewed | 200, 302, 401, 404, 409, 419, 422, 423, 500 |
| `GET /eventos/{id}/inscritos` | `EventoController::inscritos` | reviewed | 200, 302, 401, 403, 404, 422, 423, 500 |
| `GET /estudiante/boletos` | `EventoController::boletos` | reviewed | 200, 302, 401, 422, 423, 500 |
| `GET /eventos/{id}/boleto` | `EventoController::miBoleto` | reviewed | 200, 302, 401, 404, 423, 500 |
| `GET /estudiante/mi-boleto` | `EventoController::miBoleto` | reviewed | 200, 302, 401, 404, 423, 500 |
| `POST /eventos/checkin` | `EventoController::checkin` | reviewed | 200, 302, 401, 403, 404, 409, 419, 422, 423, 429, 500, 503 |
| `GET /staff/eventos` | `StaffEventoController::index` | reviewed | 200, 302, 401, 403, 422, 423, 500 |
| `GET /staff/eventos/{id}` | `StaffEventoController::show` | reviewed | 200, 302, 401, 403, 404, 423, 500 |
| `GET /eventos/{id}/staff` | `StaffEventoController::asignados` | reviewed | 200, 302, 401, 403, 404, 423, 500 |
| `POST /eventos/{id}/staff` | `StaffEventoController::asignar` | reviewed | 200, 302, 401, 403, 404, 409, 419, 422, 423, 500 |
| `DELETE /eventos/{id}/staff/{staff}` | `StaffEventoController::retirar` | reviewed | 200, 302, 401, 403, 404, 409, 419, 422, 423, 500 |
| `POST /eventos/{id}/pago` | `PagoEventoController::iniciar` | reviewed | 200, 302, 401, 404, 409, 419, 422, 423, 429, 500, 503 |
| `POST /eventos/{id}/pago/consultar` | `PagoEventoController::consultar` | reviewed | 200, 302, 401, 404, 409, 419, 422, 423, 429, 500, 503 |

## Honestidad de esquemas y `x-contract-status`

- Sólo `EventoController::index` y `store` quedan `partial`: propagan `Evento::toArray()` o el modelo directamente, sin Resource/whitelist. `fillable` no cierra los atributos almacenados ni garantiza presencia de campos históricos. `EvtEventoModelo` y `EvtEventoListado` identifican la misma limitación y mantienen `additionalProperties: true` con propiedades conocidas tipadas.
- Las demás operaciones se marcan `reviewed` por tener proyecciones concretas de entrada/salida, códigos rastreados y reglas de negocio descritas. Significa revisión estática del contrato, no prueba dinámica ni equivalencia exhaustiva entre JSON Schema y el validador Laravel. Las restricciones entre fechas, contexto de organización, BD, concurrencia y límites de cadenas numéricas siguen comprobándose en Laravel, con reglas originales y notas.
- En `resumen`, `boleto`, resumen staff y usuarios no se usa la lista de `only()` como garantía de que todos esos atributos existen en MongoDB. El vendor local de `Model::only()` asigna `getAttribute()` a cada clave y normalmente materializa ausencias como null; el fragmento acepta también omisión en las claves de esa proyección. Se exigen las claves añadidas explícitamente por el controlador (estado/generación, datos calculados, envelopes, etc.). No hay required masivo derivado de fillable/only.
- Los esquemas de proyecciones explícitas sí cierran propiedades extra; no se aplican `additionalProperties: false` ni `unevaluatedProperties: false` a modelos crudos. `EvtBoleto` y `EvtInscrito` enumeran la proyección completa para evitar el falso cierre de un `allOf` que extiende una base cerrada.
- Los enums de estados provienen de filtros, escritores y transiciones de los servicios. Los atributos históricos opcionales admiten null; moneda en la proyección es string nullable (las nuevas inscripciones escriben MXN). No se trasplantan máximos del FormRequest como restricciones del histórico.

## Entrada, consultas y reglas de negocio

- **Crear/editar:** POST y PUT usan exactamente `GuardarEventoRequest`; ambos requieren título (máximo 150), descripción (5000), ubicación (255), cuatro fechas locales, costo 0..100000 con 0..2 decimales, capacidad 1..10000 y lista_espera boolean. No se agrega PATCH ni una actualización parcial. Los extras se ignoran al extraer `validated()`.
- Fechas de entrada: `Y-m-d\TH:i`, calendario válido, sin segundos ni offset. Se interpretan en `America/Mexico_City` y convierten a UTC. Inicio > ahora, fin > inicio, cierre de registro > apertura y <= inicio del evento. Respuestas: fechas Carbon ISO 8601 UTC, con fracción variable; no se exige el mismo formato local.
- Laravel `integer` no strict admite números/cadenas aceptados por `FILTER_VALIDATE_INT`. Las variantes string del esquema son una aproximación léxica deliberadamente amplia: no toda cadena con dígitos pasa FILTER_VALIDATE_INT y sus límites min/max siguen especificados en `x-laravel-rules` y descripción. `decimal:0,2` también depende de la representación PHP. No se afirma validación numérica exacta sobre strings por JSON Schema ni se usa un `multipleOf` que cambie esa semántica.
- Boolean Laravel acepta únicamente true, false, 0, 1, "0", "1"; no las cadenas "true"/"false". El middleware normaliza trim/cadena vacía a null antes de validar; el esquema describe valores efectivos y las variantes usuales de transporte, no cada posible representación previa a esa normalización.
- Todos los `{id}` y `{staff}` tienen patrón de ruta hexadecimal de 24 caracteres con mayúsculas admitidas. No se inventan UUID para esos IDs. Las claves de intención sí son UUID. Algunas acciones normalizan a minúsculas mediante `InscripcionesEvento::ejecutar`/pagos; las consultas directas de otros métodos conservan el valor recibido.
- Listados de eventos, boletos y staff: página fija de 12. Inscritos: 20. `page` nullable, integer >=1, sin máximo. No existen `limit`/`per_page` configurables. `page` fuera de rango puede superar `last_page` y arrojar lista vacía. Asignados staff no se pagina ni tiene límite declarado.
- `GET /eventos`: buscar string nullable de máximo 120; gestion boolean nullable; estado nullable borrador/publicado/cancelado. Estado se valida siempre pero sólo filtra en gestión. Fuera de gestión sólo publicados no finalizados de organizaciones activas; gestión requiere permiso y restringe a organización seleccionada.
- Editar no admite evento iniciado/cancelado. Capacidad no baja de reservas con cupo. Con cupo activo o espera no cambia costo, ubicación ni fechas del evento. Publicar exige cierre futuro. Cancelar bloquea asistencia, pagos pagados en inscripciones confirmadas e intentos financieros pendientes vigentes. Repetir una cancelación ya aplicada es válido.
- Inscripción/cancelación aceptan body ausente o `{}` en modo legado; clave_solicitud y generacion son nullable y mutuamente `required_with` cuando el otro no está vacío. Generación esperada >=0 (0 antes del primer registro), sin máximo de negocio declarado. Al activar solicitudes versionadas, futuras escrituras sin clave/generación dan 409. Misma clave con otra intención/evento/generación da 409; replay retorna el registro actual y la generación histórica de la solicitud. No hay header Idempotency-Key de navegador para este flujo.
- Reservas de pago duran hasta min(ahora+15 minutos, inicio). Sin cupo hay espera sólo si está habilitada; gratis se marca exento. GET calcula estado_efectivo sin necesariamente persistir expiración. Cancelar una reserva pagada exige devolución; con intento pendiente puede devolver `cancelacion_pendiente`, no una cancelación financiera ya confirmada.
- `GET /eventos/{id}/inscritos`: estado confirmada/espera/cancelada/vencida y page; `confirmada` aplica `conCupo`, `vencida` incluye vencimientos calculados. Sólo gestores, no basta staff de acceso. Retorna nombre/matrícula sin QR.
- `GET /eventos/{id}/boleto` busca la inscripción propia por evento. `/estudiante/mi-boleto` devuelve el último registro propio sin filtro de estado; **no consume evento_id de query**, ni tiene página. Si el evento fue eliminado puede seguir devolviendo boleto histórico. `/estudiante/boletos` incluye todos los estados.
- El QR se revela únicamente con organización activa, evento publicado no finalizado, inscripción confirmada, pago pagado/exento y sin asistencia. `qr_habilitado` no garantiza que la ventana de escaneo ya abrió. Si falta evento, hay datos null, título de fallback y banderas false.
- **Check-in:** metodo omitido = boleto. QR requiere token_qr (máximo 255); NFC requiere credential_uid (máximo 255) y flag. Evento_id es obligatorio. Usuario_id no vacío está prohibido, pero Laravel `prohibited` permite null/colección/cadena vacía; por eso no se reemplaza por una prohibición absoluta de presencia. Campos token_qr/credential_uid opuestos se omiten: también tienen regla string sin nullable, así que su combinación con prohibited tras normalización impide usarlos como un segundo canal de identidad. Se conservan las reglas originales completas en `x-laravel-rules`.
- Acceso sólo por gestor o staff asignado y organización seleccionada correspondiente. Evento publicado, desde inicio menos 30 minutos (configurable) hasta antes del fin. Inscripción confirmada, pagado/exento y no utilizada; QR requiere usuario local y NFC identidad externa válida. Credencial rechazada por Identidad con 422 genera error credential_uid; fallos de conexión/respuesta o flag apagado generan 503. Retorna asistente y asistio_en, nunca el QR.
- Staff asignar exige usuario existente y miembro activo; evento no cancelado con fin futuro. Reactiva asignaciones eliminadas y repetir una vigente sigue dando 200. Retirar recibe ID de la **asignación**, hace baja lógica y no aplica la misma restricción temporal de asignar. Asignados incluye integrantes activos y nombres locales con fallback.
- **Pagos:** generacion requerida 1..999999999. `request->all()` combina query/cuerpo/archivos, por eso el body es opcional sólo cuando generacion viene por query. Debe aparecer en al menos una ubicación; ambas omitidas producen 422. Sólo se documenta JSON como transporte principal, no se inventa un FormRequest ni importe/moneda/usuario elegibles por cliente. Cualquier clave extra en query/body produce abort(422) sin errors; `_token` tampoco está permitido.
- Flag de pagos estrictamente true, scope de organizaciones y cliente de Finanzas. No se fija el estado actual de despliegue del flag: sólo sus reglas. Inicio exige reserva vigente de misma generación, evento activo, costo positivo y MXN. La referencia de idempotencia se genera internamente como `event-reservation:{registro_id}:{generacion}`. No es un header que deba suministrar el consumidor del portal.
- Inicio devuelve siempre 200 con estado local y confirmation_path (ruta relativa de consentimiento de Finanzas sólo cuando pendiente, null en otro caso). Consultar es POST con efectos de conciliación; retorna únicamente estado. No devuelve el objeto pago ni necesariamente exige vigencia del evento para estados terminales. Estados locales: pendiente/aplicado/cerrado/devolucion_pendiente/devuelto; cancelacion_pendiente sólo pertenece al registro, no a la respuesta de PagoEventoController. Los estados uppercase de E2 no se trasladan al portal.

## Errores, límites y caché

- `bootstrap/app.php` normaliza excepciones del portal: `{message}` y `errors` sólo para ValidationException. Los mensajes de 401/403/404/419/422/429 son fijos allí. Mensajes HTTP 409 se conservan; los errores 500/503 se ocultan con el mensaje genérico. No se inventa un campo code en Error ni se copian al contrato mensajes internos de 503 como si llegaran al cliente.
- 422 de validación referencia `ValidationError`. Pagos referencia `EvtPagoError422` (`anyOf` Error/ValidationError), porque claves extra usan abort sin errors. `ResponsabilidadesComunidad` puede producir un 422 con errors.password por lock agotado incluso en operaciones sin body, como retirar staff/publicar; no se confunde con un campo password requerido al consumidor.
- Los 409 de check-in/inscripciones/edición/staff no garantizan que el origen sea siempre un catch local de LockTimeoutException: `ResponsabilidadesComunidad::ejecutar` puede convertir esa excepción de locks anidados a ValidationException (422). Se incluyen ambos caminos definidos por el código y el conflicto global de organización.
- 401 autenticación; 403 sólo donde existe organización/policy; 404 de patrones/lookup/alcance/flags; 419 CSRF en escrituras; 500 de excepciones no controladas según el normalizador. No se agrega una respuesta 201/204 por convención a acciones que devuelven 200.
- 302/423 son caminos reales de middleware web: sesión revocada redirige a login; contraseña inicial/2FA pendientes devuelven 423 JSON con formas diferentes o redirigen según Accept/X-Inertia. `EvtBloqueoSeguridad` conserva ambas formas, incluidas code o enrollment_url donde corresponden. No se asegura que todos los fallos pasen por el normalizador.
- Throttles explícitos por ruta: check-in 60/1 minuto, inscripción POST 30/1, pagos inicio/consulta 12/1 cada ruta. No se agrega 429 de ruta a los demás endpoints ni se inventa Retry-After: el normalizador conserva headers HTTP cuando existen, sin garantizar su listado aquí.
- `Cache-Control: private, no-store` en éxitos sólo donde el controlador lo añade: inscripciones, boletos, check-in, consultas staff y pagos. Los errores de excepción normalizados también lo incluyen. Los 423 directos y éxitos de catálogo/escrituras de gestión no reciben esa cabecera por este código; no se les atribuye.

## Evidencia y comprobaciones

La fuente primaria fue el código actual, leído sin ejecutar PHP. Se consultó de forma secundaria `docs/integration/evidence/comunidad-2026-11-11/eventos-http.json` para contrastar envelopes, fechas y estados de pago; es evidencia preexistente y redactada, no una prueba ejecutada en esta tarea ni autorización para afirmar operación end-to-end actual. Los tokens y campos sustituidos por `[omitido]` no se tratan como valores originales.

Se comprueban con Python/JSON Schema: JSON válido, todos los schemas válidos para Draft 2020-12, referencias locales y dependencias padre permitidas, unicidad de operationId, parámetros de path y cobertura exacta de las rutas de los tres controladores, prefijo de todos los paths, enums/límites y ausencia de tipos/esquemas vacíos. Se validan respuestas de éxito conservadas en la evidencia y casos positivos/negativos de estructuras (sin ejecutar servicios). Es una comprobación estructural de fragmento; no sustituye un validador OpenAPI completo ni ejecuta peticiones.

Fuentes leídas (rutas relativas al checkout; hashes SHA-256 al generar el fragmento):

| Fuente | SHA-256 |
| --- | --- |
| `routes/comunidad.php` | `705fa62b7f6d8b7aebcfb76845d5d3a2a085e10690461f610395cef5070569f4` |
| `routes/web.php` | `8dd2dfdec5f73f7f46de142aa017af2ccf4c8d9d788ad56d19f42ab23b13474d` |
| `bootstrap/app.php` | `0428093bdeee159e09d691784707e4ff0602e18bfd46c913a6065fb570e6814c` |
| `app/Http/Controllers/Controller.php` | `d093c43c6861c5b4e77dca9985f0824c3ff52f923ab441907647a11933eaf00f` |
| `app/Http/Controllers/EventoController.php` | `c9c183952bf5c56fcf12104acf6a3889a9c8b1934f70d8c597c1776062fd7847` |
| `app/Http/Controllers/StaffEventoController.php` | `772542dad456a97a5968d969aa664af3af2dee460664142bf55fd479006cdcbf` |
| `app/Http/Controllers/PagoEventoController.php` | `1c52fc34e912a5ff5e5b473c00670c470e0caca32b72f94f9700336019296e37` |
| `app/Http/Requests/GuardarEventoRequest.php` | `f0cfe9b9294b98261557dcee1aff5f2db43b41f3a1e2883e00a956f752a98ed1` |
| `app/Models/Evento.php` | `094cbcdf4c20932323e437bd43cff6ddc530db62aa6fce6386013dbc528db5b6` |
| `app/Models/RegistroEvento.php` | `daad270e0b880cba686995357ccc8adce6a5b9ac6ea2c1dd5c9e1ae01d1a838a` |
| `app/Models/PagoReservaEvento.php` | `0613175385c9a1f4d02fd9ed3679308b75b08f3a5e70dbcf534053f65da77a4e` |
| `app/Models/StaffEvento.php` | `f6c64dffe8d8fee70bcb4d663bb09fa65b60c1754fb28c1fa97d13a2945fb789` |
| `app/Models/Documento.php` | `6a5d9736e18b32538b7fcb1cc940edf47e49bae2a359b1e94e454c7996d3058f` |
| `app/Models/User.php` | `de14bb0d93d719d0ebc9ac44bb2ce8eead3a578d9cfba7f1b4bc6547fdba084f` |
| `app/Policies/EventoPolicy.php` | `e83b71924462f702b2743b0fc8cfba5532363b1f76efb7b5eb02681d1e89f0c5` |
| `app/Services/InscripcionesEvento.php` | `21eb7654b80d9eaa3edfe1aa75abc811060ca9d20a8faca772685c43951ec0d7` |
| `app/Services/PagosEvento.php` | `7058cb4cd314bd8f1a5607b8dfa5d0d91a4313df2911e69fa34a3bc44f9c88b1` |
| `app/Services/IdentidadComunidadApi.php` | `ed92187d5defc158e772c956bc0bb070626d8b641c27a20840012f62dc94dfcf` |
| `app/Services/ResponsabilidadesComunidad.php` | `a8ef97fe81352e32f61bb3ae71cd45d61591fd485e0f023e8a530874c9862d36` |
| `app/Http/Middleware/SeleccionarOrganizacion.php` | `008ec2ddc73ae13decb41d7cb21066e46a3f542d211ad726c7cf44cf34232486` |
| `app/Http/Middleware/EnsureSessionIsActive.php` | `6892bfc8100157e6725cfc610dc8bc83ecde4ff5bfe1faf7b981c5668fdd187b` |
| `app/Http/Middleware/EnsureInitialPasswordChanged.php` | `8e7bd834fd937e7cba2885f6c1b221265a08184f321c46134ab9ab686c46c272` |
| `app/Http/Middleware/EnsureRequiredTwoFactorAuthentication.php` | `3586b2cdee7ef0109003719fa65ff379c91bbaaa88c812facf863416e7bfa79d` |
| `config/comunidad.php` | `6acc1fa693d569caa0ecaf524c5c89550e8b00e1641386ac3f8091a8cba44151` |
