# Primera integración de Identidad, Servicios al estudiante y Comunidad

Entorno alterno: `/home/daniel/campus_digital_integracion_e1`, rama local `integracion/equipo1-servicios-comunidad`, aplicación `http://127.0.0.1:8086`. El checkout y los contenedores originales de `/home/daniel/campus_digital` se conservan.

## Referencias

- Base local de integración E1/E6: `b2da6a0ecb3cadec801e4e62629d4ee0bb3d091d`.
- Identidad del equipo 1: versión final ya adaptada en esa base; se conservan login, Fortify, sesiones, QR/NFC, roles vigentes, cambio inicial de contraseña y política 2FA.
- Servicios del equipo 5: `equipo5/completa-modulo-5`, commit `257baeb67ff72c4efd6fe04db05f81fe75351835` (avance publicado en su rama; no implica que su PR esté aprobado).
- Se importaron exclusivamente sus modelos, servicios, controladores, requests, rutas de dominio, vistas, semillas, migraciones de dominio y pruebas. Se adaptaron los puntos de integración. No se sustituyeron configuración base, modelos de identidad, proveedores Fortify ni vistas de autenticación con el prototipo del equipo 5.

## Flujos conectados

| Emisor | API consumida | Resultado |
|---|---|---|
| E5 → E1 | condición académica, QR y NFC | Identificación real y estado académico, sin leer colecciones de Identidad desde los adaptadores de E5 |
| E6 → E5 | asignaciones de beneficios | Becas de lockers e impresiones con recurso/saldo real; E6 conserva el recibo de E5 |
| E5 → E6 | avisos individuales | Bandeja y campana comunes con preferencias, reintentos y retiro |

Se usan solicitudes HTTP reales aun siendo una aplicación Laravel. E5 accede a sus colecciones MongoDB y E6 a las suyas. El principal identificador externo es `User._id`. El contexto del usuario y la autorización de la sesión provienen del módulo 1.

Las becas aprobadas se guardan primero en Comunidad y se entregan después con `comunidad:entregar-beneficios`. Se conserva la clave `beca:{solicitud_id}` en todos los reintentos, así una respuesta perdida no duplica el recurso. Los beneficios monetarios de E2 siguen pendientes. El cron no cambia la aprobación ante indisponibilidad de E5. La UI muestra si el servicio ya fue asignado. Las fechas de Servicios sin zona se interpretan en America/Mexico_City; el reloj global de Identidad conserva UTC. Las nuevas solicitudes verifican el perfil estudiantil mediante la API de E1.

## Preparar otro entorno local

Con la imagen de integración y MongoDB en réplica:

Desde la carpeta de integración, con el contenedor `app` iniciado:

```bash
docker compose -p campus_integracion_e1 -f compose.integracion.yaml exec -T app php artisan migrate --force
docker compose -p campus_integracion_e1 -f compose.integracion.yaml exec -T app php artisan integracion:preparar-servicios
docker compose -p campus_integracion_e1 -f compose.integracion.yaml exec -T app php artisan config:clear
docker compose -p campus_integracion_e1 -f compose.integracion.yaml exec -T app php artisan db:seed --class=ServiciosIntegracionSeeder
docker compose -p campus_integracion_e1 -f compose.integracion.yaml exec -T app npm run build
docker compose -p campus_integracion_e1 -f compose.integracion.yaml up -d scheduler
```

El comando de preparación usa el generador OAuth del equipo 1, guarda secretos solo en `.env` y no los imprime. Crea tres clientes: E5→Identidad (`students:read`, `identity:qr:validate`, `identity:nfc:validate`), E6→Servicios (`services:benefits:read/write`), E5→Comunidad (`notifications:send/read`). Las URLs internas usan `http://app`, accesible tanto desde app como scheduler. `.env` y credenciales no se versionan.

El scheduler ejecuta cada minuto `servicios:avisos` y `comunidad:entregar-beneficios`. Es independiente del worker de campañas existente. Las migraciones añaden índices; no se vacía la base local. Las semillas de integración son repetibles y no reemplazan identidades ni datos existentes.

## Permisos y dependencias pendientes

- Alumnos: sus préstamos, multas, lockers, solicitudes, órdenes, tickets y reservas; solo acciones personales autorizadas. Los catálogos generales son consultables.
- Operaciones administrativas: el rol **admin** vigente de Identidad. Se aplica su 2FA requerido. El catálogo final de E1 aún no tiene un rol de operador de biblioteca/servicios; esta integración no inventa ni asigna ese rol. `student_manager` no se convierte en operador de E5.
- La creación/edición de catálogos, multas, promoción de listas, entrega física, reglas, bloqueos y gestión de tickets están protegidas. Las preferencias y mensajes solo pertenecen a su usuario.
- Wallet E2: pago de multas, lockers, órdenes y depósitos todavía no conectado. La integración bloquea confirmaciones manuales de cobro y usa `PendingStudentPaymentGateway`; no genera movimientos financieros simulados. Las órdenes y solicitudes pueden quedar pendientes; los servicios becados no requieren un cobro ficticio.
- Inventario E4 y auditoría central E7 no forman parte de esta fase. Se mantienen los registros propios de Servicios sin anunciarlos como integración con esos equipos.
- El módulo 6 heredado aún tiene adaptadores de permisos/existencia de usuarios señalados como pendientes en `COMUNIDAD-EQUIPO-1.md`. Esta primera integración no certifica que todo el código previo de Comunidad ya cumpla la regla de APIs exclusivamente.
- Esta rama no se ha publicado ni fusionado en `Testings` o `main`.

Contrato de avisos: [API y ejemplos](API_AVISOS_EQUIPO_5.md), [OpenAPI](comunidad-notificaciones.openapi.json). Contrato original de beneficios de E5: carpeta [equipo5](equipo5).

## Datos y comprobación local

Usa las cuentas de demostración ya configuradas en el entorno alterno. El alumno `estudiante@campus.test` tiene un préstamo de Código limpio, su aviso en la bandeja y un saldo de 20 impresiones generado mediante la API real de E5. La asignación tiene referencia `demo-integracion-156-impresiones`; es un dato de prueba local. Existe también un aviso de verificación de concurrencia. No se incluyen credenciales en este documento.

Se comprobó con HTTP real la consulta de perfil de E1, la asignación y consulta de beca en E5, el envío y consulta del aviso en E6 y dos envíos simultáneos con la misma clave. Se comprobó el login y las pantallas principales con sesión y cookies CSRF reales; al cerrar sesión la raíz dirige a `/login`. La compilación Vite terminó correctamente. El scheduler ejecuta los comandos cada minuto.

Validación final: 115 pruebas pasaron (995 aserciones) en la ejecución conjunta de AvisosServiciosTest, IntegracionServiciosComunidadTest y la carpeta StudentServices. La revisión previa de becas, comunicación e integración de Identidad pasó 42 pruebas adicionales; la diferencia de un fixture de Servicios detectada allí se corrigió y quedó cubierta por la ejecución final. No se ejecutó toda la suite global de los siete módulos.

Revisión del 9 de octubre tras retomar el entorno: se corrigió la validación de asunto/texto y del identificador estudiantil para rechazar entradas JSON malformadas con 422 antes de consultar Identidad o crear registros. La ejecución afectada pasó 30 pruebas (327 aserciones): AvisosServiciosTest, IntegracionServiciosComunidadTest e IntegracionIdentidadComunidadTest. Las 115 pruebas anteriores corresponden a la revisión extensa previa; ambos grupos se solapan y sus cantidades no deben sumarse.

Los nombres de rutas de E5 usan `module5.*`; la pantalla `/student-services` y el nombre `student-services.index` de Identidad permanecen independientes de `/servicios-estudiante`. Se verificó esta separación con una prueba de regresión. La base E1 ya tenía nombres compartidos entre GET/POST de `password.confirm` y GET/PATCH de `student-services.students.preferences`; no se modificaron como parte de esta integración.
