# Campus Virtual

Plataforma digital universitaria orientada al estudiante. El proyecto integra identidad digital, credenciales NFC/QR, perfiles académicos, autenticación segura y servicios compartidos para los demás módulos del ecosistema Campus Digital.

Este repositorio contiene la base de trabajo del **Equipo 1: Identidad, Acceso, NFC/QR y Perfil del Estudiante**.

## Alcance del Equipo 1

El equipo es responsable de construir la identidad común que consumen los demás módulos. Ningún dominio externo debe duplicar la lógica de autenticación ni la lectura de credenciales.

El alcance inicial contempla:

- Gestión de cuentas y perfil del estudiante.
- Autenticación, recuperación de contraseña y confirmación de cuenta.
- Autenticación de dos factores (2FA) desde la primera versión.
- Roles y permisos contextuales por negocio, asociación, Consejo o servicio.
- Registro y ciclo de vida de tarjetas NFC mediante UID simulado.
- Identidad QR y códigos temporales para validaciones.
- Dispositivos, sesiones confiables y alertas de acceso.
- Gestión y vinculación de dispositivos del usuario.
- Generación, presentación y validación de códigos QR de identificación y dinámicos/temporales para flujos autorizados.
- Validación de la condición estudiantil.
- Consentimientos y preferencias de comunicación.
- Servicios internos de identidad y credenciales para los demás equipos.

## Tecnologías

- **Backend:** Laravel 13 y PHP 8.3 o superior.
- **Frontend:** Vue 3, Inertia.js y Vite.
- **Autenticación:** Laravel Fortify y Breeze.
- **Estilos:** Tailwind CSS.
- **Pruebas:** Pest.
- **Persistencia:** MongoDB 7 para desarrollo local mediante Podman.
- **QR en frontend:** `qrcode` para renderizado y `@zxing/library` para escaneo con cámara.

La aplicación usa el paquete `mongodb/laravel-mongodb` y el modelo de usuario compatible con MongoDB. Laravel Fortify continúa siendo responsable de la autenticación; los módulos 1.8 y 1.9 solo consumen la identidad autenticada.

## Requisitos

- PHP 8.3 o superior.
- Composer.
- Node.js y npm.
- Git.
- Podman 5 o superior.
- Extensión PHP `mongodb`.

## Instalación

Clona el repositorio y entra en la carpeta del proyecto:

```bash
git clone https://github.com/Julian-Darkstar/campus-virtual.git
cd campus-virtual
```

Instala las dependencias de backend y frontend:

```bash
composer install
npm install
```

Las dependencias del módulo QR se incluyen en `package.json`: `qrcode` genera los códigos en el navegador y
`@zxing/library` lee códigos mediante la cámara. El backend reutiliza `mongodb/laravel-mongodb`; no es necesario
añadir otro paquete de Composer para generar imágenes porque el QR se renderiza en Vue.

Crea el archivo de entorno y genera la clave de la aplicación:

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Si el proyecto ya estaba instalado, actualiza ambas dependencias antes de migrar:

```bash
composer install
npm install
php artisan migrate
```

Configura MongoDB en el archivo `.env`:

```env
DB_CONNECTION=mongodb
DB_HOST=127.0.0.1
DB_PORT=27017
DB_DATABASE=campus_virtual
DB_USERNAME=
DB_PASSWORD=
```

## MongoDB local con Podman

Descarga y ejecuta MongoDB 7 con un volumen persistente:

```bash
podman pull docker.io/library/mongo:7
podman run -d --name campus-mongo \
  -p 27017:27017 \
  -v mongo_data:/data/db \
  docker.io/library/mongo:7
podman update --restart=unless-stopped campus-mongo
```

Si el contenedor ya existe, solo inícialo:

```bash
podman start campus-mongo
```

Comprueba MongoDB y Laravel:

```bash
podman exec campus-mongo mongosh --quiet --eval "db.runCommand({ ping: 1 })"
php artisan config:clear
php artisan tinker --execute="DB::connection('mongodb')->command(['ping' => 1]); echo 'MONGO_OK';"
```

La instalación local de desarrollo no habilita autenticación en MongoDB. Para DataGrip utiliza `localhost`, puerto `27017`, autenticación `No authentication` y la base `campus_virtual`. En MongoDB, las tablas se representan como colecciones; la aplicación crea `users` y `sessions` cuando existen documentos.

## Ramas de desarrollo

Este proyecto utiliza un flujo de Git con dos ramas principales:

### `main`

- **Rama prevista para versiones estables**; pertenecer a `main` no demuestra por sí solo preparación ni despliegue en producción.
- Cambios solo a través de pull requests revisados.
- Cada commit en `main` representa una versión funcional y documentada.

### `develop`

- **Rama de integración continua** donde se agrupan las features en construcción.
- Contiene trabajo en progreso y funcionalidades pendientes de finalizar.
- Punto de referencia para ver el estado actual del desarrollo.
- Cambios se agrupan en commits temáticos antes de proponer PR a `main`.

### Flujo de trabajo

```
main (stable) ← ← ← ← develop (active development)
                    ↑
                  feature branches
```

1. Crea una rama de feature desde `develop`: `git checkout -b feature/nombre-feature`
2. Realiza cambios y commits
3. Cuando esté lista, integra a `develop` mediante PR
4. Cuando una versión esté completa, crea PR de `develop` a `main`

## Desarrollo local

Para iniciar la aplicación en el puerto `8002`:

```bash
php artisan serve --host=127.0.0.1 --port=8002
```

La aplicación estará disponible en <http://127.0.0.1:8002>.

En otra terminal, ejecuta Vite para recompilar los recursos durante el desarrollo:

```bash
npm run dev
```

## Módulos QR y dispositivos

Con una sesión autenticada están disponibles:

- `/identidad/qr`: muestra identificación QR, genera un QR dinámico de un solo uso y ofrece validación web autorizada.
- `/seguridad/dispositivos`: consulta y administra dispositivos/sesiones del usuario.

Para servicios, `POST /api/v1/identity/qr-validate` valida el QR mediante OAuth Bearer con el scope `identity:qr:validate`; registra el resultado y consume los tokens dinámicos. No existe un endpoint API público de generación QR por Sanctum.

Las migraciones crean las colecciones MongoDB `devices`, `qr_tokens` y `qr_validations`, con índices para usuario,
token y expiración. La cámara requiere permisos del navegador y, en producción, un contexto HTTPS.

Para generar los recursos frontend de producción:

```bash
npm run build
```

## Pruebas

La suite de pruebas se ejecuta con:

```bash
php artisan test
```

También puede utilizarse el script de Composer:

```bash
composer test
```

Las pruebas usan la base MongoDB `campus_virtual_testing` y requieren el contenedor `campus-mongo` activo. El harness limpia esa base antes de cada prueba.

## OAuth 2.0 entre servicios

Los servicios consumidores autorizados usan las rutas OAuth mediante el grant `client_credentials`. Esto es independiente del login web de Fortify y no autentica por sí solo al actor humano.

Genera un cliente una sola vez y guarda el secreto fuera del repositorio:

```bash
php artisan oauth:client equipo-servicios --scope=students:read
```

Solicita un token:

```bash
curl -X POST http://127.0.0.1:8002/api/oauth/token \
  -d grant_type=client_credentials \
  -d client_id=svc_xxx \
  -d client_secret=xxx \
  -d scope=students:read
```

Usa el token como `Authorization: Bearer <access_token>` para las rutas `/api/v1`. Los tokens son JWT firmados, tienen issuer/audience, expiración y scopes. En producción define `OAUTH2_SIGNING_KEY` independiente de `APP_KEY` y rota los clientes periódicamente.

Los cambios de dominio implementan un contrato de eventos versionado (`*.v1`) y se guardan en la colección MongoDB interna `event_outboxes` para que un publicador pueda entregarlos a otros servicios sin acoplarlos a las colecciones internas. El contrato de integración y las guías de Equipos 2–7 están en [docs/integration/TEAM-1-INTEGRATION.md](docs/integration/TEAM-1-INTEGRATION.md).

El publicador se registra cada minuto en el scheduler Laravel con `withoutOverlapping(30)`. Para operarlo, la infraestructura debe ejecutar `php artisan schedule:run` cada minuto y configurar `EVENTS_SINK_URL` hacia un destino controlado; no se ha demostrado un cron de producción. `EVENTS_SINK_TOKEN` añade un Bearer estático provisional si el receptor lo requiere; destino y autenticación definitivos siguen pendientes de acuerdo con Equipo 7. Sin URL, `events:publish` falla sin enviar. El índice único `event_id` debe aplicarse en cada entorno antes de desplegar el transporte: su migración está `Ran` en development (batch 7, 28-09-2026), lo cual no acredita producción.

La entrega es **at-least-once**, no exactly-once: se publica un evento por POST, `published_at` sólo se guarda después de un ACK 2xx, y una respuesta perdida puede causar otro envío del mismo `event_id`. El consumidor debe deduplicar ese ID. Los fallos conservan el evento pendiente, incrementan `attempts` y guardan un código de error controlado; 429/5xx/red esperan entre 2 y 60 segundos según intentos, mientras cualquier otro non-2xx (incluido 409) espera 5 minutos e implica revisión operativa antes del siguiente intento. No se garantiza orden global ni por sujeto. La prueba E2E incluida usa exclusivamente un receptor HTTP temporal en `127.0.0.1` y datos de testing; no demuestra recepción por Equipo 7.

## Cambios Recientes

### Resumen de capacidades actuales

#### Entrega actual del módulo 1
- **Módulo 1.1:** gestión académica en MongoDB con perfiles, catálogos, historial, listado, alta, edición e importación CSV.
- **Módulo 1.2:** autenticación de dos factores integrada con Fortify.
- **Módulo 1.3:** RBAC contextual con roles y scopes.
- **Módulo 1.4:** registro NFC; **1.5:** bloqueo por pérdida, suspensión, reemplazo físico y reactivación, con historial y motivos.
- **Módulo 1.6:** QR de identificación y dinámico/temporal con validación web/OAuth; la compatibilidad plaintext legacy y reglas operativas adicionales son deuda separada. **1.7:** dispositivos, sesiones confiables y reautenticación.
- **Módulos 1.8 y 1.9:** estado académico persistente e historial; consentimientos y preferencias persistentes mediante sesión/Sanctum.
- **Integración entre servicios:** OAuth 2.0 `client_credentials`, JWT, scopes y middleware Bearer.
- **Eventos de dominio:** eventos versionados, outbox MongoDB con `event_id` estable y único tras aplicar su migración, y publicador programado con lease atómico y reintentos. La deduplicación del efecto corresponde al consumidor.
- **Integración 1.10 parcial:** estado académico y validación QR/NFC disponibles; INT-1B espera acuerdo de catálogo/ownership/provisioning con Equipos 3/4, y la entrega externa a Equipo 7 espera contrato y despliegue. La implementación local del outbox no equivale a integración externa completa.
- **Calidad:** pruebas automatizadas y build frontend disponibles; la guía de integración etiqueta la evidencia de validación con fecha.

La guía [Team 1 — Identity Integration Contract](docs/integration/TEAM-1-INTEGRATION.md) distingue las APIs OAuth para servicios de las rutas Sanctum y web, y enumera las capacidades todavía pendientes.

## Estado del proyecto

- [x] Estructura inicial Laravel.
- [x] Vue 3 + Inertia.js + Vite.
- [x] Autenticación base con Fortify y Breeze.
- [x] Módulo 1.1: perfiles académicos, catálogos, historial, listado, alta, edición e importación CSV adaptados a MongoDB.
- [x] Módulo 1.2: 2FA con Fortify.
- [x] Módulo 1.3: RBAC contextual.
- [x] Módulo 1.4: registro NFC.
- [x] Módulo 1.5: bloqueo/pérdida/suspensión/reactivación y reemplazo old→new con enlaces, historial, motivos y atomicidad MongoDB. La nueva tarjeta hereda el estado operativo; `replaced` es terminal.
- [x] Módulo 1.6: QR de identificación y dinámico/temporal, con validación disponible. La limpieza plaintext legacy y reglas operativas adicionales siguen como deuda técnica.
- [x] Módulo 1.7: dispositivos y sesiones confiables.
- [x] Migraciones iniciales de usuarios y 2FA.
- [x] Módulo 1.8: Validación de condición estudiantil (API + UI).
- [x] Módulo 1.9: Consentimientos y preferencias de comunicación (API + UI).
- [x] Identidad visual: Logo, colores institucionales, rediseño de pantallas.
- [x] Endpoints API REST v1 documentados y funcionales.
- [x] Pruebas automatizadas contra MongoDB.
- [x] Integración de autenticación inter-servicios OAuth 2.0.
- [x] Contratos implementados de estado académico, QR y NFC documentados para consumidores.
- [ ] Módulo 1.10 completo: pendiente INT-1B por acuerdo interequipos y pendiente entrega externa de eventos con Equipo 7.
- [x] Contratos de eventos versionados y outbox MongoDB.

### Notas de integración

- El módulo 1.1 se portó desde la rama SQL Server a documentos MongoDB (`campuses`, `academic_programs`, `student_profiles` y `academic_status_history`).
- La interfaz administrativa y la importación CSV están disponibles en `/students`; requieren un rol global `admin`, `maestro` o `student_manager`.
- `StudentCatalogSeeder` inicializa los catálogos; los índices únicos de usuarios y perfiles se crean mediante migraciones, sin depender del seeder.
- La importación CSV valida todas las filas antes de escribir. El contenedor local MongoDB usa el replica set `rs0`, habilitando transacciones multi-documento para atomicidad estricta.
- El estado académico y los consentimientos/preferencias persisten datos reales. Los endpoints académicos OAuth aceptan `User._id`; los de consentimientos/preferencias usan Sanctum y no son un contrato OAuth de servicio.
- La asignación contextual de roles funciona internamente; no hay API externa `role-check`. `student_manager` permanece en el catálogo por compatibilidad. Los contextos implementados son `business`, `association`, `service` y `council`; `campus` y `department` no son scopes RBAC.

## Repositorio

<https://github.com/Julian-Darkstar/campus-virtual>

## Licencia

La licencia del proyecto se definirá por el equipo antes de la primera versión pública estable.
