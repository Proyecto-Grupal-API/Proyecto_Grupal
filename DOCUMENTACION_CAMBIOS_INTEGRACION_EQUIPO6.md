# Documentación Técnica de Cambios — Integración con Equipo 6 (Comunidad)

**Módulo:** Equipo 7 (Recompensas, Puntos, Gobernanza y Observabilidad)  
**Dominio integrado:** Equipo 6 (Comunidad, Organizaciones Estudiantiles y Actividades)  
**Fecha de implementación:** Noviembre 2026  
**Entorno:** Laravel 13 / PHP 8.3 / MongoDB 7  

---

## 1. Problemas Identificados y Solución

El Equipo 6 evaluó una versión inicial del receptor (commit `e48031e`) y detectó dos casos pendientes:
1. **Conflicto de contenido indistinguible:** Cuando se re-enviaba un `event_id` con contenido alterado, el receptor respondía `200` como duplicado en lugar de señalar un conflicto de datos.
2. **Origen de auditoría incorrecto:** Los registros de auditoría de Comunidad se guardaban erróneamente como `source_domain = 'services'`.
3. **Autenticación dependiente:** Las rutas estaban acopladas a Laravel Sanctum y requerían usuarios locales, generando dudas sobre la necesidad de interactuar con el servidor OAuth de Equipo 1.

---

## 2. Cambios Implementados

### 2.1 Control de Idempotencia y Conflicto 409
Tanto en `PlatformIngestController` (`/api/v1/plataforma/*`) como en `IntegrationController` (`/api/v1/integrations/*`), se implementó la persistencia de un `envelope_hash` (SHA-256 de los datos normalizados y ordenados del sobre):

- Si el `event_id` existe y el `envelope_hash` coincide: Retorna **`200 OK`** (`estado: duplicado` / `status: already_processed`).
- Si el `event_id` existe pero el `envelope_hash` difiere: Retorna **`409 Conflict`** con el mensaje *"El identificador ya existe con otro contenido"*.
- Nunca se sobreescriben registros ya persistidos.

### 2.2 Blindaje del `source_domain = 'community'`
- En `PlatformIngestController`: El valor de `source_domain` se extrae de `$request->attributes->get('platform_source')`, que a su vez se valida contra la firma criptográfica de la cabecera `X-Platform-Domain`. Cualquier valor que venga en el cuerpo JSON es ignorado o validado para coincidencia.
- En `IntegrationController`: Se añadieron los prefijos `community.` y `comunidad.` para que los eventos sean catalogados bajo el dominio `community` en MongoDB.

### 2.3 Desacoplamiento de Autenticación (HMAC Service-to-Service)
- Se liberaron las rutas `/api/v1/plataforma/*` del middleware `auth:sanctum`.
- Se configuró el middleware `AuthenticatePlatformSource` para validar firmas HMAC-SHA256 con el secreto específico `PLATFORM_COMMUNITY_SECRET`.
- Se actualizó `EnsureIntegrationAuthorized` para reconocer el dominio `community` y el secreto de plataforma.
- **Resultado:** Equipo 6 no requiere conectarse a Sanctum ni a los clientes OAuth de Equipo 1.

---

## 3. Archivos Involucrados

| Archivo | Rol |
|---|---|
| [`app/Http/Controllers/Api/PlatformIngestController.php`](backend_respaldo/app/Http/Controllers/Api/PlatformIngestController.php) | Ingesta de plataforma con detección de 409 y hashing de sobres |
| [`app/Http/Controllers/Api/IntegrationController.php`](backend_respaldo/app/Http/Controllers/Api/IntegrationController.php) | Endpoint unificado con soporte de 409 y dominio `community` |
| [`app/Http/Middleware/AuthenticatePlatformSource.php`](backend_respaldo/app/Http/Middleware/AuthenticatePlatformSource.php) | Middleware HMAC por dominio para rutas `/plataforma/*` |
| [`app/Http/Middleware/EnsureIntegrationAuthorized.php`](backend_respaldo/app/Http/Middleware/EnsureIntegrationAuthorized.php) | Middleware multi-equipo con mapeo de `community` |
| [`config/platform.php`](backend_respaldo/config/platform.php) | Configuración de secretos con valores por defecto seguros |
| `backend_respaldo/.env` y `.env.example` | Variables de entorno `PLATFORM_*` |
| [`tests/Feature/Team6IntegrationApiTest.php`](backend_respaldo/tests/Feature/Team6IntegrationApiTest.php) | Suite de pruebas automatizadas para E6 |
| [`tests/Feature/PlatformIngestApiTest.php`](backend_respaldo/tests/Feature/PlatformIngestApiTest.php) | Pruebas de integración de plataforma |
| [`REPORTE_Y_CONTRATO_INTEGRACION_EQUIPO6.md`](REPORTE_Y_CONTRATO_INTEGRACION_EQUIPO6.md) | Contrato formal para Equipo 6 |

