# Guía: instalar Campus Digital E1–E7 en Windows con WSL2 y Docker

Esta guía es para los equipos que van a probar la plataforma en su propia computadora con **Windows 10 (22H2) o Windows 11**. Al terminar tendrás Campus Digital funcionando en `http://localhost:18080`, con las 31 cuentas de demostración de los siete equipos.

**Qué versión es:** la candidata `6710e59`, validada de punta a punta el 17 de noviembre de 2026 (ver `VERSION.txt`).

**Cuánto tarda:** la primera vez, entre 30 y 60 minutos (la mayor parte es instalar WSL2 y Docker y descargar las bases de datos). Después, arrancar toma un minuto.

> **Importante:** este paquete se probó de punta a punta en Linux con los mismos contenedores que usarás. **Todavía no se ha probado en una computadora con Windows.** Si algo falla, anota en qué paso y el mensaje exacto, y avísalo al equipo que te envió el paquete (sección «Si algo falla», al final).

---

## Requisitos

| Qué | Mínimo | Recomendado |
|---|---|---|
| Windows | 10 versión 22H2, o 11 | 11 |
| Memoria RAM | 8 GB | 16 GB |
| Disco libre | 15 GB | 25 GB |
| Procesador | 64 bits (Intel o AMD) con virtualización activada en la BIOS | — |
| Internet | Para instalar WSL2 y Docker y descargar dos imágenes de bases de datos (unos 2.5 GB) | — |

> Las computadoras con procesador ARM (por ejemplo algunas Surface) no sirven: SQL Server no tiene imagen para ARM.

---

## Paso 1 — Instalar WSL2 con Ubuntu

WSL2 permite ejecutar Linux dentro de Windows. Campus Digital se ejecuta ahí.

1. Abre **PowerShell como administrador** (clic derecho en el menú Inicio → «Terminal (Administrador)» o «Windows PowerShell (Administrador)»).
2. Ejecuta:
   ```powershell
   wsl --install -d Ubuntu
   ```
3. **Reinicia Windows** cuando te lo pida.
4. Al volver, se abre una ventana de Ubuntu que pide crear un **usuario y contraseña de Linux**. Elige los que quieras (no tienen que ser los de Windows) y apúntalos.
5. Comprueba que sea WSL **2**. En PowerShell:
   ```powershell
   wsl -l -v
   ```
   En la columna `VERSION` debe decir `2`. Si dice `1`, ejecuta `wsl --set-version Ubuntu 2`.

**Si ya tenías WSL:** ejecuta `wsl --update` en PowerShell y sigue al paso 2.

### Opcional pero recomendado: darle memoria suficiente a WSL

Si tu equipo tiene 8 GB de RAM, crea el archivo `C:\Users\<tu usuario>\.wslconfig` con este contenido (con el Bloc de notas) y luego ejecuta `wsl --shutdown` en PowerShell:

```ini
[wsl2]
memory=6GB
```

Con 16 GB o más no hace falta.

---

## Paso 2 — Instalar Docker Desktop

1. Descarga **Docker Desktop para Windows** desde <https://www.docker.com/products/docker-desktop/> e instálalo. Deja marcada la opción **«Use WSL 2 instead of Hyper-V»**.
2. Abre Docker Desktop y espera a que abajo a la izquierda diga **«Engine running»** (motor en marcha).
3. En Docker Desktop: **Settings → Resources → WSL integration** → activa **Ubuntu** → **Apply & restart**.
4. Comprueba desde Ubuntu (abre «Ubuntu» desde el menú Inicio):
   ```bash
   docker --version
   docker compose version
   ```
   Si ambos muestran una versión, todo está listo. Si dice «command not found», revisa el punto 3.

> Docker Desktop es gratuito para uso educativo y personal.

---

## Paso 3 — Copiar el paquete a tu carpeta de Linux

> **Esto es lo más importante de la guía.** El paquete debe quedar dentro de Linux (`~/A_PAQUETE_CAMPUS`), **no** en `C:\`, el Escritorio, Descargas ni OneDrive. Desde una carpeta de Windows es muy lento y el guion se niega a continuar.

Si recibiste el paquete como **`A_PAQUETE_CAMPUS.zip`** (o lo tienes en una memoria USB o en Descargas):

1. Abre **Ubuntu**.
2. Copia el archivo a tu carpeta de Linux. Ejemplo, si está en Descargas de Windows:
   ```bash
   cp /mnt/c/Users/<tu usuario de Windows>/Downloads/A_PAQUETE_CAMPUS.zip ~/
   cd ~
   sudo apt update && sudo apt install -y unzip
   unzip A_PAQUETE_CAMPUS.zip
   cd ~/A_PAQUETE_CAMPUS
   ```
   (Reemplaza `<tu usuario de Windows>` por el nombre de tu carpeta en `C:\Users`.)

Si recibiste la carpeta sin comprimir, cópiala entera:
```bash
cp -r /mnt/c/Users/<tu usuario de Windows>/Downloads/A_PAQUETE_CAMPUS ~/
cd ~/A_PAQUETE_CAMPUS
```

3. **Comprueba que el paquete llegó completo** (debe decir `La suma coincide` u `OK` en todas las líneas):
   ```bash
   sha256sum -c SHA256SUMS
   ```
4. Si abriste o editaste algún archivo del paquete con un programa de Windows, corrige los finales de línea:
   ```bash
   sed -i 's/\r$//' campus.sh docker/*.sh
   chmod +x campus.sh docker/*.sh
   ```

---

## Paso 4 — Instalar Campus Digital

Con Docker Desktop abierto, en Ubuntu y dentro de `~/A_PAQUETE_CAMPUS`:

```bash
./campus.sh instalar
```

Qué hace, en orden (verás un ✔ verde por paso):

1. Comprueba que estás en WSL2, en una carpeta de Linux y con Docker en marcha.
2. Carga la imagen de la aplicación incluida en el paquete y descomprime el código.
3. Genera contraseñas nuevas y privadas para las bases de datos (sólo en tu computadora, en `config/` y `.env`).
4. Descarga y arranca MongoDB y SQL Server (la primera vez, unos 2.5 GB: puede tardar según tu conexión).
5. Instala Campus Digital con las cuentas de demostración de los siete equipos (3 a 6 minutos).
6. Arranca la aplicación y te muestra la **dirección** y la **contraseña** de las cuentas.

Al final verás algo así:

```
Acceso (no compartas esta pantalla ni la pegues en chats)
  Dirección:   http://localhost:18080
  Usuario:     <cuenta>@campus.test
  Contraseña:  (una contraseña de 32 caracteres, distinta en cada computadora)
```

**Cada computadora genera su propia contraseña.** No es la misma que la de otro equipo ni la del laboratorio.

---

## Paso 5 — Entrar

1. Abre en el navegador de Windows: **<http://localhost:18080>**
2. Inicia sesión con cualquier cuenta de `docs/CUENTAS-POR-EQUIPO.md`, por ejemplo `demo.e3@campus.test`, y la contraseña que mostró el paso anterior.
3. Las cuentas de administración (`demo.e1`, `demo.e2.controles`, `demo.e5`, `demo.e7`…) **piden configurar el segundo factor la primera vez**: la pantalla muestra un código QR que escaneas con una aplicación de autenticación en tu teléfono (Google Authenticator, Microsoft Authenticator o similar). Después, cada inicio de sesión pide el código de 6 dígitos de esa aplicación.

Para volver a ver la dirección y la contraseña:
```bash
./campus.sh acceso
```

Qué demostrar en cada equipo, paso a paso: **`docs/GUIA-DEMOSTRACION-POR-EQUIPO.md`**.

---

## Uso diario

Todas las órdenes se ejecutan en Ubuntu, dentro de `~/A_PAQUETE_CAMPUS`, con Docker Desktop abierto.

| Quiero… | Orden |
|---|---|
| Arrancar después de apagar la computadora | `./campus.sh iniciar` |
| Detener (los datos se conservan) | `./campus.sh detener` |
| Ver si está funcionando | `./campus.sh estado` |
| Ver la dirección y la contraseña | `./campus.sh acceso` |
| Ver qué está haciendo la aplicación (para reportar un error) | `./campus.sh registros` (Ctrl+C para salir) |
| Empezar de cero (borra todos los datos de esta instalación) | `./campus.sh borrar-todo` y después `./campus.sh instalar` |

Si cierras Docker Desktop o apagas la computadora no se pierde nada: al volver, abre Docker Desktop y ejecuta `./campus.sh iniciar`.

---

## Si algo falla

| Síntoma | Qué hacer |
|---|---|
| `Ejecuta este guion dentro de WSL2` | Estás en PowerShell o CMD. Abre «Ubuntu» desde el menú Inicio |
| `El paquete está en una carpeta de Windows` | Repite el paso 3: el paquete debe estar en `~/A_PAQUETE_CAMPUS` |
| `finales de línea de Windows (CRLF)` | Ejecuta el comando que el mensaje indica (paso 3, punto 4) |
| `No encuentro «docker»` o `Docker no responde` | Abre Docker Desktop, espera «Engine running» y revisa *Settings → Resources → WSL integration* (paso 2) |
| `MongoDB no arrancó` | Ejecuta `docker compose logs mongo` y reporta las últimas líneas |
| SQL Server no responde o se detiene solo | Casi siempre es falta de memoria: revisa el `.wslconfig` (paso 1) y que el equipo tenga al menos 8 GB |
| El puerto 18080 está ocupado | Edita `.env`, cambia `CAMPUS_PUERTO=18080` por otro (por ejemplo `18081`) y ejecuta `./campus.sh iniciar`. La dirección cambia a ese puerto |
| La página dice «No pudimos verificar tu acceso» en Inventarios | Abriste más de 30 páginas en un minuto (límite conocido). Espera un minuto |
| La instalación falló a la mitad | Ejecuta `./campus.sh borrar-todo` y luego `./campus.sh instalar` |

**Para reportar un problema**, envía al equipo que te dio el paquete:
- en qué paso de esta guía ocurrió,
- el mensaje exacto (copia el texto, no una foto si puedes),
- la salida de `./campus.sh estado` y las últimas líneas de `./campus.sh registros`.

**Nunca envíes** el contenido de `config/campus.env`, de `.env` ni la salida de `./campus.sh acceso`: tienen contraseñas.

---

## Qué hay en el paquete

| Archivo o carpeta | Qué es |
|---|---|
| `LEEME-PRIMERO.md` | Resumen de una página |
| `GUIA-WINDOWS-WSL2-DOCKER.md` | Esta guía |
| `campus.sh` | La única orden que necesitas |
| `compose.yaml` | Los tres contenedores: aplicación, MongoDB y SQL Server (no hace falta tocarlo) |
| `docker/` | Guiones internos del contenedor de la aplicación |
| `imagen/campus-php-runtime-6710e59.tar.gz` | La imagen de la aplicación (PHP 8.5 con sus extensiones), la misma con la que se validó la candidata |
| `codigo/campus-6710e59.tar.gz` | El código de la candidata, con sus dependencias ya incluidas |
| `docs/` | Cuentas por equipo, guía de demostración por equipo y lo que todavía falta |
| `VERSION.txt` | Qué versión es y cómo se validó |
| `SHA256SUMS` | Para comprobar que el paquete llegó completo |

Se crean al instalar, y son **privados** (no los compartas): `config/campus.env`, `.env` y `codigo/campus/`.

## Detalles técnicos (para quien los necesite)

- La aplicación corre con el servidor de PHP con 8 procesos (`CAMPUS_PROCESOS_WEB` en `.env`): los módulos se llaman entre sí por HTTP dentro de la misma aplicación y necesitan más de un proceso. En el mismo contenedor corren la cola `comunidad` y el planificador.
- MongoDB 7 corre como conjunto de réplicas de un nodo (`rs0`), necesario para las transacciones. No tiene usuario ni contraseña porque **no se publica fuera de Docker**: sólo la ve la aplicación.
- SQL Server 2022 Developer, con un usuario propio para la base de Finanzas (`campus_financial`).
- Sólo la aplicación escucha, y sólo en `127.0.0.1`: nadie de tu red puede entrar a tu instalación.
- La instalación usa `php artisan campus:install --demo`, el mismo instalador validado en el laboratorio, con la configuración de laboratorio que enciende las integraciones entre módulos.
