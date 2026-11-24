# Campus Digital E1–E7 — paquete de pruebas para los equipos

Plataforma completa de los siete equipos (Identidad, Finanzas, Tienda, Inventarios, Servicios, Comunidad y Recompensas), lista para instalar en tu computadora con **Windows + WSL2 + Docker Desktop** (también funciona en Linux con Docker).

## En tres pasos

1. Instala WSL2 con Ubuntu y Docker Desktop, y copia esta carpeta a tu carpeta de Linux (`~/A_PAQUETE_CAMPUS`). Detalle: **`GUIA-WINDOWS-WSL2-DOCKER.md`**, pasos 1 a 3.
2. En Ubuntu, dentro de la carpeta: `./campus.sh instalar`
3. Abre <http://localhost:18080> y entra con una cuenta de **`docs/CUENTAS-POR-EQUIPO.md`** y la contraseña que muestra el paso 2.

## Documentos

| Documento | Para qué |
|---|---|
| `GUIA-WINDOWS-WSL2-DOCKER.md` | Instalación paso a paso, uso diario y qué hacer si algo falla |
| `docs/CUENTAS-POR-EQUIPO.md` | Las 31 cuentas de demostración, por equipo |
| `docs/GUIA-DEMOSTRACION-POR-EQUIPO.md` | Qué recorrer en cada equipo, paso a paso |
| `docs/PENDIENTES-73.md` | Los 73 requisitos del diseño que todavía faltan, con su motivo |
| `VERSION.txt` | Versión y cómo se validó |

## Lo que debes saber

- Es la candidata `6710e59`, validada en Linux. **Todavía no se ha probado en Windows**: si algo falla, sigue la sección «Si algo falla» de la guía y repórtalo.
- Todo es sintético: cuentas, saldos y negocios de demostración. No hay pagos reales.
- Cada computadora genera sus propias contraseñas. No compartas `config/`, `.env` ni la salida de `./campus.sh acceso`.
