# Avisos individuales de Servicios al estudiante → Comunidad

Implementación local de REQ-M5-E6-001, 9 de octubre de 2026. [OpenAPI 3.1](comunidad-notificaciones.openapi.json). La API pertenece al módulo 6.8; no modifica campañas ni envía correos, SMS o push.

## Uso

Base `/api/v1/comunidad`. Obtener un token en `POST /api/oauth/token` con `grant_type=client_credentials`, `client_id`, `client_secret` y `scope`. Identidad otorga `notifications:send` (enviar/retirar) y `notifications:read` (consultar). La API comprueba también que el cliente siga activo y conserve el permiso. Límite compartido: 120 solicitudes por minuto por cliente. Respuestas sin caché y encabezado `X-Correlation-Id`.

| Operación | Ruta | Resultado |
|---|---|---|
| Enviar | POST `/notificaciones`, encabezado `Idempotency-Key` | 201 primer recibo, 200 reintento idéntico |
| Consultar | GET `/notificaciones?clave_idempotencia=...` | El recibo original, o 404 si este cliente nunca envió esa clave |
| Retirar | POST `/notificaciones/{id}/retiro`, encabezado `Idempotency-Key`, cuerpo `{"motivo":"Reserva cancelada"}` | `{"notificacion_id":"...","estado":"retirada"}` |

Ejemplo de envío:

```json
{
  "destinatario_id": "66a123456789abcdef123456",
  "tipo": "orden_lista",
  "asunto": "Tu impresión está lista",
  "texto": "Recoge tu orden en Servicios al estudiante.",
  "accion": {
    "destino": "mis_ordenes",
    "id": "66a123456789abcdef123457"
  },
  "importancia": "normal"
}
```

Recibos de envío:

```json
{"notificacion_id":"66a123456789abcdef123458","estado":"entregada","motivo":null}
{"notificacion_id":"66a123456789abcdef123459","estado":"omitida","motivo":"preferencia_silenciada"}
```

Ejemplo de rechazo (HTTP 409, misma clave con contenido diferente):

```json
{
  "codigo": "clave_en_conflicto",
  "mensaje": "La clave ya se usó con un contenido diferente.",
  "correlacion_id": "d91e3928-6c84-4b0d-9ee0-64d9a0b246af"
}
```

Con la misma clave y otro contenido: 409 `clave_en_conflicto`. Las fechas equivalentes con distinta zona se normalizan a UTC. Asunto y cuerpo no admiten HTML. La expiración usa ISO 8601 con zona; el aviso desaparece de la bandeja al vencer. Se conservan los recibos para que un reintento no lo vuelva a crear. La consulta devuelve el resultado original aun después de retirarlo: el retiro tiene su propio recibo y clave. Los reintentos no vuelven a evaluar preferencias ni la disponibilidad de Identidad.

401 `no_autenticado`, 403 `sin_permiso`, 404 `no_encontrada`, 422 `datos_invalidos`/`destino_invalido`/`destinatario_invalido`, 429 `limite_excedido`, 503 `identidad_no_disponible`. El cuerpo lleva `codigo`, `mensaje`, `correlacion_id`; validación añade `errores`. Un envío que falla antes de persistir no deja recibo y se puede reintentar. Cada cliente únicamente consulta y retira sus avisos.

## Tipos y destinos

| Tipo | `accion.destino` | Se puede silenciar |
|---|---|---|
| prestamo_por_vencer | mis_prestamos | Sí |
| prestamo_vencido | mis_prestamos | No |
| reserva_libro_disponible | mis_reservas_libros | No |
| multa_generada | mis_multas | No |
| locker_por_vencer | mi_locker | Sí |
| lista_espera_promovida | mis_reservas | No |
| recordatorio_reserva | mis_reservas | Sí |
| no_show_registrado | mis_reservas | No |
| renta_por_devolver | mis_rentas | Sí |
| orden_lista | mis_ordenes | Sí |
| ticket_actualizado | mi_ticket | Sí |

`accion.id` identifica el registro de Servicios. Comunidad genera la ruta interna y `?registro=...`. No se admiten URLs proporcionadas por el emisor ni destinos que no correspondan al tipo. Las pantallas aplican autorización al usuario; el enlace nunca concede acceso a registros ajenos. `importancia` puede ser `normal` o `alta`; no cambia la política obligatoria del tipo.

Los recordatorios antes del vencimiento son opcionales. Los préstamos ya vencidos y las multas son obligatorios. Esta precisión resuelve la diferencia entre la frase general «vencimientos no se silencian» y la tabla de tipos de la solicitud. La preferencia personal está en **Mi bandeja → Preferencias → Servicios al estudiante**. No afecta campañas de organizaciones ni elimina mensajes anteriores.

## Responsabilidades

Equipo 1 conserva identidades y clientes OAuth. El destinatario es su `User._id`, validado por HTTP mediante `students:read`; un estado académico inactivo no impide recibir una multa. Equipo 5 decide los disparadores y guarda su propia salida persistente; Equipo 6 conserva mensaje, recibo, lectura, archivo, preferencias y retiros. No se comparten colecciones entre E5 y E6.

`servicios:avisos` planifica los once tipos, entrega por HTTP y reintenta con espera creciente (60 segundos a una hora). Registra las respuestas y retira avisos que ya no aplican. Se ejecuta cada minuto en el contenedor scheduler. Un fallo HTTP nunca deshace la operación de Servicios. La revisión periódica recupera avisos aun si falló el primer guardado en la salida; la salida guarda el contenido original para cada clave. Los recordatorios usan 24 h, 3 días, 1 h y 2 h, respectivamente, como propuso E5; precisión del scheduler: un minuto.

## Verificación

`php artisan test --compact tests/Feature/AvisosServiciosTest.php tests/Feature/IntegracionServiciosComunidadTest.php`.

Las pruebas cubren pérdida de respuesta, conflictos, silenciamiento, avisos obligatorios, aislamiento por alumno/cliente, expiración, retiro conservando primera lectura, revocación, credenciales QR/NFC, becas y pagos pendientes. Las pruebas de dominio importadas de E5 mantienen adaptadores locales únicamente en sus fixtures; no son los bindings del sistema integrado.
