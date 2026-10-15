# Integracion con Equipo 1 - Identidad

**Rol de Eq.4**: Cliente (consumimos la API de Eq.1)
**Cliente HTTP**: `App\Services\Team1OAuthClient`
**Consumidor**: `App\Services\IdentityService`
**Contrato**: INT-1B.5 (OAuth2 client_credentials + JWT Bearer)

## Contexto

Eq.1 es la fuente de verdad de identidad y roles business. Eq.4
consume dos operaciones para autorizar su panel de administracion:

1. `POST /identity/authorization/check` - verifica una capability
2. `GET /identity/assignments` - devuelve los roles efectivos

Eq.1 **no expone** el catalogo de usuarios, no devuelve emails ni
nombres, y no permite asignar/revocar roles por API. Toda la
administracion de roles ocurre dentro de Eq.1 o Eq.3.

## Autenticacion (OAuth2)

**Flujo**:

```
1. Eq.4 POST /oauth/token con client_credentials
2. Eq.1 responde {access_token: JWT, expires_in: 3600}
3. Eq.4 usa Authorization: Bearer <token> en el resto
```

**Formato del request**:

```
POST /oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials
&client_id=eq4
&client_secret=<secret>
&scope=identity:authorization:check identity:assignments:read
```

**Cache**: el token se guarda hasta `expires_in - 60s`. Al recibir 401
en cualquier request, se invalida y se reintenta UNA vez.

## Endpoints consumidos

### 1. POST /identity/authorization/check

Request:
```json
{
  "subject_id": "USR-2024-ABC",
  "capability": "business.inventory.operate",
  "scope_type": "business",
  "scope_id": "BUS-CD-SOUV-001"
}
```

Response 200:
```json
{ "authorized": true }
```

Response 200 (negado):
```json
{ "authorized": false }
```

### 2. GET /identity/assignments

Query params:
- `subject_id` (required)
- `scope_type=business` (required)
- `scope_id` (required)

Response 200:
```json
{
  "subject_id": "USR-2024-ABC",
  "scope_type": "business",
  "scope_id": "BUS-CD-SOUV-001",
  "membership_status": "active",
  "roles": [
    {
      "role_key": "business_owner",
      "status": "active",
      "starts_at": "2026-01-01T00:00:00+00:00",
      "ends_at": null,
      "assigned_at": "2026-01-01T00:00:00+00:00"
    }
  ]
}
```

**Reglas aplicadas por Eq.4**:

- Solo `membership_status = active` autoriza.
- Solo los roles con `status = active` se consideran.
- Si el subject no existe o no tiene roles, se rechaza.

## Mapeo de roles Eq.1 -> Eq.4

Eq.1 expone 5 roles business canonicos. Eq.4 maneja 4 roles:

| Rol Eq.1 | Rol Eq.4 | Notas |
|---|---|---|
| `business_owner` | `admin` | Unico por negocio |
| `business_manager` | `admin` | Delegacion operativa |
| `inventory_manager` | `inventory_manager` | Coincide exacto |
| `buyer` | `buyer` | Coincide exacto |
| `cashier` | (ignorado) | Fuera de dominio Eq.4 |

**Multi-rol (Opcion B)**: si un subject tiene varios roles en Eq.1,
Eq.4 devuelve la union de los roles canonico. Ejemplo:

```
Eq.1 emite: [business_owner, buyer]
Eq.4 resuelve: roles = [admin, buyer], role = admin (primario)
```

Los permisos se evaluan como la **union** de los permisos de todos
los roles. El campo `role` (primario) solo se usa para mostrar en la UI.

## Scopes requeridos a Eq.1

| Scope | Uso |
|---|---|
| `identity:authorization:check` | Validar capabilities puntuales |
| `identity:assignments:read` | Leer roles efectivos por subject |

## Variables de entorno (Eq.4)

**Modo stub (dev)**:

```env
TEAM1_STUB_ENABLED=true
TEAM1_DEFAULT_USER_ID=USR-ADMIN-001
```

**Modo real (prod)**:

```env
TEAM1_STUB_ENABLED=false
TEAM1_OAUTH_TOKEN_URL=http://campus-eq1:8000/oauth/token
TEAM1_API_URL=http://campus-eq1:8000/api/v1
TEAM1_CLIENT_ID=eq4
TEAM1_CLIENT_SECRET=<secret que nos da Eq.1>
TEAM1_TIMEOUT=5
TEAM1_CACHE_TTL=60
```

Cuando Eq.1 confirme, se cambian las variables y se corre:

```
docker exec campus-eq4-app php artisan config:clear
docker exec campus-eq4-app php artisan cache:clear
```

## Resiliencia

| Escenario | Comportamiento |
|---|---|
| 401 en cualquier request | Invalida el token, reintenta UNA vez |
| 5xx | No reintenta (Eq.4 responde 503 al usuario) |
| Timeout / error de red | No reintenta |
| Subject sin roles activos | Rechaza el acceso, log info |
| Membership no-active | Rechaza el acceso, log info |

## Rol `auditor` en Eq.4

Eq.1 **no expone** un rol `auditor` en su catalogo business.
El rol `auditor` de Eq.4 solo funciona en modo stub (dev).
En produccion nadie va a tener este rol hasta que Eq.1 lo publique.

## Colecciones MongoDB de Eq.4

| Coleccion | Uso |
|---|---|
| `users` (Eq.4 no la usa) | Identidad vive en Eq.1 |
| `stock_alerts` | Para el badge de alertas urgentes |

## Pendientes con Eq.1

1. Que nos entreguen `client_id` + `client_secret` + confirmacion de
   los scopes `identity:authorization:check` y `identity:assignments:read`.
2. Definir como se propaga el `subject_id` a Eq.4 en produccion
   (hoy Eq.4 lo toma del stub o del usuario logueado).
3. Definir si Eq.1 va a exponer `auditor` en algun momento.
