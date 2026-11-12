# Contratos de organizaciones

Corte: 2026-11-11. Revisión estática del código; no se ejecutó PHP, aplicación, DB, HTTP, pruebas PHP ni Git. Solo se editan este archivo y `organizaciones.openapi.fragment.json`.

| Bloque | Operaciones | reviewed | partial |
|---|---:|---:|---:|
| periodos | 6 | 5 | 1 |
| documentos | 8 | 8 | 0 |
| comisiones | 7 | 7 | 0 |
| delegaciones | 3 | 3 | 0 |

El único `partial` existente es GET períodos/{id}/historial: snapshots `antes/despues` sin casts anidados; la representación histórica exacta de fechas BSON/Carbon no se demuestra sin evidencia adicional. Se conserva la razón concreta en `x-contract-reason`.

## Criterios comunes

- Fragmento OAS 3.1 para el mismo parent: reutiliza `Error`, `ValidationError`, `Message`, `MongoId` y `sessionCookie`; componentes propios con prefijo `Org`. Null se expresa con tipos/ramas JSON Schema, nunca `nullable`.
- Fuentes comunes: `routes/web.php` (alias `/api` y portal versionado), `routes/comunidad.php`, `Controller::organizacion`, `OrganizacionPolicy`, `SeleccionarOrganizacion`, middleware de sesión/contraseña/2FA y `bootstrap/app.php`. Cada operación lleva `x-source`. El alias legacy `/api` no se duplica en este fragmento del portal.
- Sesión y membresía activa en lecturas; presidencia vigente en escrituras. `X-Organization-Id` solo detecta pestaña desactualizada (409). CSRF en mutaciones; 419 si inválido. 302/423 dependen de los middleware de cuenta y de negociación JSON. 422 de validación incluye `errors`; abort(422) solo `message`; el renderer sanea ambos mensajes. Bloqueo compartido 120 s, espera 5 s: timeout -> 422 `errors.password`.
- Los controladores nuevos usan `Request::validate` inline; no FormRequests propios. Entradas abiertas porque Laravel ignora extras. Proyecciones `.only()` y campos de modelos antiguos quedan opcionales y anulables; solo envoltorios y campos calculados explícitos se exigen. Modelos `toArray()` abiertos por atributos adicionales. No se deducen límites de salida de validaciones actuales de entrada.

## Documentos — 8 operaciones

- Fuentes: `DocumentoOrganizacionController` (incluidos `metadatos`, `validarArchivo`, `buscar`, `resumen`, `version`), `DocumentosOrganizacion`, modelos `DocumentoOrganizacion`/`VersionDocumentoOrganizacion`, `config/filesystems.php`, y `FilesystemAdapter::download/response`.
- POST alta y nueva versión: multipart `archivo` obligatorio, MIME inferido y extensión original pdf/jpg/jpeg/png, máximo 5120 KiB = 5 242 880 bytes; `throttle:30,1` -> 429. Metadata completa en PUT; descripción omitida/null -> vacío, visibilidad omitida/null -> presidencia. Archivos privados sin URL pública, sin ruta/hash en la proyección.
- Listado 10/página (`creado_en desc`, `_id asc`); historial 20 (`numero desc`). Presidencia puede consultar retirados e historial completo; miembros solo documentos activos visibles a integrantes y versión actual. `estado` omitido -> activo; null explícito filtra null (no es el default).
- 201 en alta/nueva versión, 200 al repetir clave y misma huella de datos+actor+bytes; nueva versión responde documento y versión actual, no necesariamente la versión de la clave repetida. Metadata no cambia archivo. Documento retirado no admite cambios (abort 422); versión optimista distinta o clave con otro contenido -> 409. Repetir retiro retorna 200 antes de comprobar versión.
- Descargas actual y por ID: binario, Content-Disposition attachment, MIME de la versión, Content-Length del disco, nosniff y private/no-store. 404 por documento/versión ajena, no visible, histórica para no administradores o archivo ausente. Audita cada descarga. Se modela media dinámico, sin garantizar un MIME fijo para archivos históricos.

## Comisiones — 7 operaciones

- Fuentes: `ComisionConsejoController` (validación inline y `consejo/datos/resumen`), `ComisionesConsejo`, `ComisionConsejo`, `IntegranteComision` y membresías activas. Organización de tipo distinto de `consejo` ->404.
- Listado 10/página; detalle 20 integrantes/página, ambos `creado_en desc, _id asc`. `estado` omitido ->activa, null filtra null. `historial` acepta booleanos Laravel (0/1, no textos true/false); true incluye retirados. Situación calculada con fecha de Ciudad de México; `vigente` del integrante exige membresía activa y comisión vigente.
- POST alta/asignación ->201, replay con misma clave/datos/actor ->200 incluso si después se cerró/retiró. PUT exige metadata completa y versión; cierre retira participaciones. Cierre/retiro repetidos ->200 sin sustituir motivo; cierre ya cerrado omite cotejo de versión.
- 422: nombre slug vacío/duplicado, comisión cerrada/no vigente, integrante no activo/duplicado o coordinación ocupada; 409: clave reutilizada con otra huella o versión optimista desactualizada. `asignacion` es ID de participación, no de usuario. Mutaciones de comisiones no añaden explícitamente private/no-store en éxito; lecturas sí.

## Delegaciones — 3 operaciones

- Fuentes: `DelegacionOrganizacionController`, `DelegacionesComunidad`, `PermisosComunidad`, `DelegacionOrganizacion`, `RolOrganizacion::vigentes`, membresías y `config/financial.php` (sin leer .env ni allowlists reales).
- Listado 10/página (`creado_en desc, _id asc`), presidencia ve todas; otros integrantes solo las recibidas, en cualquier estado. Capacidades: 3 base y 6 de caja solo para asociación/consejo habilitado por allowlist; se pueden seleccionar máximo 3, distintas. La respuesta no acredita que caja esté habilitada en una organización real.
- `inicio/fin`: Y-m-dTH:i sin segundos/offset, Ciudad de México. Inicio >= minuto actual, fin > inicio y > ahora, sin exceder inicio del día siguiente a fecha_fin de presidencia cuando existe. Destinatario: otro integrante activo. 422 en destinatario_id, fin o capacidades; 409 clave con otro contenido.
- Alta ->201; replay con clave y mismo contenido normalizado+delegante ->200, antes de validar otra vez destinatario, vigencia o cargo origen; autorización y capacidades habilitadas sí se verifican. Revocación repetida ->200 conservando datos originales.
- Estado: revocada > vencida > programada > vigente/sin_efecto. Vigencia exige organización y versión de estado coincidentes, ambas membresías activas y presidencia origen con firma vigente. `clave_idempotencia` sí puede aparecer en salida; firma_origen/huella están ocultos.

## Verificación

Cierre: 24 operaciones (23 reviewed, 1 partial), sin alterar las seis operaciones iniciales de períodos. JSON y JSON Schemas Draft 2020-12 válidos; referencias contrastadas con el parent en memoria; parámetros de path y operaciones contrastados con `runtime-routes.json`. No se reconstruye ni modifica el catálogo global. Esto no acredita ejecución funcional.
