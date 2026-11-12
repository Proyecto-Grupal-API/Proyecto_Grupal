# Contratos de contexto del portal

Alcance: cuatro controladores indicados, exclusivamente `/api/v1/comunidad/portal`. Fragmento para integrar `paths` y `components.schemas`; no es un documento OpenAPI autónomo. Dialecto OpenAPI 3.1.0: anulables mediante `anyOf` con `type: "null"`; no queda `nullable: true`. El padre aporta `Error`, `ValidationError`, `Message`, `sessionCookie` y CSRF. Todos los componentes nuevos usan `Ctx`. No se modificó el catálogo global ni archivos de otros agentes.

## Cobertura y forma real

- OrganizacionController: index, seleccionar, updatePerfil, addMiembro, assignRol (POST/PUT), removeMiembro y removeRol.
- GestionOrganizacionesController: index, show, titular, store, completar y estado. `vista()` es Inertia fuera del prefijo solicitado y queda excluida.
- DashboardController: index. IdentidadComunidadController: __invoke y la transformación de IdentidadComunidadApi::estado.
- No existe un DTO independiente para estas respuestas: los límites de serialización están en los arrays de los controladores, `IdentidadComunidadApi`, `Documento`, modelos y vendor. No se sustituyeron por un contrato hipotético.

## Parcialidad explícita

`GET organizaciones` permanece **partial** porque entrega modelos MongoDB completos (`organizaciones`, `organizacion`, `miembros`, `roles`). `$fillable` no es un filtro de lectura: los atributos persistidos no ocultos sobreviven a `toArray()`. `Organizacion::$hidden` excluye exclusivamente alta_datos, clave_alta, firma_alta e historial_estados. Se tipan propiedades conocidas y se permiten extras mediante `CtxJsonValue` recursivo. No se afirma que el conjunto de campos ni los tipos históricos estén completamente garantizados: no se consultó DB ni un validador de colección. Los estados/tipos conocidos corresponden a las escrituras actuales y filtros leídos.

`GET gestion-organizaciones/{id}` permanece **partial** porque `historial_estados` se expande con `[...$h, actor_nombre => ...]` sin proyección de claves. Extras persistidos sobreviven; `collect()->map()` preserva claves y puede serializar un historial asociativo como objeto. Los campos de RegistroOrganizaciones se tipan sin inventar presencia obligatoria en documentos históricos; solo actor_nombre se añade incondicionalmente. El resumen de organización sí tiene una proyección cerrada.

Los objetos cerrados del dashboard y de Identidad no arrastran los extras de los modelos completos. Las proyecciones `get([...])` de suspendidas y periodos no convierten atributos ausentes en obligatorios.

## only(), null y fechas

Se leyó el vendor instalado: `HasAttributes::only()` construye cada clave solicitada con `getAttribute()`. En `resumen`, `titular` y `ultimasBecas` esas claves están presentes y un atributo ausente puede ser null. En cambio `Arr::only()` usa `array_intersect_key`: en Identidad solo son required student_id, user_id, status, restrictions y benefits_eligible. name, enrollment, program, campus, status_label, effective_from, status_reason y semester son opcionales y nullables. `effective_from` no tiene validación de fecha: es string, sin format date/date-time. No se impone regex ObjectId adicional a IDs de esa respuesta: el servicio solo comprueba igualdad con el usuario autenticado.

`Documento` hereda MongoDB Model, con creado_en/actualizado_en. Los casts datetime de miembros/roles y las fechas Eloquent se serializan como ISO 8601 UTC. Query Grammar transforma BSON UTCDateTime a Carbon y renombra _id a id si no existe id; si ambos existen no elimina _id. PeriodoOrganizacion no castea fecha_inicio/fecha_fin; sus escrituras actuales usan Y-m-d. RolOrganizacion no agrega vigente globalmente: index lo añade explícitamente. Dashboard devuelve cajaDisponible siempre null (`type: "null"`), gestion null sin permiso de edición y proximoEvento null si no hay evento (ambos con `anyOf` objeto/null). Todos los campos anulables del fragmento usan la misma representación 3.1, también en query, requests, Identidad y modelos/proyecciones.

## Validación, límites y estado

Se incluyen cuerpos application/json con required reales, nullable, máximos, mínimos, enum, UUID y ObjectId; las restricciones de hoy y relaciones entre fechas se expresan en description/x-laravel-rule porque OpenAPI no evalúa fechas relativas. No se inventó regex de matrícula ni min:3 para editar nombre. PUT de cargo exige los tres campos de POST; no es PATCH. Los extras de request se ignoran por validate(), no hay rechazo de claves desconocidas. Middleware web puede convertir strings vacíos en null y recortar strings antes de validar; las restricciones describen los datos validados, sin exigir que el cliente envíe campos opcionales.

Solo gestión index valida buscar (120), estado (activa/suspendida/configurando) y page (integer >=1, sin máximo validado); paginate(12) es fijo. Los demás GET del alcance no tienen filtros query validados. Dashboard limita las últimas solicitudes a 5 antes de filtrar convocatorias inexistentes. Los ID de ruta tienen regex [a-fA-F0-9]{24}; no coincidir produce 404. Crear organización y consultar titular usan throttle:20,1; no se generalizó 429 a rutas sin ese throttle explícito.

Seleccionar devuelve 200; crear miembro/cargo y organización devuelven 201; editar/bajar/retirar/completar/cambiar estado devuelven 200. No se inventaron respuestas 201 en GET ni DELETE 204. Repetir un alta idempotente conserva 201; completar un alta ya completada produce 409. Las claves de idempotencia están en JSON (clave_alta/clave_cambio), no en una cabecera inventada. `completar` no requiere requestBody.

Asignar cargo puede devolver 422 con message+errors por ValidationException o solo message cuando validarCargo aborta por periodo cancelado. Se usa `anyOf` entre ValidationError y Error: el Error abierto del padre también acepta message+errors, por lo que `oneOf` sería ambiguo. `ResponsabilidadesComunidad::ejecutar` transforma timeout del bloqueo global en 422 errors.password; el bloqueo por organización capturado en modificar produce 409. Se documenta X-Organization-Id opcional en las seis mutaciones de organización protegidas: si se envía no selecciona contexto y debe coincidir con la sesión. Seleccionar y gestión están exentas de esa comparación.

Identidad devuelve 404 para RequestException HTTP 404; otros errores HTTP remotos son 503. Conexión, configuración o payload inválido también son 503 con otro message. restrictions/benefits_eligible son obligatoriamente null; no hay decisión institucional de beneficios en este contrato. Éxito Identidad/dashboard/listado-detalle-titular de gestión fija Cache-Control private, no-store; no se atribuye esa cabecera a todos los éxitos.

## Transporte compartido

Rutas en web.php: auth, session.active, device.track y SeleccionarOrganizacion, además de middleware web. El padre define cookie y CSRF; el fragmento solo los referencia y no los duplica. Enviar Accept: application/json sin X-Inertia permite respuestas JSON de contraseña inicial/2FA (423), tipadas como CtxAccountLocked. Sesión revocada devuelve redirect 302 a login; contraseña/2FA pendientes también redirigen si no expectsJson o hay X-Inertia. El renderer del portal maneja excepciones como JSON, pero no convierte esos redirects/respuestas directas. Por ello no se promete JSON en todo camino de middleware. CSRF inválido en escrituras produce 419. Se conservan los 401/403/404/409/422/429/500/503 solo donde corresponden.

## Verificación documental

Solo análisis estático y validación local del fragmento: JSON, unicidad de operationId, referencias propias/padre, prefijo Ctx, rutas/métodos contra routes/comunidad.php y catálogo existente, restricciones/null explícito y fixtures positivos/negativos sin arrancar la aplicación. No se ejecutaron Pest, Docker, Laravel, Git, DB ni solicitudes HTTP; esta revisión no certifica ejecución ni datos históricos. Las referencias a componentes compartidos quedan pendientes de resolución al integrar con el padre. La revisión estructural local no sustituye un validador completo de OpenAPI.

## Fuentes inspeccionadas (SHA-256 al generar)

- `routes/comunidad.php`: `705fa62b7f6d8b7aebcfb76845d5d3a2a085e10690461f610395cef5070569f4`
- `routes/web.php`: `8dd2dfdec5f73f7f46de142aa017af2ccf4c8d9d788ad56d19f42ab23b13474d`
- `bootstrap/app.php`: `0428093bdeee159e09d691784707e4ff0602e18bfd46c913a6065fb570e6814c`
- `app/Http/Controllers/OrganizacionController.php`: `231f47813bed1817c39c2be5ef25d8add5a17ac54d6752153a5d630bd895ecbe`
- `app/Http/Controllers/GestionOrganizacionesController.php`: `47f5c365cc29c161715ad7f21ff2b4dc24153c5fc361ebfb8bd9a4a32125c52f`
- `app/Http/Controllers/DashboardController.php`: `4bbb5b8610cf8fa7d6d8c7caa01403a84b47a4ade5998537894f435bbf116f23`
- `app/Http/Controllers/IdentidadComunidadController.php`: `5f8e3189a5eaf5fd697baa0d9d9d47e66106064e3b4bdd87b9aad91ea9096f8e`
- `app/Http/Controllers/Controller.php`: `d093c43c6861c5b4e77dca9985f0824c3ff52f923ab441907647a11933eaf00f`
- `app/Models/Documento.php`: `6a5d9736e18b32538b7fcb1cc940edf47e49bae2a359b1e94e454c7996d3058f`
- `app/Models/Organizacion.php`: `6bc5ff1d20517db58e85e9421812d2bdf64d150f5a0bfad0be7c7aa665f645bd`
- `app/Models/MiembroOrganizacion.php`: `82400b78bb23d8b163b3b2aabcbe411b1a7eb5d0ba9e5f985ad65139e1809fc9`
- `app/Models/RolOrganizacion.php`: `5ced79e6226b2a658f4980b4d50cd592e56a2e51b2d8deb75c99293a2dd5d974`
- `app/Models/PeriodoOrganizacion.php`: `6764e96edf9389f3e9e56c73ada954251670edf7ae62010baa5674d7db176afe`
- `app/Models/SolicitudBeca.php`: `2fb96a285892958a199540ca1b27795e6840097bb3136a6ce32d4be6645c62dc`
- `app/Models/Evento.php`: `094cbcdf4c20932323e437bd43cff6ddc530db62aa6fce6386013dbc528db5b6`
- `app/Models/User.php`: `de14bb0d93d719d0ebc9ac44bb2ce8eead3a578d9cfba7f1b4bc6547fdba084f`
- `app/Services/IdentidadComunidadApi.php`: `ed92187d5defc158e772c956bc0bb070626d8b641c27a20840012f62dc94dfcf`
- `app/Services/RegistroOrganizaciones.php`: `3ef448463b3a12b0e50d9479295dd7ec4515ca9afda2c21fd33ce66e9fe112fa`
- `app/Services/PeriodosOrganizacion.php`: `36da1555646b936b1862f765f3c481e874553f4e127b508e2e397af967029a3f`
- `app/Services/ResponsabilidadesComunidad.php`: `a8ef97fe81352e32f61bb3ae71cd45d61591fd485e0f023e8a530874c9862d36`
- `app/Policies/OrganizacionPolicy.php`: `1b1453c2b71b11a2bb6a195d96f6f0710eda100e1b1447e6f0685e2de0d98b5e`
- `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php`: `a0e8106eca79da6caedd151cf915c0cf17418c3e3ec120ef36d19d61b5681edb`
- `vendor/laravel/framework/src/Illuminate/Collections/Arr.php`: `63a60e931ee3345fe83b2acdad4168ebbc370b99c07696a3bcf997dd662b5ece`
- `vendor/mongodb/laravel-mongodb/src/Eloquent/DocumentModel.php`: `6094f161b3a0f2e24b6bf0245c8c407fcfdd9c9b4edecb58e382efe21c47d267`
- `vendor/mongodb/laravel-mongodb/src/Query/Grammar.php`: `3ab76caf6e0ac42b6e42913fe6522aff2f6f3e36f40dd415cb26cc29c08cf9fc`
