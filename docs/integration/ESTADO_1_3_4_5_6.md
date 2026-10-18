# Entrega integrada de Campus Digital — equipos 1, 3, 4, 5 y 6

Corte de publicación: **15 de octubre de 2026**, America/Mexico_City.
Rama: **`MERGE-1-3-4-5-6`**.

## Alcance

Esta entrega conserva la aplicación y autenticación del equipo 1 e incorpora los
dominios de los equipos 3, 4 y 5 junto a Comunidad del equipo 6. Las interfaces
comparten navegación y sesión; las conexiones entre dominios utilizan APIs.

| Equipo | Incorporado | Comportamiento y límites |
|---|---|---|
| 1 · Identidad | Sí | Login, perfiles, credenciales, sesiones, roles efectivos y APIs. Persisten contratos pendientes de directorio y autorización institucional para E6. |
| 2 · Finanzas | No | No se instala SQL Server ni se ejecutan cobros, bonos o reembolsos. |
| 3 · Marketplace | Sí | Negocios, tiendas, catálogo, carrito, pedidos pendientes, reserva real de inventario, cancelación y recuperación. Cobro y devolución financiera pendientes. |
| 4 · Inventarios | Sí | Negocio/actor reales, existencias, almacenes, compras, recepción parcial/total, reservas y movimientos. Confirmación financiera y canjes pendientes. |
| 5 · Servicios | Sí | Biblioteca, lockers, espacios, renta, impresión y soporte. Estado/credenciales E1 y avisos/beneficios E6 conectados. Pagos y garantías pendientes. |
| 6 · Comunidad | Sí | Organizaciones, cargos, becas, eventos/Staff, campañas, bandeja, consultas y transparencia. Continúan los faltantes funcionales del informe. |
| 7 · Recompensas y auditoría | No | APIs de preparación y auditorías propias no representan puntos, canjes o auditoría central conectados. |

## Flujos conectados

- E5 consulta condición académica y valida QR/NFC mediante las APIs de E1.
- E6 entrega becas de locker/impresiones a E5; conserva recibo y reintenta con la
  misma clave de idempotencia.
- E5 entrega avisos a la bandeja/campana de Comunidad, con preferencias y retiro.
- E3 consulta cargos vigentes de E6 al solicitar una tienda de organización y al
  aprobarla; aprovisiona al solicitante como propietario mediante la API de E1.
- E3 consulta inventario de E4 y reserva existencias por 15 minutos. Cancelación,
  vencimiento y respuestas perdidas se gestionan conservando pedido y referencias.
- E3/E4 comprueban la autorización vigente de E1 en el negocio correspondiente.

## Referencias de origen

| Dominio | Rama de origen | Commit |
|---|---|---|
| E1 | `feat/modulo-1-equipo-1` | `f41c780` más corrección de consentimientos de `8611154` |
| E3 | `equipo3_negocios` | `b9534b9` |
| E4 | `Modulo_4` | `1dec1fc` |
| E5 | `equipo5/completa-modulo-5` / `Equipo-5` | `257baeb` / mismo árbol en `ea7f0dc` |
| E6 | Avance propio e integración previa E1/E6 | Base local `b2da6a0` más los cambios de esta entrega |

Se importan y adaptan los dominios; esto no es un merge indiscriminado de las
aplicaciones independientes de cada equipo. Las adaptaciones y pruebas forman
parte del código publicado en esta rama.

## Preparación del entorno

Se utiliza `compose.integracion.yaml`, MongoDB en réplica y la imagen de
integración PHP. El acceso local es `http://127.0.0.1:8086/login`.

La imagen requiere la base `sail-8.5/app` disponible. Una copia nueva necesita
instalar Composer/npm, preparar su `.env` privado, inicializar su propia réplica,
ejecutar migraciones y provisionar los clientes autorizados del entorno. Consultar
las guías de cada integración antes de ejecutar comandos de preparación/demo.
La entrega no contiene las credenciales ni los datos de la laptop de origen.

Comandos de uso habitual **una vez preparado el entorno**:

```bash
docker compose -f compose.integracion.yaml up -d
docker compose -f compose.integracion.yaml exec -T --user 1000:1000 app npm run build
docker compose -f compose.integracion.yaml exec -T --user 1000:1000 app php artisan test --compact
```

Las pruebas solo pueden usar `campus_virtual_testing` en el MongoDB del alterno;
no ejecutar varias suites a la vez contra esa misma base. Los comandos PHP/npm
deben ejecutarse como UID/GID `1000:1000` para conservar la propiedad de cachés.
No utilizar `down -v` para detener una copia cuyos datos se necesiten conservar.

## Documentación y pendientes

- [Solicitud a E1: directorio y autorización institucional](SOLICITUD_EQUIPO6_A_EQUIPO1_DIRECTORIO_Y_AUTORIZACION_2026-10-17.md):
  necesidades D1–D4 e I1–I3 para el líder y agente de Identidad.

- [Revisión de APIs E1/E2](REVISION_APIS_E1_E2_2026-10-17.md):
  ramas contrastadas después del comité; validación del estado académico y dependencias pendientes de directorio, segmentación y pagos.

- [Comité de becas por convocatoria](COMITE_BECAS_COMUNIDAD.md):
  revisores con recomendaciones y un responsable del dictamen; acceso limitado al expediente enviado.

- [Revisión de archivos privados](REVISION_ARCHIVOS_PRIVADOS_COMUNIDAD.md):
  diagnóstico local de referencias, archivos faltantes e historial; sin reparación automática.

- [Archivos privados de becas](ARCHIVOS_PRIVADOS_BECAS_COMUNIDAD.md):
  consistencia de carga/retiro, conservación ante resultado incierto y limpieza posterior al commit.

- [Dictámenes de becas y consistencia](DICTAMENES_ATOMICOS_BECAS_COMUNIDAD.md):
  transacción local para solicitud, auditoría y preparación de su beneficio.

- [Acceso NFC de Comunidad](ACCESO_NFC_EVENTOS_COMUNIDAD.md): integración de Staff con la API de E1, activada con autorización explícita solo en el alterno; lector físico pendiente.

- [Actualización de equipos del 17 de octubre](ACTUALIZACION_EQUIPOS_2026-10-17.md):
  revisión remota, correcciones E4 y preparación de recibos v2/transporte E7, sin activar finanzas.

- [Reservas y reinscripciones de eventos](RESERVAS_EVENTOS_COMUNIDAD.md):
  incremento local del 17 de octubre; vencimiento de pago pendiente a 15 minutos,
  espera FIFO y solicitudes protegidas por generación.

- [Periodos de asociaciones y Consejo](PERIODOS_ORGANIZACIONALES_COMUNIDAD.md):
  incremento local del 16 de octubre; registro de periodos, cargos vinculados
  con fechas limitadas e historial de cambios.

- [Comisiones y documentos de Comunidad](COMISIONES_Y_DOCUMENTOS_COMUNIDAD.md):
  incremento local del 16 de octubre, posterior al corte publicado; comisiones
  del Consejo y expediente documental privado con versiones y retiro.

- [Permisos por cargo y delegaciones operativas de Comunidad](PERMISOS_Y_DELEGACIONES_COMUNIDAD.md):
  incremento local validado el 16 de octubre, posterior al corte publicado
  de este documento; no representa nuevas autorizaciones financieras.
- [Marketplace y validación de la integración 1–3–4–5–6](INTEGRACION_1_3_4_5_6.md).
- [Inventarios](INTEGRACION_1_4_5_6.md).
- [Servicios y beneficios/notificaciones](INTEGRACION_1_5_6.md).
- [Dependencias pendientes de Identidad](Dependencias_Equipo_1_Final.md).
- [Informe de los 74 submódulos](AUDITORIA_FUNCIONAL_E_INTEGRACION_2026-10-15.md),
  [PDF](AUDITORIA_FUNCIONAL_E_INTEGRACION_2026-10-15.pdf) y
  [cálculos](AUDITORIA_FUNCIONAL_E_INTEGRACION_2026-10-15.json).

Los informes fechados y los campos `pushed: false` de snapshots anteriores
describen el momento en que se generaron. Esta entrega permite conservar esa
evidencia histórica y publicar la implementación posterior sin reescribir los
informes ni sus PDFs. Publicar esta rama no fusiona el avance en `main` o `Testings`.

## Validación previa a esta publicación

Ejecutada el 15 de octubre de 2026 contra el MongoDB de pruebas del alterno,
de forma serial y sin utilizar las bases de demostración:

- Suite completa disponible en esta aplicación: **1,119 pruebas aprobadas,
  7,933 aserciones, cero fallos**. Duración: 1001.18 segundos.
- `npm run build`: compilación de producción correcta.
- `composer validate --no-check-publish`: correcto.
- Análisis de sintaxis PHP: 612 archivos correctos.
- Revisados los archivos de publicación frente a las credenciales privadas
  del entorno; `.env`, dependencias, datos, logs y respaldos quedan excluidos.

Implementación verificada: [`21e26a2`](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/commit/21e26a2e8edee4cfd1f226c55871cfec48742e49).
La suite corresponde al código integrado 1–3–4–5–6. No incluye ejecución
del módulo financiero en SQL Server ni del módulo 7; tampoco certifica
hardware NFC, proveedores de pago o consistencia distribuida en producción.

Registros locales, excluidos de Git: `storage/logs/publicacion-20261015-tests.log`
y `storage/logs/publicacion-20261015-build.log`.
