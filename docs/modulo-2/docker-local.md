# Docker local de E2

## Alcance y estado

Entrega adicional al diagnostico de instalacion: un contenedor Linux amd64 para revisar la aplicacion existente y sus paginas Vue compiladas. No cambia servicios, rutas, permisos, dependencias fijadas en los locks ni codigo de otros equipos. El repositorio contiene tambien la identidad necesaria para iniciar sesion; se empaqueta su codigo existente sin modificarlo.

**Estado de entrega:** archivos revisados estaticamente; imagen, PowerShell y arranque pendientes de validacion en Docker Desktop. No se afirma que las bases o la integracion esten certificadas. No es un despliegue productivo: usa el servidor local de Laravel, sin HTTPS, alta disponibilidad ni copias de seguridad. No incluye dependencias PHP de desarrollo ni ejecuta la suite de pruebas.

El puerto publicado es `127.0.0.1:8082`. Tu servidor de `8000` puede seguir funcionando. La aplicacion Docker usa SQL Server y MongoDB externos; no instala ni modifica sus servicios en Windows. Las acciones normales que hagas en la web, incluido iniciar sesion, **si pueden escribir en las bases configuradas**. Docker no crea una copia de esos datos.

## Requisitos

- Docker Desktop iniciado, motor Linux y Compose V2 actualizado; perfil dirigido a Windows x64.
- Internet para descargar imagenes, paquetes Debian/Microsoft, Composer y npm.
- SQL Server y MongoDB existentes con esquema de desarrollo ya preparado. MongoDB debe conservar el replica set necesario para las transacciones.
- Puerto 8082 libre y credenciales de acceso a las bases. No compartas claves en capturas o salidas.
- La construccion instala Microsoft ODBC 18 mediante aceptacion de su licencia `ACCEPT_EULA=Y`, y las extensiones `pdo_sqlsrv 5.13.0` y `mongodb 2.4.1`.

Se usan etiquetas `php:8.5-cli-bookworm`, `node:24-bookworm-slim` y `composer:2`; sus parches pueden cambiar. Los locks de PHP/JS se respetan, pero esto no garantiza reproduccion binaria completa. Tras validar la imagen registra su ID y los digests; antes de produccion deben fijarse y revisarse esos componentes.

## 1. Verificar herramientas, sin arrancar

Desde `C:\Proyectos\Campus-Digital`:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tools\financial\Check-Docker.ps1 -ProjectPath "C:\Proyectos\Campus-Digital"
```

Comprueba Compose y el motor; no construye ni arranca. Si falta Docker, detente en este paso y resuelve su instalacion. No hay que parar los servidores PHP actuales.

## 2. Construir sin credenciales ni acceso a las bases

```powershell
docker compose --env-file docker/e2/compose.env -f docker/e2/compose.yaml build app
```

Puede tardar varios minutos: compila extensiones e instala dependencias. No ejecuta migraciones, generacion de claves, seeds ni Artisan durante la construccion. Composer se instala sin scripts/plugins ni dependencias de desarrollo y comprueba requisitos de plataforma. npm usa el lock; Vite se ejecuta expresamente para compilar Vue. Ziggy se copia desde las dependencias Composer porque `resources/js/app.js` lo importa desde `vendor`.

La lista de inclusion `Dockerfile.dockerignore` evita enviar `.env`, credenciales habituales, vendor, node_modules, cache y storage del host. No guardes secretos dentro de codigo fuente: los directorios app/config/etc. si se incluyen. La configuracion privada se monta al arrancar y no se incorpora a la imagen.

## 3. Configurar solo el archivo privado de Docker

Si aun no existe `docker/e2/local/.env`, copia la plantilla una sola vez:

```powershell
if (!(Test-Path .\docker\e2\local\.env)) { Copy-Item .\docker\e2\runtime.env.example .\docker\e2\local\.env }
```

```powershell
notepad .\docker\e2\local\.env
```

Completa APP_KEY y la conexion SQL; confirma los nombres de las bases y autenticacion Mongo. Conserva la **APP_KEY existente** si compartes usuarios con 2FA u otros datos cifrados. No ejecutes `key:generate` sobre esa instalacion. Edita solo este archivo; no reemplaces el `.env` raiz. `docker/e2/local/.gitignore` excluye su contenido de Git; compruebalo con `git status --short`.

Dentro del contenedor, `127.0.0.1` es el propio contenedor. Para los servicios Windows se propone `host.docker.internal`. Mongo usa `directConnection=true` para la conexion local a un unico miembro; no constituye una topologia productiva. Si Mongo escucha solo en loopback o SQL no admite TCP, la conexion puede ser rechazada. Revisa interfaces, puerto fijo, autenticacion y reglas de firewall de alcance limitado; esta entrega no cambia esos servicios ni abre puertos automaticamente. No expongas las bases a toda la red para resolverlo.

No uses bases `_testing` en este servidor web. No copies `.env.testing`. Las pruebas tienen procedimientos separados y pueden limpiar sus datos.

## 4. Iniciar y revisar

```powershell
docker compose --env-file docker/e2/compose.env -f docker/e2/compose.yaml up -d app
```

```powershell
docker compose --env-file docker/e2/compose.env -f docker/e2/compose.yaml ps
```

```powershell
docker compose --env-file docker/e2/compose.env -f docker/e2/compose.yaml logs --tail 80 app
```

Revisa los logs localmente antes de compartirlos. En el arranque se valida la presencia de configuracion minima sin imprimir claves y se ejecuta `package:discover`, que genera cache efimera del contenedor. No se migran bases, no se crean usuarios, no se conceden permisos y no se generan claves. Inicia sesion desde `http://127.0.0.1:8082`. La cookie tiene nombre distinto del servidor 8000; las sesiones Docker se guardan en su propio volumen.

El healthcheck solo comprueba un socket HTTP abierto; **no demuestra que Laravel, SQL, Mongo ni un pago funcionen**. Confirma manualmente acceso, pagina Finanzas, datos esperados y un comprobante existente/PDF. No hagas emisiones o movimientos para verificar el despliegue. Si hay error HTTP, revisa los logs; no se habilita debug automaticamente.

Los interruptores de E1, Marketplace, BECA en checkout, E4 y tareas programadas se fuerzan a false en Compose incluso si cambias el archivo privado. No hay workers ni scheduler. La denegacion de operaciones administrativas pendientes sigue siendo el comportamiento correcto. Habilitar una integracion requiere una entrega posterior con su configuracion y autorizacion aprobadas.

## 5. Detener y actualizar

```powershell
docker compose --env-file docker/e2/compose.env -f docker/e2/compose.yaml down
```

Conserva el volumen de storage y no toca las bases externas. No uses `down -v` si necesitas conservar sesiones, archivos y cache de ese volumen. El codigo se copia en la imagen: despues de cambios reconstruye con `build app` y ejecuta `up -d app`; no hay sincronizacion de codigo ni Vite dev server. No se modifica `public/build` en Windows.

Para registrar la imagen validada:

```powershell
docker image inspect campus-e2-local:review --format '{{.Id}}'
```

## Referencias oficiales consultadas (2026-11-09)

- https://hub.docker.com/_/php — imagenes PHP y compilacion de extensiones.
- https://pecl.php.net/package/pdo_sqlsrv/5.13.0 — compatibilidad PHP 8.5/Debian 12.
- https://pecl.php.net/package/mongodb/2.4.1 — version del driver.
- https://learn.microsoft.com/en-us/sql/connect/odbc/linux-mac/installing-the-microsoft-odbc-driver-for-sql-server — ODBC y licencia.
- https://docs.docker.com/build/concepts/context/ — exclusion especifica por Dockerfile.
- https://docs.docker.com/desktop/features/networking/ — acceso al host.
- https://getcomposer.org/doc/03-cli.md — install y comprobacion de plataforma.
