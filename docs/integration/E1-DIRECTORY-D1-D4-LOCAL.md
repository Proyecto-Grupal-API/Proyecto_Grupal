# Directorio E1 → E6 · D1–D4 · LOCAL ONLY

**Implementación local no publicada, no certificada para consumo externo.** No se
ha habilitado ningún cliente, scope ni infraestructura. La fuente es la solicitud
para acuerdo de E6 del 17-10-2026, archivo
`SOLICITUD_EQUIPO6_A_EQUIPO1_DIRECTORIO_Y_AUTORIZACION_2026-10-17.md`, presente en
MERGE-1-3-4-5-6 (`f609bd3b23284b0f4f043fc63c577adf2e89f0e1`). No es aceptación
bilateral ni anuncio de disponibilidad. La entrega E1 será integral, no parcial.

## Frontera y autenticación

HTTP S2S, OAuth2 client_credentials existente. JWT firmado, issuer/audience/exp
verificados por ValidateServiceToken. Las dos rutas optan explícitamente por el
perfil `oauth.service:,directory`: exige `exp` presente, entero y futuro, además
de las verificaciones existentes de firma, emisor y audiencia. El emisor E1 ya
genera ese formato. Los demás consumidores del middleware no cambian de perfil
ni reciben este endurecimiento implícitamente; no se modifica el emisor OAuth2.
DirectoryAccess exige scopes del token Y
revalida cada grant contra cliente persistido activo mediante AuthorizeIdentityService.
No se modifica ese middleware compartido ni el contrato académico students:read.

Los scopes nuevos son identificadores internos, sin concesiones:

| Operación/proyección | Scope necesario |
|---|---|
| Resolver matrícula | identity:directory:resolve |
| identity en batch | identity:directory:read |
| account_condition en batch | identity:accounts:condition:read |
| include_enrollment=true en batch | adicional identity:directory:enrollment:read |

También se exige perfil cerrado por client_id, operación, finalidad, tipo de
contexto y proyección. `config/identity_directory.php` contiene clients=[]: deniega
por defecto. No hay acceso por admin, cargo E6, students:read o permiso institucional.
Finalidad no significa autorización por sí sola; debe estar en el perfil aprobado.

El token autentica al servicio, NO a un humano. E6 deberá acreditar sesión,
segundo factor aplicable y permisos organizacionales antes de llamar. El contexto
declarado no prueba titularidad, pertenencia ni autoridad; E1 no consulta datos E6
para acreditarlo. No se admiten actor_id, roles ni filtros libres en el cuerpo.
Si se exige acreditación humana directa por E1, falta un contrato específico.

## Entrada común

POST JSON, objeto raíz, sin parámetros de query ni propiedades desconocidas.
purpose: identificador de 1–64 caracteres `[a-z][a-z_]*` autorizado en el perfil.
context: únicamente {type,id}; type entre organization, event, scholarship_call,
committee, campaign, roster; además debe estar permitido para ese consumidor/finalidad.
id es referencia opaca no vacía, sin espacios/control ASCII y de hasta 100 bytes.
No se traduce a scope institucional ni financiero, ni se verifica contra E6.

Las referencias se leen del JSON original para no cambiar IDs mediante los
middleware globales de trimming. Solo la matrícula tiene normalización explícita.

## D1

`POST /api/v1/identity/directory/resolve-enrollment`

```json
{
  "enrollment_number": " abc-123 ",
  "purpose": "membership_management",
  "context": {"type": "organization", "id": "referencia-e6"}
}
```

Reutiliza StudentIdentityInput: trim + mayúsculas; máximo canónico 30 caracteres
alfanuméricos/guion, máximo 128 bytes de entrada cruda. Igualdad exacta indexada,
nunca búsqueda parcial ni por nombre. El identificador resuelto es User._id.

HTTP 200, por ejemplo:

```json
{
  "data": {
    "result": "resolved",
    "identity": {"user_id": "64a111111111111111111111", "display_name": "Persona sintética", "enrollment_number": "ABC-123"}
  },
  "meta": {"request_id": "UUID generado por E1", "api_version": "v1"}
}
```

Otros resultados 200: not_found o not_usable, ambos con identity=null. Baja lógica,
activación pendiente y cambio inicial obligatorio nunca revelan identidad en D1.
Ambigüedad exacta o perfil huérfano: 409; condición contradictoria: 503. No se elige
el primer resultado de dos coincidencias.

`enrollment_integrity_verified=false` bloquea D1 con 503 aun con un cliente permitido.
Antes de cambiarlo debe acreditarse, mediante una fase autorizada independiente,
unicidad e integridad de user_id/enrollment_number, normalización histórica y
ausencia de perfiles huérfanos. La unicidad literal no descarta variantes históricas
con diferente capitalización. La bandera es una atestación operativa, no una prueba
automática de datos ni permiso para migrarlos/repararlos.

## D2 y D3

`POST /api/v1/identity/directory/batch`

```json
{
  "user_ids": ["64a111111111111111111111", "referencia-ausente", "64a111111111111111111111"],
  "projection": ["identity", "account_condition"],
  "include_enrollment": false,
  "purpose": "historical_reference",
  "context": {"type": "organization", "id": "referencia-e6"}
}
```

user_ids es lista de 1–100 posiciones. Los IDs son cadenas opacas de hasta 100
bytes, sin blancos/control ASCII; no matrícula ni StudentProfile._id. No se hace
case-folding de IDs. Proyección por defecto: [identity]; include_enrollment por
defecto false. Las proyecciones son cerradas y no repetidas. La matrícula exige
identity y su scope adicional. Solicitar proyección sin permiso devuelve 403,
no se elimina silenciosamente.

data.items preserva cantidad y orden, con index y requested_user_id por posición.
Duplicados se consultan una vez y se reproducen en sus posiciones. Un ID inválido
produce invalid_id y requested_user_id=null; estructuras no se envían a MongoDB.

| result | Significado |
|---|---|
| found | Cuenta encontrada; no implica usable=true |
| not_found | No encontrada; con identity sola también oculta bajas |
| deleted | Baja conocida, exclusivamente con account_condition |
| invalid_id | Referencia individual inválida |
| unavailable | Datos inconsistentes; sin identidad ni condición positiva |

identity: {user_id,display_name}, y enrollment_number únicamente si se solicitó y
autorizó. Sin perfil, matrícula autorizada=null. Sin solicitud, campo omitido.
Las bajas siempre tienen identity=null, nunca nombre o matrícula anteriores.
Para cuentas no eliminadas, D2 puede mostrar identidad histórica aunque existan
restricciones iniciales: found no autoriza nuevas acciones. E6 debe pedir D3 para ello.

D3 usa projection=[account_condition], sin identity ni matrícula. Su DTO:
{state,usable,has_student_profile}. No devuelve estado académico, roles ni 2FA.

| state derivado, no persistido | usable |
|---|---|
| operational | true |
| activation_pending | false |
| initial_password_change_required | false |
| deleted | false; has_student_profile=null para minimizar información |

Baja prevalece. Ambos flags iniciales true o valores malformados: condición
indeterminada; no se inventa precedencia y el elemento queda unavailable. Los
flujos actuales de creación/reemisión establecen los flags como alternativas.
Ausencia histórica/null de flags conserva la semántica existente; booleanos y
enteros 0/1 se admiten, otras representaciones no habilitan la cuenta.

No hay suspensión/inactividad general canónica en User. academic_status no se
consulta ni se convierte en condición de cuenta. Una cuenta sin perfil existe
y puede ser operational. usable no comprueba contraseña, autenticación, sesión,
2FA, membresía, cargo, consentimiento, cupo ni elegibilidad. Es una observación
de restricciones conocidas de referencia; no una autorización ni garantía futura.

Un fallo general de MongoDB devuelve 503, no una lista falsa de inexistentes.
Errores individuales conservan los demás resultados: meta.partial=true para
invalid_id/unavailable, no por una ausencia conocida. No se utiliza HTTP 207.

Consultas constantes: un lote a users; otro a student_profiles solo si lo necesita
la proyección. Sin serialización de modelos ni N+1. Se detectan perfiles duplicados
y se limita la lectura incluso si falta un índice. No se añaden índices/migraciones.

## D4: cuotas, privacidad, auditoría y errores

Límites iniciales, NO rendimiento medido:
- 100 posiciones, 32 KiB de solicitud, 128 KiB de respuesta.
- 30 consultas autorizadas y validadas/60s por cliente y operación.
- 3000 referencias/60s por cliente, compartidas entre operaciones.
- Presupuesto de referencias/24h positivo y explícito por cliente, sin valor por defecto.

Las posiciones repetidas y consultas fallidas después de la admisión consumen cuota.
No se devuelve cuota por errores. Las ventanas conservan la semántica relativa
desde su primer consumo, no un calendario ni ventana deslizante estricta.
No se confía en IP ni jti: rotar tokens del mismo cliente no crea otra cuota.
Solicitudes rechazadas antes de admisión no consultan identidades; estos límites
no sustituyen protecciones de tráfico del servidor ni detectan por sí solos abuso.

### Cuotas MongoDB: implementadas localmente, no certificadas

DirectoryQuota delega exclusivamente en DirectoryQuotaStore. No usa caché,
Redis, ArrayStore, bloqueo con lease ni fallback. `quota_store_verified=false`,
`quota_deployment=null` y `quota_generation=null` impiden acceder al almacén.
El booleano no demuestra por sí solo acreditación. No se habilitan consumidores.

Conexión fija `mongodb`, colección técnica fija `identity_directory_quota_state`.
Un documento por despliegue/cliente; `_id` es SHA-256 del JSON [1,deployment,client_id].
Generation no cambia la clave: una generación distinta bloquea, no crea otro saldo.
Se almacena client_hash, no el token ni los IDs consultados. Esquema:

```text
_id, schema_version=1, deployment, generation, client_hash
limits: {requests:30, references_minute:3000, daily:presupuesto_explícito}
windows:
  requests_resolve, requests_batch, references_minute, references_day
  cada una: {used:entero, starts_at:BSON Date|null, ends_at:BSON Date|null}
last_result: null | {nonce, operation, references, at:BSON Date, decision}
```

`initialState()` únicamente construye arrays para preparación explícita y valores
esperados; no crea ni inserta nada. No hay migración, comando de arranque ni upsert.
El estado prístino es used=0 y ambas fechas null. Fechas incompletas, campos ausentes,
contadores negativos/fuera del límite/no enteros, recibos inválidos, límites distintos
de la configuración o generación incompatible impiden el consumo.

Una sola `findOneAndUpdate`, `upsert=false`, filtra identidad/esquema/generación,
límites y validez del estado. Su pipeline evalúa conjuntamente los tres presupuestos
afectados con `$$NOW`: modalidad, posiciones/minuto y posiciones/día. No hace una
lectura previa para decidir ni emplea un bloqueo externo. Comprueba used <= limit-cost
para evitar overflow; solo suma después de admitir. Cada ventana expira cuando
ends_at <= $$NOW, incluido el instante exacto. Al admitir se reinician únicamente
las ventanas afectadas vencidas; las vigentes y la otra modalidad se conservan.
Los periodos son 60.000/86.400.000 ms desde el consumo inicial.

El mismo comando escribe un recibo técnico, incluso cuando confirma agotamiento;
en ese caso NO incrementa ni reinicia presupuestos. Se pide el documento posterior
con `WriteConcern('majority',5000,true)` y maxTimeMS=5000. Se valida nonce, identidad,
generación, límites, ventanas, operación/coste y coherencia de la decisión. Estados:

| Interno | HTTP/efecto |
|---|---|
| admitted | Continúa; consumo confirmado por el contrato del comando |
| exhausted | 429 solo con recibo y presupuesto agotado coherentes; Retry-After |
| unavailable | 503: no coincidencia/documento, estado inválido o perfil incompleto |
| uncertain | 503: excepción/timeout o postimagen no confirmable; pudo consumir |

Una respuesta vacía NO es un 429. No hay reintento de aplicación ni devolución de
cuota ante resultado incierto. Un lote que cuesta más que todo el presupuesto diario
permanece denegado: esperar no hace admisible ese tamaño; debe reducirse o existir
un presupuesto aprobado diferente. Retry-After no promete que una solicitud idéntica
sea autorizable. No existe transacción conjunta con identidad, auditoría o HTTP.

Compatibilidad prevista: MongoDB 7; biblioteca instalada mongodb/mongodb 2.4.1
admite pipeline, documento posterior y WriteConcern. Se usa $expr, $cond, $type,
$regexMatch y $$NOW. Los tests puros comprueban construcción, no ejecución de servidor.
FindAndModify de la biblioteca llama Server::executeWriteCommand sin bucle propio.
Los reintentos nativos dependen de extensión/URI/topología: no se modificaron ni
se acreditaron. Antes de usarlo, verificar que cualquier retry conserva la identidad
de escritura del protocolo y no duplica consumo; si no puede acreditarse, bloquear
la habilitación y definir configuración de conexión aprobada. No reejecutar como
comando nuevo una operación de resultado desconocido.

### Preparación y recuperación pendientes (NO ejecutadas)

1. Acreditar destino, versión, PRIMARY, volumen persistente, journal, write concern,
   credenciales y aislamiento. Majority en un miembro NO es redundancia física.
2. Autorizar creación explícita de la colección y su índice único estándar _id.
   No se proponen índices TTL ni índices personalizados; TTL podría perder saldos.
3. Aprovisionar documentos mediante procedimiento separado aprobado, a partir de
   initialState, con despliegue/generación y presupuesto coincidentes con la política.
   No crear documentos al atender solicitudes. El proceso de consulta solo requiere
   find/update sobre esa colección, no createCollection/insert/drop/administración.
4. Verificar integridad/tipos, ausencia de documentos ajenos, permisos y respuestas
   de escritura; mantener quota_store_verified=false hasta su acreditación.
5. Pérdida/restauración incompleta: bloquear; nunca insertar automáticamente saldos
   a cero. Restaurar estado probado o aplicar cuarentena conservadora hasta cubrir
   el horizonte afectado (24h diario), con aprobación y generación operativa explícita.
   Una copia antigua coherente no puede detectarse siempre desde ese mismo almacén:
   se requiere evidencia externa de recuperación. Reiniciar Laravel no reinicia saldos.

No se ejecutó ninguno de estos pasos ni se conectó a MongoDB para acreditarlos.

La auditoría es obligatoria antes de entregar resultados, incluidos resultados
negativos. Reutiliza SecurityEvent, type=identity_directory_consulted. Solo registra
cliente, operación, finalidad aprobada, context_type, cantidad, resultado general
y request_id generado por E1. No context.id, matrículas, nombres, IDs consultados,
tokens, cuerpo, IP o información académica. E6 correlaciona su contexto con request_id.
Si falla la auditoría: 503 directory_audit_unavailable y no se entrega la respuesta.
La mera devolución de un modelo no se acepta como confirmación. El directorio
exige `exists`, `wasRecentlyCreated` e ID, y después relee desde SecurityEvent
el documento por su clave, comprobando tipo, severidad y metadatos completos
contra lo solicitado. Un ID asignado en memoria, una cancelación `saving/creating`
que devuelve false, una relectura ausente, inconsistente o fallida no liberan datos.
Es una lectura adicional de auditoría antes de la respuesta, no un segundo insert.
No cambia SecurityEvent::log para otros consumidores. Acreditar write concern y
preferencia de lectura en el preflight; una lectura atrasada puede causar 503,
no una autorización optimista. No es garantía de retención o durabilidad física
frente a pérdida del servidor, ni sustituye la política de almacenamiento.
No se emite un evento de dominio ni se transporta a E7.

Todas las respuestas: Cache-Control private,no-store. Sin caché intersolicitud
de identidad, condición o grants; sin ETag. No es un snapshot transaccional entre
dominios. La condición puede cambiar y E6 debe revalidar en operaciones sensibles.

| HTTP | Semántica |
|---|---|
| 200 | Consulta evaluada, incluidos resultados negativos/parciales |
| 401 | Token ausente/inválido/expirado |
| 403 | Scope/grant/cliente/finalidad/contexto/proyección no permitidos |
| 409 | Referencia D1 ambigua o inconsistente |
| 413/415/422 | Tamaño / formato JSON / entrada inválida |
| 429 | Cuota; Retry-After cuando la genera DirectoryQuota |
| 503 | Datos no acreditados, MongoDB, cuotas, condición o auditoría indisponibles |
| 500 | Error inesperado sanitizado |

Errores: {message,error_code,meta:{request_id,api_version}}. No contienen inputs,
SQL/queries, URI ni excepciones internas. DirectoryTransport protege el transporte;
bootstrap/app.php delimita además render/report al prefijo del directorio porque
el pipeline Laravel puede reportar antes del middleware exterior. No se reportan
excepciones crudas de estas rutas al logger predeterminado. Esto limita diagnóstico:
no afirmar monitoreo de errores operativo. No cambia errores de otros contratos.

## Perfil de consumidor: ejemplo estructural, NO habilitado

Cada clients[client_id] requiere daily_reference_limit y operaciones resolve/batch
con purposes[finalidad]={projections:[...],context_types:[...],enrollment:bool}.
La configuración entregada no incluye ejemplos activos ni credenciales. Scopes
y active se revalidan también desde oauth_service_clients. No otorgar grants ni
añadir consumidores sin aprobación de finalidad/proyección/custodia.

## Certificación pendiente

IdentityDirectoryTest es puro: normalización, condición, proyección, política,
reglas de cuotas y transporte sanitizado sin aplicación, MongoDB ni caché.
IdentityDirectoryApiTest está PREPARADO, NO EJECUTADO. Crea fixtures/clientes
sintéticos, índices y auditoría; prepara explícitamente la colección/documento de
cuotas de testing después de la guarda y conserva la limpieza heredada.
No constituye prueba de integración con E6 ni del rendimiento/concurrencia reales.

DirectoryServiceTokenTest es puro: utiliza ValidateServiceToken y el decodificador
JWT reales, claves sintéticas y un contenedor mínimo de configuración, sin
bootstrap Laravel, proveedores, dotenv, DB, caché ni escrituras. Incluye token
válido, sin exp, expirado, firma/emisor/audiencia incorrectos y entradas malformadas;
comprueba también que el perfil legado de otros consumidores no cambia.
IdentityDirectoryApiTest prepara cancelaciones de auditoría en saving/creating,
incluido ID en memoria, y fallos/inconsistencias en la relectura. No se han ejecutado
esas pruebas persistentes ni deben interpretarse como aprobadas.

DirectoryQuotaStoreTest es puro: doble controlado que evalúa las expresiones del
filtro/pipeline generado y simula respuestas/fallos. No demuestra atomicidad ni
durabilidad de MongoDB. DirectoryQuotaMongoTest está preparado, NO EJECUTADO:
prueba comando real, límites, aislamiento, instancias distintas, estado inválido
y ventanas independientes, con preparación técnica explícita posterior a la guarda.
Instancias distintas en un proceso no equivalen a un reinicio real de la aplicación.

Plan operativo pendiente, sujeto a nueva autorización:
- Concurrencia: varios procesos sobre un cliente y ventana no vencida, N solicitudes
  con coste fijo; admisiones <= todos los presupuestos y contadores exactamente
  iguales a consumos confirmados más consumos inciertos que efectivamente llegaron.
- Reinicio: agotar parcialmente, reiniciar solo procesos autorizados y comprobar
  continuidad; no reiniciar contenedores de otros entornos.
- Fallos: escritura rechazada, wtimeout, desconexión antes/después de respuesta;
  comprobar 503 sin datos, sin retry de aplicación ni rollback. Verificar por lectura
  el estado parcial, distinguir efectos de retry nativo y preservar incertidumbre.
- Recuperación: estado ausente y generación restaurada se bloquean sin upsert;
  una pérdida real/restauración se ensaya solo en almacén descartable autorizado.
No se incluyeron disparadores de subprocess, reinicio, cambio de privilegios o
fallos de red en la suite preparada; ese arnés requiere aprobación independiente.

Antes de cualquier ejecución persistente se exige autorización adicional y el
preflight E1MongoTestTarget: servidor/replica set/identidad/base campus_virtual_testing,
privilegios limitados, montajes y secretos aislados. Ese entorno aún no está
aprovisionado/acreditado. No reutilizar integración ni cambiar nombres para eludir
guardas. No ejecutar composer test/setup, migraciones, seeders ni suites generales.

Pendientes de pruebas: colisiones/normalización histórica e índices efectivos,
coste real de consultas y tamaños, carreras de cuotas con procesos distintos,
fallos de persistencia de auditoría, errores HTTP y regresión académica/QR/NFC.
Después: incorporación selectiva autorizada y consumo HTTP real por E6, sin
copiar modelos, relajar guards ni modificar consumidores durante esta etapa.
