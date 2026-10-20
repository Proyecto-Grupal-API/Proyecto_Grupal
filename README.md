# Campus Digital — Programa de Recompensas y Puntos (Equipo 7)

Plataforma integral de recompensas, gamificación, lealtad y gobierno de puntos para el ecosistema universitario **Campus Digital**. Diseñada con arquitectura de microservicios contenerizados, contabilidad inmutable de doble entrada en MongoDB con réplica set transaccional (`rs0`), API REST segura en Laravel 13 y una interfaz de usuario moderna y reactiva en Vue 3.

---

## 🏛️ Arquitectura del Sistema

```
                        ┌───────────────────────────────────────────────┐
                        │              Navegador Web / UI               │
                        │        http://localhost:5173 (Vue 3 + Vite)   │
                        └───────────────────────┬───────────────────────┘
                                                │ /api (Reverse Proxy)
                                                ▼
                        ┌───────────────────────────────────────────────┐
                        │          Nginx Reverse Proxy & Gateway        │
                        │             http://localhost:8000             │
                        └───────────────────────┬───────────────────────┘
                                                │ FastCGI (Port 9000)
                                                ▼
                        ┌───────────────────────────────────────────────┐
                        │            Backend Laravel 13 (PHP 8.3)       │
                        │       Autenticación Sanctum + RBAC Roles      │
                        │        PointsLedgerService + FraudDetection   │
                        └───────────────────────┬───────────────────────┘
                                                │ Driver MongoDB 2.x
                                                │ Transacciones ACID (rs0)
                                                ▼
                        ┌───────────────────────────────────────────────┐
                        │             MongoDB 7 Replica Set             │
                        │          mongodb:27017 (Host: 27018)          │
                        │          ReplicaSet: rs0 (Keyfile Auth)       │
                        └───────────────────────────────────────────────┘
```

### Tecnologías Principales
- **Backend:** PHP 8.3-FPM, Laravel 13, `mongodb/laravel-mongodb` v5.x con extension `ext-mongodb` 2.x.
- **Base de Datos:** MongoDB 7 configurada en modo Replica Set (`rs0`) con autenticación por `keyfile` para soportar transacciones ACID de múltiples documentos en el ledger contable.
- **Seguridad:** Laravel Sanctum adaptado a modelos de documentos MongoDB, middleware de roles (`role:*`) y permisos granulares (`permission:*`).
- **Frontend:** Vue 3 (Composition API), Vite, Vue Router 4, Tailwind CSS, Heroicons, diseño responsive con soporte de modo oscuro y selector reactivo de roles en tiempo de ejecución.
- **Contenedores:** Docker Compose con healthchecks automáticos y orden de arranque por dependencias (`mongodb` -> `mongo-init` -> `app` -> `webserver` -> `frontend`).

---

## 🚀 Puesta en Marcha Rápida (Docker)

### Prerrequisitos
- [Docker Desktop](https://www.docker.com/products/docker-desktop/) instalado y en ejecución en Windows, macOS o Linux.
- Puertos disponibles en la máquina anfitriona: `8000` (API), `5173` (Frontend), `27018` (MongoDB directo opcional).

### 1. Clonar y configurar entorno
```bash
# Copiar el archivo de variables de entorno si no existe
cp .env.example .env
```

Variables clave en `.env`:
```ini
API_PORT=8000
FRONTEND_PORT=5173
MONGO_PORT=27018
MONGO_ROOT_USERNAME=root
MONGO_ROOT_PASSWORD=secret
RUN_MIGRATIONS=true
```

### 2. Levantar los contenedores
```bash
docker compose up -d --build
```

El script de inicialización creará automáticamente:
1. El clúster MongoDB con Replica Set `rs0` e inicializará el usuario root y de aplicación.
2. Ejecutará las migraciones y seeders automáticos en Laravel (`DatabaseSeeder`).
3. Iniciará el servidor Nginx y el servidor de desarrollo Vite con proxy inverso activo.

### 3. URLs de Acceso
| Servicio | URL | Descripción |
| :--- | :--- | :--- |
| **Frontend Web** | [http://localhost:5173](http://localhost:5173) | Aplicación SPA completa con switcher de roles |
| **API REST** | [http://localhost:8000/api/v1](http://localhost:8000/api/v1) | Endpoints de la API v1 |
| **Healthcheck API** | [http://localhost:8000/up](http://localhost:8000/up) | Estado de salud del contenedor backend |
| **MongoDB Host** | `mongodb://root:secret@127.0.0.1:27018/?replicaSet=rs0&authSource=admin` | Conexión directa desde Compass / DBeaver |

---

## 👥 Matriz de Credenciales y Roles Demo

Todos los usuarios de demostración tienen la contraseña predeterminada: `Campus2026!`

| Rol | Correo Electrónico | Contraseña | Capacidades Principales |
| :--- | :--- | :--- | :--- |
| **Estudiante** | `alumno@campus.edu` | `Campus2026!` | Ver saldo, catálogo de recompensas, solicitar canjes, ver historial y campañas activas. |
| **Profesor** | `profesor@campus.edu` | `Campus2026!` | Validar y entregar códigos de canje, proponer campañas académicas, analítica docente. |
| **Administrador** | `admin@campus.edu` | `Campus2026!` | Gestión del catálogo, negocios afiliados, reglas de acumulación, revisión de fraude, auditoría. |
| **Admin Plataforma** | `platform.admin@campus.edu` | `Campus2026!` | Gobierno global de políticas, parámetros del sistema, reintentos de webhooks y observabilidad. |

> **Nota:** La barra superior de navegación de la aplicación web incluye un selector desplegable (**Rol Activo**) que permite conmutar entre estos usuarios de prueba instantáneamente sin necesidad de cerrar sesión manualmente.

---

## 🧪 Ejecución de Pruebas Automatizadas

La suite de pruebas automatizadas cubre el 100% de los flujos críticos del backend:

```bash
docker compose exec app php artisan test
```

### Pruebas Incluidas (30 Tests / 81 Aserciones):
1. **`PointsLedgerServiceTest` (20 pruebas):**
   - Integridad del libro contable de doble entrada (`earned`, `redeemed`, `reversed`, `expired`, `adjusted`).
   - Transaccionalidad ACID en MongoDB: reversión automática en fallos.
   - Prevención de saldos negativos y bloqueo de fondos.
   - Reconciliación contable matemática entre el balance proyectado y las entradas del ledger.
2. **`AuthApiTest` (4 pruebas):**
   - Login con generación de token Bearer Sanctum.
   - Rechazo de credenciales inválidas.
   - Obtención de perfil autenticado (`/api/v1/auth/me`).
   - Cierre de sesión y revocación del token en base de datos.
3. **`PermissionsAndRolesApiTest` (4 pruebas):**
   - Autorización estricta por permisos y roles en endpoints administrativos.
   - Rechazo con HTTP 403 Forbidden para estudiantes en rutas protegidas.
4. **`RewardsAndRedemptionsApiTest` (2 pruebas):**
   - Flujo completo de canje: bloqueo de puntos, reserva de stock y generación de código alfanumérico único.
   - Entrega por parte de profesor/admin con deducción definitiva de inventario.

---

## 📦 Frontend: Compilación y Estructura

El frontend está desarrollado en Vue 3 con Vite y Tailwind CSS dentro de `frontend_respaldo/`.

### Comandos Frontend dentro del contenedor:
```bash
# Compilar bundle de producción (0 errores)
docker compose exec frontend npm run build

# Validar linter
docker compose exec frontend npm run lint
```

### Módulos del Frontend (13 Vistas Adaptativas):
- **Estudiante:**
  - `StudentDashboard.vue`: Saldo en tiempo real, accesos rápidos y últimos movimientos.
  - `StudentCatalog.vue`: Catálogo filtrable por categoría/puntos con modal de confirmación de canje.
  - `StudentRedemptions.vue`: Cupones digitales generados con código alfanumérico, estado y opción de cancelación.
  - `StudentCampaigns.vue`: Misiones y campañas activas con multiplicadores de puntos.
  - `StudentActivity.vue`: Extracto de cuenta detallado de entradas y salidas de puntos.
- **Profesor:**
  - `ProfessorDashboard.vue`: Resumen de actividad académica y validaciones pendientes.
  - `ProfessorValidate.vue`: Terminal de verificación de códigos de canje con botón de entrega física.
  - `ProfessorCampaigns.vue`: Propuesta de campañas académicas por mérito o investigación.
  - `ProfessorAnalytics.vue`: Estadísticas de canjes procesados e impacto docente.
- **Administración y Plataforma:**
  - `AdminDashboard.vue`: Métricas ejecutivas (volumen de puntos emitidos, canjeados y tasa de redención).
  - `AdminLedger.vue`: Libro mayor contable con herramienta de reconciliación en tiempo real.
  - `AdminCatalog.vue`: Gestión CRUD de recompensas con control de stock físico/digital.
  - `AdminBusinesses.vue`: Registro y administración de comercios aliados en campus.
  - `AdminRules.vue`: Reglas configurables de asignación de puntos por evento.
  - `AdminGovernance.vue`: Monitor de alertas de fraude y ajuste manual de puntos con doble factor/auditoría.
  - `AdminAudit.vue`: Registro inmutable de auditoría con trazabilidad de IP, actor y cambios (`before/after`).
  - `AdminObservability.vue`: Monitor de webhooks entrantes y salientes con reintento manual.
  - `AdminSettings.vue`: Configuración de versiones de políticas y parámetros globales.

---

## 🔌 Contrato de Integración: Equipo 3 (Marketplace y Ventas)

Para sincronizar compras y devoluciones del módulo de Marketplace (Equipo 3) con el sistema de lealtad (Equipo 7), se expone el siguiente webhook estándar:

### Endpoint de Ingesta
`POST /api/v1/integrations/ingest-event`

### Cabeceras Requeridas
```http
Content-Type: application/json
Accept: application/json
Authorization: Bearer <API_TOKEN_EQUIPO_3>
X-Campus-Signature: <HMAC_SHA256_HEX(payload, SECRET_KEY)>
```

### Evento 1: Compra Completada (`marketplace.purchase_completed`)
Otorga puntos al alumno según las reglas vigentes (por ejemplo, 1 punto por cada $10 MXN de compra).

```json
{
  "event_id": "evt_mp_982341203912",
  "event_type": "marketplace.purchase_completed",
  "occurred_at": "2026-10-14T18:30:00Z",
  "student_id": "std_100234",
  "payload": {
    "order_id": "ORD-2026-90412",
    "business_id": "bus_cafeteria_central",
    "total_amount": 250.00,
    "currency": "MXN",
    "items_count": 3,
    "points_to_award": 25
  }
}
```

**Respuesta Exitosa (HTTP 200):**
```json
{
  "success": true,
  "status": "processed",
  "event_id": "evt_mp_982341203912",
  "transaction_id": "tx_670d8a12b9c34e0012f45a78",
  "points_credited": 25,
  "new_balance": 175
}
```

### Evento 2: Devolución o Reembolso (`marketplace.refund_issued`)
Revierte proporcionalmente los puntos previamente acreditados.

```json
{
  "event_id": "evt_mp_982341203913",
  "event_type": "marketplace.refund_issued",
  "occurred_at": "2026-10-14T19:00:00Z",
  "student_id": "std_100234",
  "payload": {
    "original_order_id": "ORD-2026-90412",
    "refund_amount": 250.00,
    "points_to_deduct": 25,
    "reason": "Devolución de artículo defectuoso"
  }
}
```

### Idempotencia y Códigos de Respuesta
- Si se envía un `event_id` duplicado, el sistema responde **HTTP 200** con status `"already_processed"` evitando doble acreditación.
- **HTTP 400 Bad Request:** Formato de carga útil inválido o campos faltantes.
- **HTTP 401 Unauthorized:** Token ausente o inválido.
- **HTTP 403 Forbidden:** Firma HMAC incorrecta o permisos insuficientes.
- **HTTP 422 Unprocessable Entity:** Saldo insuficiente para ejecutar la reversión de puntos.

---

## 🛠️ Resumen de Endpoints API v1

Todas las rutas están agrupadas bajo el prefijo `/api/v1`:

```
POST   /v1/auth/login                  - Inicio de sesión y expedición de token
POST   /v1/auth/switch-demo-user       - Cambio rápido entre identidades demo
POST   /v1/auth/logout                 - Revocación de token actual
GET    /v1/auth/me                     - Datos del usuario logueado

GET    /v1/dashboard/stats             - Estadísticas y KPIs según rol del usuario

GET    /v1/points/my-balance           - Saldo actual del estudiante
GET    /v1/points/my-history           - Historial de movimientos del estudiante
GET    /v1/points/ledger               - Libro mayor (Admin)
POST   /v1/points/earn                 - Asignación de puntos por acción/regla
POST   /v1/points/redeem               - Canje de recompensa
POST   /v1/points/reverse              - Reversión contable de transacción
POST   /v1/points/reconcile/{id}       - Reconciliación contable de balance

GET    /v1/rewards                     - Catálogo público/activo de recompensas
GET    /v1/rewards/{id}                - Detalle de recompensa
POST   /v1/rewards                     - Crear recompensa (Admin)
PUT    /v1/rewards/{id}                - Actualizar recompensa (Admin)
DELETE /v1/rewards/{id}                - Eliminar recompensa (Admin)

GET    /v1/redemptions                 - Lista de canjes del usuario o global
POST   /v1/redemptions                 - Solicitar nuevo canje (Estudiante)
POST   /v1/redemptions/validate-code   - Validar código de canje (Profesor/Admin)
POST   /v1/redemptions/{id}/deliver    - Marcar canje como entregado (Profesor/Admin)
POST   /v1/redemptions/{id}/cancel     - Cancelar canje y reembolsar puntos

GET    /v1/businesses                  - Lista de negocios afiliados
POST   /v1/businesses                  - Crear negocio aliado (Admin)
POST   /v1/businesses/{id}/toggle-status - Activar/desactivar negocio

GET    /v1/rules                       - Reglas de acumulación de puntos
POST   /v1/rules                       - Registrar nueva regla de puntos

GET    /v1/campaigns                   - Campañas promocionales y académicas
POST   /v1/campaigns/propose           - Proponer campaña académica (Profesor)

GET    /v1/governance/fraud-flags      - Alertas de detección de fraude (Admin)
POST   /v1/governance/fraud-flags/{id}/review - Dictaminar alerta de fraude
POST   /v1/governance/manual-adjustment - Ajuste manual extraordinario de balance

GET    /v1/audit/logs                  - Registro inmutable de auditoría forense
GET    /v1/analytics/overview          - Métricas globales de la plataforma
POST   /v1/integrations/ingest-event   - Webhook de ingesta para Equipo 3 (Marketplace)
```

---

## 📄 Licencia y Créditos
Desarrollado para la materia de **Ingeniería de Software (7mo Semestre)** — *Equipo 7: Campus Digital (Recompensas y Puntos)*.
