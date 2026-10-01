# Integración de Comunidad con Equipo 7

Fecha: 1 de octubre de 2026. Implementación local en `integracion/equipo1-comunidad`, carpeta `/home/daniel/campus_digital_integracion_e1`, entorno alterno del puerto 8086.

## Estado de los requerimientos

| Requerimiento | Estado |
| --- | --- |
| Resumen de asociación/Consejo | Implementado y probado por HTTP en el entorno alterno. |
| Recepción de lotes de notificaciones desde Equipo 7 | Pendiente. La bandeja y las campañas locales existen, pero aún no hay un POST de integración para Rewards. |
| Publicación de eventos de Comunidad hacia Equipo 7 | Pendiente. Existe auditoría local; falta conectar una entrega recuperable con el receptor del equipo 7. |

Referencia: solicitud `Solicitud_E7_a_Equipo6_Asociaciones.md` recibida del equipo 7. La comunicación entre módulos será por API; el módulo consumidor no lee colecciones de Comunidad.

## Resumen de organizaciones

```text
GET /api/v1/organizations/{organizationId}/summary
Authorization: Bearer <token de servicio>
```

Base local desde la laptop: `http://127.0.0.1:8086`. Dentro de la red Docker de esta integración: `http://app`. El puerto 8086 solo está publicado en la interfaz local; esto todavía no es un despliegue accesible desde otras laptops.

El parámetro es el identificador existente de la organización, entregado como cadena. En esta versión corresponde al ID MongoDB de 24 caracteres hexadecimales. El consumidor debe tratarlo como una referencia opaca; no usar el nombre, slug o matrícula como sustituto.

Respuesta 200 de ejemplo:

```json
{
  "id": "0123456789abcdef01234567",
  "type": "association",
  "name": "Asociación de Sistemas",
  "status": "active"
}
```

El identificador del ejemplo debe sustituirse por uno real de la copia correspondiente.

| Campo | Contrato |
| --- | --- |
| `id` | Identificador de la organización como texto. |
| `type` | `association` o `council`. Traduce los tipos locales `asociacion` y `consejo`. |
| `name` | Nombre actual de la organización. |
| `status` | `active`, `suspended` o `configuring`. Traduce los estados locales `activa`, `suspendida` y `configurando`. |

Una organización suspendida o aún en configuración responde **200 con su estado real**. El receptor debe considerar únicamente `active` como elegible por estado para patrocinar. Un 404 significa que el identificador es inválido o que la organización no existe.

La respuesta contiene exclusivamente esos cuatro campos. No expone miembros, titulares, correos, teléfonos, claves de alta, motivos de suspensión ni historial. El GET no modifica documentos. `Cache-Control: private, no-store` evita conservar como autorización una respuesta anterior; se consulta el estado vigente al realizar la operación que lo necesita.

Esta consulta confirma existencia y estado, no facultades del operador, fondos disponibles, aprobación de configuración ni autorización financiera. Equipo 7 valida las reglas y permisos de su campaña; Wallet valida las operaciones financieras. La validación de estado no reserva recursos ni impide un cambio posterior de estado.

## Autenticación y permiso

Se reutiliza la autenticación OAuth de servicios integrada desde Equipo 1. Permiso requerido:

```text
community:organizations:read
```

Un token de sesión del navegador o un token que solo tenga `students:read` no permite consultar este resumen. El permiso es de lectura y no concede administración de organizaciones ni acceso a expedientes.

Para provisionar un cliente exclusivo en una copia autorizada, un responsable ejecuta desde la carpeta de integración:

```bash
docker compose -f compose.integracion.yaml exec -T --user sail app php artisan oauth:client rewards --scope=community:organizations:read
```

El comando crea el cliente y muestra una vez su identificador y secreto. Guardarlos en la configuración privada del servidor consumidor; no incorporarlos al repositorio ni al frontend. Este trabajo no creó credenciales permanentes para terceros ni modificó los permisos del cliente existente de Comunidad.

Solicitud para obtener el token:

```text
POST /api/oauth/token
Content-Type: application/json
```

```json
{
  "grant_type": "client_credentials",
  "client_id": "<cliente provisionado>",
  "client_secret": "<secreto privado>",
  "scope": "community:organizations:read"
}
```

Se usa `access_token` de la respuesta como Bearer al consultar el resumen. Deben respetarse `expires_in` y los errores del emisor. La configuración OAuth se mantiene bajo el mecanismo de Identidad ya integrado.

La validación de servicios comprueba tanto el permiso del token como que el cliente siga activo y conserve el permiso requerido. Desactivar o borrar al cliente invalida su acceso con tokens anteriores; retirar el scope requerido devuelve 403. Esta comprobación también se aplica a los demás endpoints que utilizan `oauth.service`.

## Errores y límites

| HTTP | Significado |
| --- | --- |
| 200 | Organización encontrada; comprobar su `status`. |
| 401 | Falta token, es inválido/expirado o su cliente ya no está activo. |
| 403 | El token o el cliente no tienen el permiso requerido. |
| 404 | Organización inexistente o identificador inválido. |
| 405 | Método no permitido. El resumen es GET/HEAD. |
| 429 | Límite de consultas; respetar `Retry-After`. |
| 5xx | Fallo del servicio o datos incompatibles; no asumir patrocinador válido. |

Los errores de esta ruta se devuelven en JSON incluso sin `Accept: application/json`. Se limita a 120 consultas por minuto por IP, con un contador propio separado de OAuth. El consumidor no debe deducir existencia/estado de una respuesta de error.

## Próximos bloques

1. Recepción de notificaciones con autenticación de servicio, plantillas y destinos internos acordados, audiencia autorizada, idempotencia durable y consulta del estado del lote. Diferenciar avisos promocionales de notificaciones sobre un canje propio al aplicar preferencias.
2. Publicación recuperable de hechos de Comunidad, identificados y versionados, con reintentos y deduplicación acordados con el receptor del equipo 7.

Para los eventos: una campaña de comunicación procesada no equivale a una campaña de puntos activada; un dictamen de beca aprobado no acredita entrega financiera o de un servicio. Los eventos agregados de auditoría y los hechos individuales usados para acreditar puntos requieren contratos distintos.

No se habilitaron notificaciones de Rewards ni publicación de esos eventos con este bloque. Las campañas de puntos, sus aprobaciones versionadas, recompensas y canjes siguen perteneciendo al equipo 7.

## Validación de este bloque

- Pruebas dirigidas: 49 aprobadas, 414 aserciones; cubren el resumen, OAuth, APIs de Identidad y gestión de organizaciones.
- Suite general: 367 pruebas aprobadas y 16 de becas bloqueadas por el directorio de archivos simulados, heredado con propietario root. Se reparó únicamente `storage/framework/testing` de esta integración; al repetir BecasTest sus 16 pruebas aprobaron (195 aserciones). Los 383 casos cuentan con resultado aprobatorio entre la suite general y la repetición del bloque afectado. Ejecutar siempre las pruebas con `--user sail`.
- HTTP real: consulta de organización activa, Consejo suspendido, referencia inexistente y revocación de un cliente con token previamente emitido. El cliente y la organización temporales se eliminaron al terminar.
- Registros locales ignorados por Git: `storage/logs/e7-organizaciones-tests.log`, `storage/logs/e7-organizaciones-http.log` y `storage/logs/e7-organizaciones-suite.log` y `storage/logs/e7-organizaciones-becas-recheck.log`.
- Las pruebas usan únicamente `campus_digital_testing` en el MongoDB aislado. No ejecutar suites simultáneas contra esta misma base.
