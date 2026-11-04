# Respuesta de Equipo 7 a Equipo 6 — REQ-M6-E7-001 (Auditoría y Analítica)

**De:** Equipo 7 (Recompensas, Gobierno, Auditoría transversal y Analítica)
**Para:** Equipo 6 (Comunidad)
**Referencia:** REQ-M6-E7-001 (23-sep-2026) + reporte de pruebas sobre el receptor publicado (commit `e48031e` en su copia del proyecto)
**Fecha de esta respuesta:** 27-oct-2026

## 1. Resumen

Confirmamos los 2 problemas que reportaron sobre nuestro receptor y ya los corregimos en nuestro repositorio (Equipo 7). También resolvemos la duda de autenticación: **este endpoint no usa OAuth ni Sanctum**, usa firma por secreto compartido — mismo esquema que ya tienen implementado y agendado en el repo grupal (`comunidad:publicar-plataforma` / `CommunityPlatformDelivery`). Si ya prueban contra el grupal, **no necesitan cambiar nada de su lado** — este ajuste es nuestro.

## 2. Qué corregimos

### 2.1 Conflicto de contenido (antes: 200 duplicado para todo)
Ahora guardamos un hash (`envelope_hash`, SHA-256 del sobre completo con claves ordenadas) junto con cada evento/auditoría. Al recibir el mismo `event_id` otra vez:

- **Mismo contenido** (mismo hash) → `200 duplicado` (idempotente, como antes).
- **Contenido distinto** (hash distinto) → **`409 Conflict`**, con mensaje `"El identificador ya existe con otro contenido."` Nunca se sobrescribe el registro existente.

### 2.2 Origen de auditoría (antes: hardcodeado a `services`)
`source_domain` ya **no** se toma del campo que manda el llamante ni queda fijo. Se toma del dominio que quedó autenticado por la firma de la petición (ver sección 3). Si ustedes mandan con `X-Platform-Domain: community`, el registro queda con `source_domain = "community"`, siempre.

### 2.3 Autenticación (resuelve la pregunta sobre OAuth/E1)
Este endpoint **no valida con Sanctum ni con OAuth**. Usa un secreto compartido por dominio (`config/platform.php`), validado por firma HMAC-SHA256. No depende de Identidad ni de sus clientes OAuth — es independiente de ese flujo.

## 3. Contrato de autenticación

Cada petición a `/api/v1/plataforma/eventos` o `/api/v1/plataforma/auditoria` debe llevar:

| Header | Valor |
|---|---|
| `X-Platform-Domain` | `community` (su dominio; debe coincidir con lo que se les asigne) |
| `X-Platform-Timestamp` | Unix timestamp de 10 dígitos, ventana de tolerancia ±300s |
| `X-Platform-Signature` | `hmac_sha256(timestamp + "|" + METODO + "|" + "/api/v1/plataforma/..." + "|" + sha256(cuerpo_json), secreto_de_community)` |

El secreto (`PLATFORM_COMMUNITY_SECRET`, ≥20 caracteres) se acuerda y comparte **fuera de banda** (no por chat/commit) antes de activar el flujo en ambiente compartido.

Si ya corren `CommunityPlatformDelivery` contra el repo grupal, es exactamente esta misma firma — no hay nada nuevo que implementar de su parte.

## 4. Endpoints (sin cambios de ruta)

| Endpoint | Método | Código éxito | Código duplicado | Código conflicto |
|---|---|---|---|---|
| `/api/v1/plataforma/eventos` | POST | 202 | 200 | 409 |
| `/api/v1/plataforma/auditoria` | POST | 202 | 200 | 409 |

Otros códigos: `401` (firma inválida/ausente o secreto no coincide), `413` (cuerpo > 64KB), `422` (esquema inválido o payload con datos personales — rechazamos `name`, `nombre`, `email`, `correo`, `password`, `token`, `secret`, `documento`, `expediente`).

## 5. Lo que mantenemos sin cambios

- Rutas, nombres de campos y formas de respuesta: idénticas a la primera entrega (la que validaron con Equipo 5).
- Idempotencia por `event_id`.

## 6. Pendiente de acordar con nosotros

- No implementamos el contrato específico de su PDF (`/gobierno/auditoria/catalogo`, etc.) — adoptamos el receptor genérico ya probado con Equipo 5, extendido para su dominio. Avísennos si para su caso de uso necesitan sí o sí esas rutas separadas (catálogo de acciones admitidas, confirmación de recepción por `evento_id` vía `GET`, etc.) y lo evaluamos.
- Falta acordar y compartir el valor real de `PLATFORM_COMMUNITY_SECRET` entre ambos equipos antes de probar en un ambiente compartido.
- Indicadores de analítica (`/analitica/metricas` de su propuesta): aún no implementado de nuestro lado como ruta separada; por ahora viajarían como eventos genéricos por `/eventos`. Si necesitan algo más granular, lo discutimos.

## 7. Próximo paso sugerido

1. Acordar el secreto compartido para `community`.
2. Correr una prueba conjunta contra nuestro receptor (no el monolito grupal) con un evento real de su módulo.
3. Confirmar si el contrato genérico cubre su caso o si necesitan las rutas específicas de su PDF original.

---
*Equipo 7 — Campus Digital*
