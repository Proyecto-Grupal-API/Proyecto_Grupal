# Estado de Integraciones — Equipo 4

**Ultima actualizacion**: 2026-10-11
**Business ID**: BUS-CD-SOUV-001
**Contrato comun**: HMAC-SHA256

## Resumen ejecutivo

| Equipo | Nuestro lado | Lado de ellos | Estado |
|---|---|---|---|
| Eq.1 (Identidad) | Adaptado a OAuth2 INT-1B.5 + Opcion B multi-rol | API publicada | Cerrado (falta client_id/secret) |
| Eq.2 (Wallet) | Webhook receptor + auto-return | Sin emisor | Bloqueado en ellos |
| Eq.3 (Marketplace) | 8 endpoints + validacion PI | Solo mocks | Bloqueado en ellos |
| Eq.7 (Rewards) | 5 endpoints + expiry + outbox | Cero integracion | Bloqueado en ellos |

## Hallazgos de auditoria — atendidos

| # | Prioridad | Hallazgo | Resolucion |
|---|---|---|---|
| 1 | Alta | Indice unico de idempotencia no se crea solo | Comando team4:init-indexes idempotente |
| 2 | Media | e7:expire no corre en Docker | Servicio campus-eq4-scheduler con schedule:work |
| 3 | Media | Formato Eq.7 no coincide | Compatibilidad dual GET/POST, 404, 409 insufficient_stock, quantity raiz |
| 4 | Media | Cliente E1 firmaba mal la ruta | Migrado a OAuth2 + /identity/assignments |
| 5 | Baja | docs/eq3.md desactualizado | Actualizado |
| 6 | Baja | Webhook E2 acepta cualquier business_id | Validado contra config -> 403 |
| 7 | Baja | Confirm sin reserva da 409 | Valida reserva antes de pago -> 404 |
| 8 | Baja | Sin Accept: json devuelve 302 | Middleware ForceJsonResponse en /api/* |
| 9 | Baja | Backoff documentado incorrecto | Docs alineados a 500 -> 1500ms |

## Pendientes reales

- Eq.1: agregar TEAM1_OAUTH_TOKEN_URL + TEAM1_CLIENT_ID + TEAM1_CLIENT_SECRET al .env y flipear STUB=false
- Eq.2: esperar que implementen el emisor del webhook
- Eq.3: esperar que reemplacen MockTeam4InventoryGateway por cliente HTTP real
- Eq.7: definir webhook vs polling para inventory.reservation.expired

## Documentacion

- eq1.md — contrato con Eq.1 (OAuth2)
- eq2.md — contrato con Eq.2 (webhook)
- eq3.md — contrato con Eq.3 (integracion)
- eq7.md — contrato con Eq.7 (inventario)
- README.md — firma HMAC comun
- STATUS_INTEGRACIONES.md — este archivo
