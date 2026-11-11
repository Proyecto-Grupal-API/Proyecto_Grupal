# Comunidad: contratos de servicios y Recompensas

Corte: 11 de noviembre de 2026. Implementación consolidada en `MERGE_COMPLETO`. Estos contratos describen el comportamiento implementado, sin ampliar las facultades financieras de E1/E2.

## 1. Revocación de servicios entregados E6 → E5

**E6 decide la solicitud de revocación; E5 decide si el recurso o remanente sigue siendo cancelable.** Nunca se borra el consumo ni se interpreta una solicitud como cancelación confirmada.

Operación web con sesión/CSRF y contexto de organización:

`POST /api/v1/comunidad/portal/becas/solicitudes/{solicitudId}/revocacion-beneficio`

Cuerpo: `motivo` (texto de 10 a 1000 caracteres) y `confirmacion` aceptada, normalmente `true`. El backend obtiene al actor desde la sesión. Solo Presidencia vigente en la organización exacta puede actuar; nunca sobre una solicitud propia. Requiere dictamen aprobado y recibo E5 válido de locker/impresiones. Los bonos BECA, dinero y servicios no entregados quedan fuera de esta operación.

| Resultado | Código | Efecto |
|---|---:|---|
| Intención persistida | 202 | `estado=revocacion_pendiente`; E5 todavía debe confirmarla |
| Repetición ya resuelta | 200 | Devuelve el estado conocido; no crea otra intención |
| Actor sin autoridad o beneficiario propio | 403 | No cambia el beneficio |
| Recurso/estado incompatible o motivo diferente para la misma intención | 409 | No reemplaza el contenido previo |
| Cuerpo inválido | 422 | Errores de validación de Laravel |

La respuesta incluye `estado` y `message`. El detalle de la solicitud conserva `beneficio.revocacion`, `recibo_servicios`, `recibo_cancelacion`, `estado`, `error_integracion` y `puede_revocar_beneficio`. La interfaz distingue solicitud pendiente, confirmación y revisión requerida.

`comunidad:entregar-beneficios` procesa tanto entregas como revocaciones pendientes. Usa el lock local de la misma entrega. El worker consulta primero:

`GET /api/v1/servicios/asignaciones?clave_idempotencia=beca:{solicitudId}`

Solo ante una asignación inequívoca activa prepara:

`POST /api/v1/servicios/asignaciones/{asignacionId}/cancelacion`

OAuth: cliente existente de beneficios, scopes `services:benefits:read` y `services:benefits:write`. Clave fija `beca:{solicitudId}:cancelacion`, también enviada como `referencia_origen`, más el motivo. E5 limita el texto a 500: si el motivo original es mayor, E6 conserva íntegro el original y envía un resumen con su huella SHA-256.

El recibo debe coincidir en solicitud, beneficiario, organización, convocatoria, folio, recurso, cantidad y vigencia. Se comprueba el monto de páginas liberadas contra las consumidas; no se reemplaza el recibo de entrega. Si la respuesta se pierde, la siguiente consulta por la clave original recupera la cancelación antes de volver a enviar. Consulta vacía, recibo ambiguo o transporte fallido conservan `revocacion_pendiente` y backoff. Los rechazos conocidos de E5 (`no_cancelable`, `ya_cancelada`, `conflicto_idempotencia`) pasan a `revision_requerida`; se requiere revisión humana y se conserva el historial.

No se revalida una autoridad humana vencida para generar nuevas intenciones: el worker únicamente recupera/ejecuta la intención específica registrada por el backend. No tiene un permiso general para revocar otros beneficios.

## 2. Avisos transaccionales E7 → bandeja E6

Se reutiliza `comunidad-notificaciones.openapi.json` versión 1.2.0:

- `GET /api/v1/comunidad/notificaciones?clave_idempotencia=...`, scope `notifications:read`.
- `POST /api/v1/comunidad/notificaciones`, scope `notifications:send` e `Idempotency-Key`.

E7 usa un cliente exclusivo configurado en `platform.notices.client_id`. Compartir ese cliente con Finanzas se rechaza como configuración ambigua. El catálogo de cada emisor lo determina el servidor, no un campo libre del navegador.

| Hecho persistido de E7 | Tipo | Destino privado |
|---|---|---|
| Acreditación positiva de PointsLedger | `puntos_acreditados` | `/recompensas/alumno/actividad?movimiento={ledgerId}` |
| Evento de canje confirmado | `canje_confirmado` | `/recompensas/alumno/canjes?canje={redemptionId}` |
| Evento de canje entregado | `canje_entregado` | Igual, al canje exacto |
| Evento de canje cancelado | `canje_cancelado` | Igual, al canje exacto |

IDs MongoDB canónicos de 24 caracteres hexadecimales. E7 solo lee sus hechos; no acredita puntos ni canjea recursos desde el emisor. Clave: `platform-community:{tipo}:{sourceEventId}`. Las acciones se construyen mediante un catálogo fijo, sin URL libre. `movement_id` en `GET /api/v1/recompensas/points/my-history` filtra antes de paginar y siempre por el estudiante autenticado. La consulta de un canje también verifica propietario.

La outbox `platform_community_notices` inserta payload inmutable, índice único y entregas con lease/CAS. Consulta GET antes del POST, incluido el primer intento. Solo un 404 explícito `codigo=no_encontrada` permite enviar; 401, 403, error HTML, contenido o clave incompatibles permanecen pendientes. Se valida acuse 200/201, ID real y estado entregada/omitida. Los errores guardan códigos acotados, no credenciales ni cuerpos del transporte.

Los avisos de hechos transaccionales se conservan en la bandeja. Las preferencias existentes de organización/servicio siguen aplicándose a las campañas/recordatorios opcionales. Las preferencias de correo/push/SMS de Identidad no constituyen una baja de notificaciones internas obligatorias ni se asume un canal de correo configurado.

Comando: `plataforma:entregar-avisos-comunidad --limit=30`. `--limit`: entero entre 1 y 100; hay un tope finito de lectura por fuente e índices de escaneo. Cada ronda vuelve a revisar IDs para recuperar hechos reparados sin duplicarlos. Activación y corte temporal obligatorios:

- `PLATFORM_COMMUNITY_NOTICES_ENABLED=false` por defecto.
- `PLATFORM_COMMUNITY_NOTICES_URL`, cliente/secret privados y `PLATFORM_COMMUNITY_NOTICES_SINCE` ISO 8601 con zona.

## 3. Agregados E6 → E7

Se reutiliza `POST /api/v1/plataforma/eventos`, dominio `community` y la firma HMAC existente. No hay lecturas directas entre bases de módulos.

Headers: `X-Platform-Domain=community`, `X-Platform-Timestamp`, `X-Platform-Signature`. Se firma `timestamp|POST|/api/v1/plataforma/eventos|sha256(cuerpo JSON exacto)` con la clave privada existente. Los reintentos conservan cuerpo e ID; el timestamp y su firma se renuevan. Se acepta solo acuse 200/202 con el mismo `event_id` y `estado=aceptado` o `duplicado`.

Sobre común: `event_id` UUIDv5 determinista, `event_name`, `source_domain=community`, `aggregate_id`, `occurred_at` ISO 8601 y `payload`.

| Evento | Payload permitido |
|---|---|
| `community.report.published.v1` | `organization_id`, `report_id`, `version=1`, `period={start,end}`, `generated_at`, `metrics` |
| `community.report.withdrawn.v1` | Mismo esquema del reporte; evento independiente de retiro |
| `community.survey.closed.v1` | `organization_id`, `consultation_id`, `revision`, `eligible_count`, `participation_count`, `closed_at` |
| `community.election.closed.v1` | Mismo esquema de participación |

`metrics` acepta únicamente las 32 claves de MetricasComunidad del emisor, enteros no negativos y unidad coherente: cantidades o centavos MXN. No incluye títulos, motivos, nombres, padrones, candidatos, respuestas, votos, documentos ni IDs de personas. Solo salen reportes publicados/retirados y consultas cerradas, después del corte y nunca futuras. Se preserva el snapshot original en outbox; el retiro no lo sobrescribe.

Outbox `community_analytics_deliveries`: ID único, intentos, lease, backoff y recibo. `comunidad:publicar-analitica --limit=30` encola/envía hasta ese límite. La lectura recorre fuentes propias a escala del curso; un despliegue mayor necesitaría un cursor persistente para acotar también el escaneo total. No hay transacción de negocio abierta alrededor de HTTP.

Consulta E7:

`GET /api/v1/recompensas/analytics/community`

Sesión autenticada y permiso `analytics.view` del middleware existente. Filtros opcionales: `organization_id` hexadecimal de 24 caracteres, `days` 1–366 (30 por defecto), `page` 1–2147483647 y `per_page` 1–100 (10 por defecto). Valores incompatibles producen 422, estudiante sin permiso 403, no autenticado 401, otros métodos 405.

Respuesta: `linked`, `window`, `stats`, `metric_units` y `reports` paginados. Sin eventos válidos, `linked=false` y `stats=null`; no se inventan ceros como si hubiera conexión. Solo se consideran eventos del dominio, nombre, marcador de ingestión y esquema válidos. Los IDs/fechas se comparan; se toleran los tipos de fecha persistidos por el driver Mongo oficial, no cadenas arbitrarias en bruto.

Se aplica el estado más reciente por organización/reporte y la mayor revisión válida por proceso. Un retiro prevalece en empate temporal. La ventana usa la fecha del hecho; los retiros posteriores siguen ocultando el reporte. `stats` cuenta reportes vigentes y consultas cerradas de la ventana. **No suma valores entre reportes con periodos potencialmente solapados.** Las entregas históricas de beneficios se siguen contabilizando después de una revocación si hubo recibo original; no representan beneficios activos.

Bandera `PLATFORM_COMMUNITY_ANALYTICS_ENABLED=false` y corte `PLATFORM_COMMUNITY_ANALYTICS_SINCE` ISO con zona por defecto sin configurar. Se reutiliza `PLATFORM_DELIVERY_URL` y la clave de ingestión del dominio Community. Ambas tareas se programan cada minuto, sin solapamiento, solo con sus banderas habilitadas.

## 4. Verificación y estado local

Índices versionados en las dos migraciones del 11 de noviembre. Casos focalizados y recibos de aceptación en `evidence/comunidad-90-2026-11-11/`. La validación usa usuarios nuevos y bases aisladas, sin probar contraseñas existentes.

La activación autorizada de http://127.0.0.1:8086 tiene corte 2026-11-11T10:25:08Z; el primer escaneo quedó vacío y no modificó roles, usuarios, beneficios ni puntos. Un clon de Git conserva las banderas apagadas y requiere sus propias credenciales/configuración. No publicar `.env` ni secretos como parte de una solicitud entre equipos.
