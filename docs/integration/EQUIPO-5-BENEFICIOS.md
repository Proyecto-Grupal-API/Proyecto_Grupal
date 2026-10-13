# Equipo 5 → Equipo 6: beneficios de servicio becados

Respuesta a **REQ-M6-E5-001**. Contrato técnico completo, con ejemplos de éxito y de rechazo: [equipo5-beneficios-servicio.openapi.yaml](equipo5-beneficios-servicio.openapi.yaml). Se puede importar directo en Postman o Insomnia.

## Alcance acordado

| Beneficio     | Unidad           | Qué hace el Equipo 5                                                                               |
| ------------- | ---------------- | -------------------------------------------------------------------------------------------------- |
| `locker`      | 1 por periodo    | Asigna un locker libre del periodo de lockers activo. Hay un solo tamaño; el edificio es opcional. |
| `impresiones` | páginas (1–2000) | Abre un saldo que el alumno consume al pagar órdenes de impresión (módulo 5.8).                    |

`comidas` y `transporte` **no** pertenecen al Equipo 5. Responden `422 beneficio_no_soportado`.

## Cómo conectarse

Es servidor a servidor por HTTP con token Bearer, igual que Comunidad ↔ Identidad.

1. El Equipo 1 registra un cliente OAuth para Comunidad con los scopes `services:benefits:read` y `services:benefits:write`.
2. Comunidad obtiene el token con `POST /api/oauth/token` (`grant_type=client_credentials`).
3. Comunidad llama a `/api/v1/servicios/...` con `Authorization: Bearer {token}`.

En la rama `Equipo-5` (todavía sin el OAuth del Equipo 1) el alias `oauth.service` valida un token local definido en `.env`:

```dotenv
STUDENT_SERVICES_API_CLIENT_ID=equipo6-comunidad
STUDENT_SERVICES_API_TOKEN=un-token-largo-solo-para-desarrollo
STUDENT_SERVICES_API_SCOPES="services:benefits:read services:benefits:write"
```

**Al integrar con la app del Equipo 1:** el alias `oauth.service` debe apuntar a su `ValidateServiceToken` (en `bootstrap/app.php`). Las rutas no cambian, y este middleware local deja de usarse. El `client_id` del token separa las asignaciones de cada cliente.

## Flujo recomendado para Comunidad

`BeneficiosBeca::preparar()` ya genera casi todo el contrato. El cuerpo se puede mandar tal cual:

```http
POST /api/v1/servicios/asignaciones
Authorization: Bearer {token}
Idempotency-Key: beca:{solicitud_id}
Content-Type: application/json

{ "beneficiario_id": "...", "organizacion_id": "...", "convocatoria_id": "...",
  "solicitud_id": "...", "folio": "BEC-2026-0042", "tipo": "impresiones",
  "cantidad": 100, "vigencia_inicio": "2026-10-12T06:00:00+00:00",
  "vigencia_fin_exclusiva": "2026-12-20T06:00:00+00:00" }
```

| Respuesta                      | Qué hacer en Comunidad                                                                                                                           |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| `201`                          | Guardar `asignacion_id` y `estado`.                                                                                                              |
| `200` con `meta.repetida=true` | Es la misma asignación; no se creó otra.                                                                                                         |
| `409` / `422`                  | Rechazo explícito con `codigo`. No modifica el dictamen. `sin_disponibilidad` y `sin_periodo` se pueden reintentar más tarde con la misma clave. |
| Timeout o `5xx`                | Resultado desconocido. Consultar `GET /asignaciones?clave_idempotencia=beca:{solicitud_id}` antes de reintentar.                                 |

**Estados que devuelve la consulta:**

- **locker:** `asignado` → `entregado` (primer acceso autorizado en la validación de servicios 5.11) → `finalizado`, o `cancelado`.
- **impresiones:** `asignado` → `en_uso` → `consumido` o `vencido`, o `cancelado`.

## Cancelación

`POST /asignaciones/{id}/cancelacion` lleva `Idempotency-Key` (una clave distinta a la de creación, por ejemplo `cancelacion:{solicitud_id}`), `motivo` y `referencia_origen`.

- **Locker:** libera el locker si la asignación sigue activa. Si ya terminó, responde `409 no_cancelable`.
- **Impresiones:** libera solo el saldo no consumido. Lo consumido se conserva como evidencia y no se revierte.
- Repetir la cancelación con la misma clave devuelve la misma `cancelacion_id`. Con otra clave responde `409 ya_cancelada`.

## Garantías y límites

- **Idempotencia:** hay un índice único por cliente y clave, así que ni dos peticiones simultáneas con la misma clave crean dos asignaciones. Si la misma clave llega con otro contenido, la respuesta es `409 conflicto_idempotencia`.
- **Último locker:** el apartado es atómico (`available → occupied` en una sola operación). Si otra petición toma el locker primero, se intenta con el siguiente; si ya no hay, la respuesta es `409 sin_disponibilidad`.
- **Elegibilidad:** el beneficiario es el `User._id`. Un alumno ya con locker en el periodo recibe `409 beneficiario_con_locker`.
- **Correlación:** cada respuesta trae `X-Correlation-Id`, y si el cliente envía uno se respeta.
- **Límite de uso:** 120 solicitudes por minuto.

## Datos de prueba (rama Equipo-5)

```bash
php artisan migrate        # crea los índices de benefit_assignments
php artisan db:seed        # periodos y lockers (LockersSeeder) + demás servicios
```

Para probar el consumo de impresiones:

1. Asignar `impresiones` por API a un alumno.
2. Con ese alumno, crear una orden de impresión en `/servicios-estudiante/servicios-impresiones`.
3. Pagarla con el botón «Usar beca».

## Pendiente

- Cambiar `LocalStudentDirectory` por la API de estatus del Equipo 1 (`GET /api/v1/students/{id}/status`).
- Notificaciones de cambio de estado hacia Comunidad. Por ahora la consulta es la fuente de verdad.
