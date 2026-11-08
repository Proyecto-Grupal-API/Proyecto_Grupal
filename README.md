# Campus Digital · Equipo 4

Panel de **Inventarios, Proveedores, Compras y Abastecimiento** para los negocios de Campus Digital.

**Stack:** Laravel 13 (PHP 8.3+) · Vue 3 + Inertia · Tailwind · MongoDB · Docker

## Módulos

| Grupo | Pantallas |
|---|---|
| Dashboard | Resumen con indicadores |
| Catálogo | Productos, Souvenirs, Costos, Categorías |
| Stock | Inventario, Almacenes y ubicaciones, Kardex, Reservas |
| Compras | Proveedores, Órdenes de compra, Recepciones |
| Operación | Devoluciones, Alertas, Conteos |

Toda la interfaz vive bajo **`/equipo4`**. La ruta raíz `/` devuelve 404, y eso es normal.

---

## Levantar el proyecto con Docker (recomendado)

### Requisitos
- Docker Desktop instalado **y abierto** (espera a que diga "Engine running").
- Puertos libres: `8004` (app) y `27017` (MongoDB).

### Pasos
1. Abre una terminal en la carpeta del proyecto.
2. Levanta los contenedores:
   ```bash
   docker compose up -d --build
   ```
   La primera vez tarda varios minutos porque construye la imagen.
3. Carga los datos de demostración (solo la primera vez):
   ```bash
   docker exec campus-eq4-app php artisan demo:seed
   ```
4. Abre **http://localhost:8004/equipo4**

> Al arrancar, la app tarda uno o dos minutos en responder. Si el navegador dice
> "no se puede conectar", espera un poco y recarga.

### Comandos útiles
```bash
docker compose down                      # apagar
docker compose up -d                     # volver a encender (sin --build)
docker ps                                # ver qué está corriendo
docker compose logs -f campus-eq4-app    # ver logs de la app
```

---

## Levantar sin Docker

Requisitos: PHP 8.3+ con la extensión `mongodb`, Composer, Node 20+ y MongoDB corriendo en `127.0.0.1:27017`.

```bash
composer install
cp .env.example .env
php artisan key:generate
npm install
```

En el `.env` confirma:
```
DB_CONNECTION=mongodb
DB_URI="mongodb://127.0.0.1:27017"
DB_DATABASE=campus_digital
```

Después, en dos terminales:
```bash
php artisan serve     # http://127.0.0.1:8000/equipo4
npm run dev           # Vite con recarga en vivo
```

Y carga los datos de demostración:
```bash
php artisan demo:seed
```

---

## Datos de demostración

`php artisan demo:seed` crea categorías, almacenes, ubicaciones, 10 productos, stock y proveedores. Es **idempotente**: puedes ejecutarlo varias veces sin duplicar datos.

Hay además scripts de MongoDB en `database/mongo/` (opcionales):
```bash
mongosh "mongodb://127.0.0.1:27017/campus_digital" database/mongo/team4_init.js
mongosh "mongodb://127.0.0.1:27017/campus_digital" database/mongo/team4_seed.js
mongosh "mongodb://127.0.0.1:27017/campus_digital" --file database/mongo/migrations/001_team4_inventory_indexes.js
mongosh "mongodb://127.0.0.1:27017/campus_digital" --file database/mongo/migrations/002_team4_alerts_and_approvals.js
```
Con Docker se ejecutan dentro del contenedor `campus-eq4-mongo` (copia primero la carpeta con `docker cp`).
Las migraciones son versionadas e idempotentes. `TEAM4_ALERT_EMAIL` configura el correo de respaldo para alertas; si el registro del negocio tiene `inventory_responsible_email` o `responsible_email`, ese destinatario tiene prioridad. `TEAM4_INACTIVE_PRODUCT_DAYS` define el umbral (90 días por defecto). La tarea `team4:generate-alerts` corre cada 15 minutos y procesa todos los negocios con productos.

---

## Usuarios y permisos

El acceso se controla por **rol** y por **permiso de cada módulo**. En desarrollo no hay login: el usuario por defecto es el administrador, y puedes cambiar de usuario visitando:

```
http://localhost:8004/equipo4/dev/switch/<ID>
```

| ID | Nombre | Rol |
|---|---|---|
| `USR-ADMIN-001` | Jose Arreguin | Administrador (por defecto) |
| `USR-INV-001` | Ana Martinez | Responsable de inventario |
| `USR-BUY-001` | Luis Perez | Comprador |
| `USR-AUD-001` | Sofia Lopez | Auditor (solo lectura) |
| `USR-STU-001` | Alumno Ejemplo | Alumno (sin acceso al panel) |

Si un usuario no tiene permiso, ve la página "No autorizado". Esta ruta solo existe con `APP_ENV=local`.

---

## Frontend

El código está en `resources/js`:

- `Layouts/Equipo4Layout.vue`: barra lateral, barra superior y menú de usuario.
- `Components/Team4Module.vue`: encabezado, indicadores y tabla que usan casi todas las pantallas.
- `Pages/Equipo4/*.vue`: una pantalla por módulo.

Si cambias el frontend con Docker, recompila desde tu máquina; la carpeta `public/build` está montada en el contenedor:
```bash
npm install     # solo la primera vez
npm run build
```
Recarga el navegador con `Ctrl+F5`.

---

## Pruebas

```bash
docker exec campus-eq4-app php vendor/bin/phpunit --testdox
```

Importante:
- Las pruebas **necesitan los datos de demostración** (`demo:seed`). Sin ellos fallan por "stock > 10" y "SOUV-TAZA-001 no resuelto".
- Las pruebas usan la misma base de datos que la app. Para no tocar tus datos, corre contra una base temporal:
  ```bash
  docker exec -e DB_DATABASE=campus_test -e MONGODB_DATABASE=campus_test campus-eq4-app php artisan demo:seed
  docker exec -e DB_DATABASE=campus_test -e MONGODB_DATABASE=campus_test campus-eq4-app php vendor/bin/phpunit
  ```

---

## Integraciones con otros equipos

Las APIs para los equipos 3 y 7 están bajo `/api/equipo4/...` y exigen **firma HMAC**. El secreto compartido va en `TEAM_SERVICE_SHARED_SECRET` del `.env`.

Documentación en [`docs/`](docs/README.md): `eq1.md`, `eq2.md`, `eq3.md`, `eq7.md`.

> **Nunca subas el archivo `.env` ni secretos reales a GitHub.** Usa `.env.example` como plantilla.

---

## Problemas frecuentes

| Problema | Solución |
|---|---|
| `http://localhost:8004/` da 404 | Entra a `/equipo4` |
| "No se puede conectar" | Abre Docker Desktop y espera 1–2 minutos |
| Docker se cerró solo | Ábrelo de nuevo; los contenedores se reinician solos |
| El desplegable de categorías sale vacío | Ejecuta `php artisan demo:seed` (crea los `slug` de las categorías) |
| Los cambios de diseño no se ven | `npm run build` y `Ctrl+F5` |
| Las páginas tardan unos 4 segundos | Es normal en Windows con Docker (carpeta montada), no ocurre igual en producción |
| El puerto 8004 o 27017 está ocupado | Apaga el otro proyecto con `docker compose down` |
| `docker exec ... mongosh` falla con `d is not defined` | Usa la versión corregida de `team4_init.js` (`db.` en vez de `d.`) |

---

## Estructura

```
app/
  Http/Controllers/   controladores del panel
  Api/                APIs de integración (equipos 3 y 7)
  Services/           reglas de negocio (reservas, costos, adaptadores)
  Console/Commands/   demo:seed
config/               team1.php, team4.php, team4_permissions.php
database/mongo/       scripts de MongoDB
docker/               nginx, php, supervisor, entrypoint
docs/                 documentación de integraciones
resources/js/         Vue + Inertia
routes/web.php        rutas del panel (/equipo4)
tests/Feature/Equipo4 pruebas automáticas
```

## Más información

- [`README_EQUIPO4_COMPLETO.md`](README_EQUIPO4_COMPLETO.md): descripción completa de los módulos 4.1 a 4.11.
- [`docker/README.md`](docker/README.md): detalles de los contenedores.
