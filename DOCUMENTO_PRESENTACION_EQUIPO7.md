# Campus Digital — Programa de Recompensas y Puntos (Equipo 7)
## Documento Maestro de Avances, Arquitectura y Preparación para Integraciones

**Materia:** Ingeniería de Software (7mo Semestre)  
**Equipo:** Equipo 7 — Recompensas, Puntos y Lealtad  
**Fecha:** Octubre 2026  
**Estado del Proyecto:** 100% Funcional, Integrado de Extremo a Extremo y Verificado con Pruebas Automatizadas  

---

## 📑 Tabla de Contenidos
1. [Resumen Ejecutivo](#1-resumen-ejecutivo)
2. [Arquitectura Tecnológica del Sistema](#2-arquitectura-tecnológica-del-sistema)
3. [Avances y Módulos Desarrollados (Lo Construido)](#3-avances-y-módulos-desarrollados-lo-construido)
4. [Estrategia y Preparación para Integraciones Inter-Equipos](#4-estrategia-y-preparación-para-integraciones-inter-equipos)
   - [4.1 Integración con Equipo 3 (Marketplace y Ventas)](#41-integración-con-equipo-3-marketplace-y-ventas)
   - [4.2 Integración con Módulos Académicos y Escolares](#42-integración-con-módulos-académicos-y-escolares)
   - [4.3 Integración con Identidad y Autenticación Central (SSO)](#43-integración-con-identidad-y-autenticación-central-sso)
   - [4.4 Integración con Notificaciones y Comunicaciones](#44-integración-con-notificaciones-y-comunicaciones)
5. [Métricas de Calidad y Pruebas Automatizadas](#5-métricas-de-calidad-y-pruebas-automatizadas)
6. [Guión y Estructura Recomendada para la Presentación (Slides)](#6-guión-y-estructura-recomendada-para-la-presentación-slides)
7. [Guía de Demostración en Vivo (Live Demo Script)](#7-guía-de-demostración-en-vivo-live-demo-script)

---

## 1. Resumen Ejecutivo

El **Programa de Recompensas y Puntos (Equipo 7)** constituye el motor transversal de fidelización, incentivos y gamificación de la plataforma **Campus Digital**. Su propósito es transformar la experiencia universitaria reconociendo el mérito académico, la participación en actividades extracurriculares y el consumo en comercios afiliados del campus mediante una moneda digital interna (*Puntos Campus*).

### Hitos Clave Alcanzados:
- **Disponibilidad Inmediata:** Despliegue contenerizado en Docker con arranque automatizado en un solo comando (`docker compose up -d --build`).
- **Fiabilidad Contable Absoluta:** Implementación de un Ledger Inmutable de Doble Entrada basado en MongoDB Replica Set (`rs0`) con soporte para **transacciones ACID multi-documento**, previniendo matemáticamente saldos negativos, condiciones de carrera y dobles gastos.
- **Seguridad Rigurosa y RBAC Granular:** Autenticación vía Laravel Sanctum adaptada nativamente a colecciones NoSQL, respaldada por un esquema de control de acceso basado en roles (Estudiante, Profesor, Administrador, Admin de Plataforma) y permisos atómicos.
- **Frontend SPA Moderno y Reactivo:** Desarrollado en Vue 3 con Vite y Tailwind CSS, con 13 interfaces de usuario adaptativas y un selector dinámico de identidades en tiempo real.
- **Preparación Integral para Integraciones:** Arquitectura orientada a eventos con webhook seguro (`/api/v1/integrations/ingest-event`), verificación de firmas criptográficas HMAC-SHA256, mecanismos de idempotencia y consola de observabilidad de reintentos.
- **Calidad Certificada:** **30 de 30 pruebas automatizadas pasando con éxito** (81 aserciones) y 0 errores de compilación en el frontend.

---

## 2. Arquitectura Tecnológica del Sistema

El sistema implementa una arquitectura desacoplada por capas, con separación estricta de responsabilidades:

```
┌────────────────────────────────────────────────────────────────────────┐
│                          CAPA DE PRESENTACIÓN                          │
│     Frontend Vue 3 (Composition API) + Vite + Tailwind CSS (SPA)       │
│                  http://localhost:5173 (Host)                          │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ Peticiones REST / Bearer Token
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                        CAPA DE ENTRADA Y GATEWAY                       │
│             Nginx Reverse Proxy & Balanceador (Port 8000)              │
│       - Enrutamiento hacia PHP-FPM  - Cabeceras de Seguridad y CORS    │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ FastCGI (Port 9000)
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                      CAPA DE LÓGICA DE NEGOCIO                         │
│                    Backend Laravel 13 (PHP 8.3)                        │
│                                                                        │
│   ┌────────────────────┐ ┌────────────────────┐ ┌──────────────────┐   │
│   │ Sanctum Auth / RBAC│ │ PointsLedgerService│ │ Motor Antifraude │   │
│   └────────────────────┘ └────────────────────┘ └──────────────────┘   │
│   ┌────────────────────┐ ┌────────────────────┐ ┌──────────────────┐   │
│   │ Redemptions Manager│ │ AuditLog Forense   │ │ Ingest Webhook   │   │
│   └────────────────────┘ └────────────────────┘ └──────────────────┘   │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ Driver MongoDB 2.x
                                    │ Protocolo Wire con Transacciones ACID
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                         CAPA DE PERSISTENCIA                           │
│                MongoDB 7 Replica Set (rs0, Keyfile Auth)               │
│        Colecciones: users, accounts, ledger_entries, rewards,          │
│        redemptions, businesses, rules, campaigns, fraud_flags, logs    │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Avances y Módulos Desarrollados (Lo Construido)

A continuación se detalla cada uno de los subsistemas completados:

### 3.1 Motor Contable y Libro Mayor (`PointsLedgerService`)
- **Contabilidad de Doble Entrada:** Cada operación genera un registro inmutable en `ledger_entries` con su contraparte en el balance agregado (`accounts`).
- **Tipos de Movimiento Soportados:**
  - `earned`: Acreditación de puntos por consumo en negocios, mérito académico o promociones.
  - `redeemed`: Débito temporal o definitivo al solicitar un artículo del catálogo.
  - `reversed`: Cancelación de compras o devoluciones comerciales, con salvaguarda contra saldos negativos.
  - `adjusted`: Modificaciones manuales extraordinarias aprobadas por gobierno institucional.
  - `expired`: Caducidad programada de puntos por ciclo escolar.
- **Idempotencia Estricta:** Toda operación exige una clave de idempotencia (`idempotency_key`). Si la red reintenta una petición idéntica, el sistema devuelve la transacción previa sin duplicar fondos.
- **Reconciliación Contable:** Endpoint matemático `/api/v1/points/reconcile/{studentId}` que recalcula el saldo sumando las entradas del libro mayor y corrige cualquier desfase de caché.

### 3.2 Seguridad, Autenticación y Autorización (RBAC)
- **Token Personal Sanctum sobre NoSQL:** Implementación de `PersonalAccessToken` extendiendo `DocumentModel` para almacenar tokens criptográficos directamente en colecciones MongoDB.
- **Estructura de Roles y Permisos:**
  - `student`: Consulta de saldo personal, navegación del catálogo, solicitud y cancelación de canjes propios.
  - `professor`: Validación y entrega física de recompensas en ventanilla, propuesta de campañas académicas y analítica grupal.
  - `admin`: Configuración de reglas, catálogo, comercios aliados, resolución de banderas de fraude y auditoría.
  - `platform_admin`: Configuración del sistema, versionado de políticas globales y reintentos de webhooks.
- **Conmutación Rápida para Demostración:** Endpoint `/api/v1/auth/switch-demo-user` que permite a evaluadores y docentes probar los 4 roles en un solo clic.

### 3.3 Catálogo de Recompensas y Ciclo de Canje (`Redemptions`)
- **Tipología de Recompensas:** Soporte para artículos físicos (mochilas, termos, papelería) y digitales (bonos de descuento, impresiones).
- **Control de Inventario Concurrente:** Mecanismo de dos fases:
  1. *Fase de Reserva:* Al solicitar el canje, los puntos se debitan y el stock pasa a `reserved_stock`.
  2. *Fase de Liquidación:* Al validar el código presencialmente, el stock físico se descuenta definitivamente.
  3. *Fase de Cancelación:* Si el alumno cancela antes de la entrega, los puntos y el stock reservado se liberan automáticamente.
- **Código Seguro de Entrega:** Generación de códigos alfanuméricos únicos (ej. `CANJE-X8K92L`) para canje en mostrador.

### 3.4 Motor de Reglas, Campañas y Antifraude
- **Reglas Dinámicas de Acumulación:** Factor multiplicador por negocio (ej. 1 punto por cada $10 MXN), puntos fijos por acción y ventanas de vigencia temporal.
- **Topes Diarios y Protección:** Límites de puntos máximos por transacción y por día para blindar el presupuesto de los comercios.
- **Detección de Anomalías (`FraudFlag`):** Detección automática de transacciones sospechosas (ej. múltiples compras en milisegundos o reversiones con saldo insuficiente) con interfaz para dictamen administrativo (`pending`, `reviewed`, `dismissed`).

### 3.5 Auditoría Forense Transversal (`AuditLog`)
- Registro inmutable que almacena: actor (`actor_id`, `actor_role`), acción ejecutada, entidad modificada, dirección IP, User-Agent y estado completo antes y después del cambio (`before/after`).

### 3.6 Frontend SPA (13 Interfaces Adaptativas)
- **Estudiante:** Dashboard con saldo en vivo, Catálogo interactivo con modal de canje, Mis Canjes con código de barras/cupones, Campañas y Extracto de Puntos.
- **Profesor:** Terminal de validación y entrega de códigos con verificación instantánea, propuesta de campañas de mérito y analítica.
- **Administrador:** Tablero ejecutivo con KPIs globales, Libro Mayor (Ledger) con reconciliación interactiva, ABM de Recompensas, Comercios, Reglas, Gobierno de Fraude, Registro de Auditoría y Observabilidad de Webhooks.

---

## 4. Estrategia y Preparación para Integraciones Inter-Equipos

La arquitectura del Equipo 7 fue concebida desde su diseño original para integrarse orgánicamente con los demás módulos del Campus Digital.

```
                                  ┌───────────────────────────┐
                                  │   Equipo 3 (Marketplace)  │
                                  │   Compras / Devoluciones  │
                                  └─────────────┬─────────────┘
                                                │ Webhook HMAC (Event Ingestion)
                                                ▼
┌───────────────────────────┐     ┌───────────────────────────┐     ┌───────────────────────────┐
│     Módulos Escolares     │     │         EQUIPO 7          │     │     Sistema de Notif.     │
│   Calificaciones y Mérito ├────►│  Programa de Recompensas  ├────►│       Push / Email        │
└───────────────────────────┘     │          y Puntos         │     └───────────────────────────┘
                                  └─────────────▲─────────────┘
                                                │ Token Sanctum / Claims
                                                │
                                  ┌─────────────┴─────────────┐
                                  │   Identidad Central (SSO) │
                                  │   Usuarios y Roles Campus │
                                  └───────────────────────────┘
```

---

### 4.1 Integración con Equipo 3 (Marketplace y Ventas)

El Equipo 3 administra la tienda universitaria, cafeterías y librerías. La integración permite que cada compra genere puntos automáticamente y que cada devolución revierta los puntos otorgados.

#### A. Especificación del Endpoint
- **URL:** `POST http://localhost:8000/api/v1/integrations/ingest-event`
- **Autenticación:** Cabecera `Authorization: Bearer <API_TOKEN_EQUIPO_3>`
- **Firma Criptográfica:** Cabecera `X-Campus-Signature: <HMAC_SHA256(payload, SHARED_SECRET)>`
- **Formato:** `Content-Type: application/json`

#### B. Evento de Compra Completada (`marketplace.purchase_completed`)
Cuando un alumno paga en el Marketplace, el Equipo 3 despacha este evento:

```json
{
  "event_id": "evt_mp_20261014_982341",
  "event_type": "marketplace.purchase_completed",
  "occurred_at": "2026-10-14T18:30:00Z",
  "student_id": "std_100234",
  "payload": {
    "order_id": "ORD-2026-90412",
    "business_id": "biz-cafeteria-central",
    "total_amount": 250.00,
    "currency": "MXN",
    "items_count": 3,
    "points_to_award": 25
  }
}
```

**Respuesta Exitosa (HTTP 200 OK):**
```json
{
  "success": true,
  "status": "processed",
  "event_id": "evt_mp_20261014_982341",
  "transaction_id": "tx_670d8a12b9c34e0012f45a78",
  "points_credited": 25,
  "new_balance": 175
}
```

#### C. Evento de Devolución o Cancelación (`marketplace.refund_issued`)
Si el estudiante devuelve un artículo, el Equipo 3 notifica para descontar los puntos asociados:

```json
{
  "event_id": "evt_mp_20261014_982342",
  "event_type": "marketplace.refund_issued",
  "occurred_at": "2026-10-14T19:00:00Z",
  "student_id": "std_100234",
  "payload": {
    "original_order_id": "ORD-2026-90412",
    "refund_amount": 250.00,
    "points_to_deduct": 25,
    "reason": "Devolución de artículo en mostrador"
  }
}
```

#### D. Idempotencia y Tolerancia a Fallos
- **Idempotencia Garantizada:** Si el Equipo 3 reintenta el webhook por interrupciones de red, el sistema detecta el `event_id` existente y responde inmediatamente con status `"already_processed"`, evitando duplicar puntos.
- **Trazabilidad en Tiempo Real:** Todos los webhooks entrantes quedan registrados en `/api/v1/integrations/logs` y pueden reintentarse manualmente desde la vista `AdminObservability.vue`.

---

### 4.2 Integración con Módulos Académicos y Escolares

La plataforma está lista para incentivar logros académicos mediante el módulo de campañas y asignación por mérito:

1. **Reconocimiento Académico Automático:**
   - Puede recibir eventos como `academic.excellence_achieved`, `academic.attendance_perfect` o `academic.library_on_time`.
   - Se procesan bajo el mismo contrato de ingesta de eventos con reglas de acumulación preconfiguradas.
2. **Propuestas Docentes:**
   - La pantalla `ProfessorCampaigns.vue` permite a cualquier profesor redactar propuestas de incentivos (ej. *"100 puntos por participar en la Feria de Ciencias"*).
   - El administrador revisa y aprueba la campaña en un solo clic, habilitando su vigencia inmediata.

---

### 4.3 Integración con Identidad y Autenticación Central (SSO)

- **Compatibilidad con Directorio Activo / LDAP / OAuth2:** El modelo de usuario de Laravel (`User.php`) y su vinculación con `Sanctum` permite recibir identidades desde un proveedor central de la universidad (como Google Workspace for Education o Microsoft Entra ID).
- **Atributos Homologados:** Identificador universal del alumno (`student_id` / matrícula institucional), rol académico y correo `@campus.edu`.

---

### 4.4 Integración con Notificaciones y Comunicaciones

El sistema emite eventos de dominio internos en cada punto de inflexión del ciclo de vida:
- `PointEarnedEvent` -> Notificación push: *"¡Has ganado 25 puntos en Cafetería Central!"*
- `RedemptionCreatedEvent` -> Envío de cupón digital al correo del estudiante.
- `RedemptionDeliveredEvent` -> Confirmación de entrega exitosa en ventanilla.
- `FraudFlagDetectedEvent` -> Alerta crítica a los administradores de seguridad.

---

## 5. Métricas de Calidad y Pruebas Automatizadas

La robustez de la solución está certificada por una suite automatizada de pruebas unitarias y de integración que se ejecutan dentro del contenedor:

```bash
docker compose exec app php artisan test
```

### Resultados de la Suite (100% de Éxito):
```
   PASS  Tests\Feature\AuthApiTest
  ✓ login returns token and user profile                                10.17s  
  ✓ login rejects invalid password                                       0.33s  
  ✓ me returns authenticated user                                        0.65s  
  ✓ logout revokes token                                                 0.49s  

   PASS  Tests\Feature\PermissionsAndRolesApiTest
  ✓ student cannot access admin ledger                                   0.79s  
  ✓ student cannot view another students balance                         0.38s  
  ✓ student cannot create earning rules                                  0.39s  
  ✓ admin can access admin endpoints                                     0.53s  

   PASS  Tests\Feature\RewardsAndRedemptionsApiTest
  ✓ full redemption lifecycle                                            1.32s  
  ✓ redemption fails when insufficient points                            0.50s  

   PASS  Tests\Feature\Services\PointsLedgerServiceTest
  ✓ earn creates ledger entry and updates account balance                0.46s  
  ✓ earn uses fixed points when set                                      0.43s  
  ✓ earn applies rule multiplier                                         0.55s  
  ✓ earn applies campaign multiplier when campaign id given              0.47s  
  ✓ earn caps at max points per operation                                0.42s  
  ✓ earn throws when no active rule for business                         0.48s  
  ✓ earn throws when rule is outside its validity window                 0.35s  
  ✓ earn partially caps points when daily cap is almost reached          0.50s  
  ✓ earn throws when daily cap is fully exhausted                        0.49s  
  ✓ earn is idempotent on retry with same key                            0.41s  
  ✓ earn writes an audit log entry                                       0.36s  
  ✓ redeem debits balance when sufficient points                         0.49s  
  ✓ redeem throws when insufficient points                               0.52s  
  ✓ redeem is idempotent on retry with same key                          0.57s  
  ✓ reverse of earn with full balance available removes all points       0.48s  
  ✓ reverse of earn already partially redeemed creates shortfall and fr… 0.41s  
  ✓ reverse throws when original ledger entry not found                  0.49s  
  ✓ reverse is idempotent on retry with same key                         0.47s  
  ✓ get available balance sums directly from ledger                      0.47s  
  ✓ reconcile account fixes a cache that drifted from the ledger         0.40s  

  Tests:    30 passed (81 assertions)
  Duration: 37.24s
```

---

## 6. Guión y Estructura Recomendada para la Presentación (Slides)

Estructura sugerida de 7 diapositivas para exponer ante profesores y compañeros de equipo:

### Diapositiva 1: Título y Misión del Equipo 7
- **Título:** Campus Digital — Programa de Recompensas y Puntos.
- **Subtítulo:** Motor Contable Inmutable, Gamificación Universitaria e Integración de Servicios.
- **Integrantes:** Equipo 7.
- **Objetivo:** Presentar el sistema de incentivos y su capacidad de conexión con los demás equipos (Marketplace, Escolar, Identidad).

### Diapositiva 2: El Reto y la Arquitectura de Solución
- **El Desafío:** Manejar una moneda digital universitaria sin riesgo de duplicidad, fraude ni desincronización de saldos.
- **La Solución:** 
  - Docker Compose orquestando 4 contenedores (Nginx, PHP 8.3, MongoDB rs0, Vite Vue 3).
  - MongoDB con Réplica Set para transacciones bancarias ACID multi-documento.
  - Laravel 13 con Sanctum NoSQL y Vue 3 reactivo.

### Diapositiva 3: Innovación Contable — El Ledger Inmutable
- **Explicación:** ¿Por qué no un simple campo `puntos = puntos + 10`?
- **Ventaja del Doble Registro:** Cada punto ganado, canjeado o revertido tiene trazabilidad forense matemática.
- **Idempotencia:** Protección contra fallos de red (evita pagar dos veces).
- **Reconciliación en Vivo:** Auditoría que detecta y corrige discrepancias al vuelo.

### Diapositiva 4: Seguridad, Roles y Gobierno Antifraude
- **Esquema RBAC:** 4 perfiles con interfaces específicas (Alumno, Docente, Administrador, Plataforma).
- **Detección de Fraude:** Banderas automáticas cuando se detectan patrones irregulares.
- **Auditoría Forense:** Registro inmutable de cada cambio con IP y estados `before/after`.

### Diapositiva 5: Preparación de Integración con el Equipo 3 (Marketplace)
- **Contrato REST / Webhook:** `POST /api/v1/integrations/ingest-event`.
- **Seguridad Inter-Servicios:** Firmas HMAC-SHA256 (`X-Campus-Signature`).
- **Casos de Uso Reales:** Compras en cafetería acumulan puntos; devoluciones los descuentan con consistencia transaccional.

### Diapositiva 6: Demostración en Vivo (Flujo End-to-End)
- *Ver la sección 7 para el guión de la demo.*

### Diapositiva 7: Conclusiones y Métricas de Calidad
- **30/30 Tests Unitarios y de Integración Pasando (100% verde).**
- **0 Errores de compilación en Frontend.**
- **Listo para conectar con el ecosistema global de Campus Digital.**

---

## 7. Guía de Demostración en Vivo (Live Demo Script)

Sigue estos pasos en vivo durante la presentación para mostrar el funcionamiento real:

### Paso 1: Abrir la Aplicación
1. Abrir navegador en: `http://localhost:5173`.
2. Mostrar la interfaz responsiva y moderna.
3. Señalar en la barra superior el selector de **Rol Activo**.

### Paso 2: Flujo del Alumno (Canje)
1. Seleccionar rol **"Alumno"** (`alumno@campus.edu`).
2. Ir a la pestaña **Catálogo de Recompensas**.
3. Seleccionar una recompensa (ej. *Boleto de Descuento 50% Cafetería* o *Cuaderno Ejecutivo*).
4. Hacer clic en **"Canjear Recompensa"** y confirmar en el modal.
5. Ir a **"Mis Canjes"**: Mostrar el cupón generado con su estado `pending` y su código único (ej. `CANJE-XXXXXX`). Copiar ese código.

### Paso 3: Flujo del Profesor (Validación y Entrega)
1. En la barra superior, cambiar el selector a **"Profesor"** (`profesor@campus.edu`).
2. Notar cómo el menú lateral y el dashboard cambian automáticamente.
3. Entrar a **"Validar Canje"**.
4. Pegar el código del cupón generado por el alumno y presionar **"Buscar Cupón"**.
5. Se mostrarán los datos del alumno y el artículo. Hacer clic en **"Marcar como Entregado"**.
6. Explicar a la audiencia que en este momento el inventario físico se descontó definitivamente en MongoDB.

### Paso 4: Flujo del Administrador (Ledger y Auditoría)
1. Cambiar el rol a **"Administrador"** (`admin@campus.edu`).
2. Ir a **"Libro Mayor (Ledger)"**: Mostrar la fila con el canje registrado con su código de transacción, fecha, actor e importe.
3. Presionar el botón **"Reconciliar Cuenta"** para mostrar la verificación matemática en vivo.
4. Ir a **"Auditoría"**: Mostrar el registro con la IP, fecha y snapshot del cambio.
5. Ir a **"Observabilidad / Webhooks"**: Mostrar cómo el sistema monitorea los eventos entrantes del Equipo 3.

---

## 📌 Datos de Contacto y Repositorio
- **Repositorio:** `Api / Proyecto-Grupal`
- **Frontend URL:** `http://localhost:5173`
- **Backend API URL:** `http://localhost:8000/api/v1`
- **Documentación Técnica Completa:** [README.md](../README.md)
