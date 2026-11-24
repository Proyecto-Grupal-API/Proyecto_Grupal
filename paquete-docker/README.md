# Paquete Docker de Campus Virtual (pruebas para los equipos)

Archivos del paquete `A_PAQUETE_CAMPUS` que instala la plataforma E1–E7 completa
en Windows con WSL2 y Docker Desktop (también en Linux con Docker). Levanta tres
contenedores: la aplicación Laravel, MongoDB 7 con réplica y SQL Server 2022.
La aplicación sólo escucha en `127.0.0.1:18080`.

| Archivo | Para qué |
| --- | --- |
| [`LEEME-PRIMERO.md`](LEEME-PRIMERO.md) | Instalación en tres pasos |
| [`GUIA-WINDOWS-WSL2-DOCKER.md`](GUIA-WINDOWS-WSL2-DOCKER.md) | Instalación paso a paso, uso diario y qué hacer si algo falla |
| `campus.sh` | `instalar`, `iniciar`, `detener`, `estado`, `acceso`, `registros`, `borrar-todo` |
| `compose.yaml` | Servicios `app`, `mongo` y `sqlserver`, con las imágenes de base fijadas por huella |
| `docker/` | Arranque de la aplicación, carga del entorno y creación de las bases |
| [`docs/CUENTAS-POR-EQUIPO.md`](docs/CUENTAS-POR-EQUIPO.md) | Las 31 cuentas de demostración |
| [`docs/GUIA-DEMOSTRACION-POR-EQUIPO.md`](docs/GUIA-DEMOSTRACION-POR-EQUIPO.md) | Recorrido de cada equipo |
| [`docs/PENDIENTES-73.md`](docs/PENDIENTES-73.md) | Requisitos del diseño que faltan, con su motivo |
| [`VERSION.txt`](VERSION.txt) | Versión y cómo se validó |

## Lo que no está en Git

- `imagen/campus-php-runtime-6710e59.tar.gz` (unos 228 MB): la imagen de la
  aplicación (PHP 8.5 con mongodb, sqlsrv, pdo_sqlsrv y gd) supera el límite de
  100 MB de GitHub y se distribuye con el paquete.
- `.env` y `config/campus.env`: `campus.sh` los genera en cada máquina con sus
  propias contraseñas. No se comparten.
- `SHA256SUMS`: las huellas del paquete distribuido, que incluyen la imagen y el
  tar del código.

## Armar el paquete desde el repositorio

`campus.sh` espera esta estructura dentro de WSL (no en `/mnt/c`):

```
~/A_PAQUETE_CAMPUS/
├── campus.sh, compose.yaml, docker/, docs/   ← esta carpeta
├── imagen/campus-php-runtime-6710e59.tar.gz  ← se distribuye aparte
└── codigo/campus/                            ← la raíz de este repositorio (rama MERGE_COMPLETO)
```

Si `codigo/campus` viene de un clon y no del paquete, faltan `vendor/` y
`public/build/`. Hay que instalarlos (`php composer.phar install` y `npm ci` con
`npm run build`) antes de `./campus.sh instalar`.

Los guiones necesitan finales de línea LF. Si Git los convirtió a CRLF en Windows,
`campus.sh` lo detecta e indica cómo corregirlo.
