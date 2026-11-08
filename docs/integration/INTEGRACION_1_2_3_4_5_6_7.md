# Campus Digital: integración local de los siete equipos

Fecha: 27 de octubre de 2026. Copia de trabajo: `/home/daniel/campus_digital_integracion_e1`.
Dirección: http://127.0.0.1:8086. Rama de integración: `MERGE_COMPLETO` (antes `MERGE-1-3-4-5-6`).
Publicación y renombrado solicitados por el usuario el 27 de octubre de 2026.
El proyecto principal `/home/daniel/campus_digital` se conserva sin modificaciones.

Actualización del 30 de octubre: [avance E4 a3ea96d](INCORPORACION_E4_a3ea96d_2026-10-30.md), con transporte HMAC preparado, pendiente de activación y cierre funcional de canjes.

## Fuentes incorporadas

- E2: `feat/modulo-2-equipo-2`, `7460d47`, incluida la nueva consulta OAuth de autorización de bonos, apagada por defecto.
- E7: `Equipo-7`, `e48031e`, backend de `backend_respaldo/` y vistas de `frontend_respaldo/src/`.
- Se conservan Identidad E1 y las integraciones locales de Marketplace, Inventarios, Servicios y Comunidad.
- El ZIP de cliente financiero de E3 fue revisado anteriormente. Esta entrega no sustituye su coordinador de pedidos por ese cliente ni activa el cobro.

## Qué se incorporó

**Finanzas.** Dominio, modelos, servicios, APIs, comprobantes, pantallas y comandos de E2.
SQL Server Developer 2022 exclusivo del alterno, sin puerto publicado, con volumen persistente.
Bases separadas: `campus_digital_financial` y `campus_digital_financial_testing`.
La cuenta SQL de la aplicación tiene ámbito sobre esas bases; no es una autoridad financiera humana.
Se ejecutaron las 51 migraciones financieras del corte, sin borrar ni reseedear las bases existentes.

Las pantallas `/finanzas`, `/finanzas/bonos`, `/finanzas/comprobantes`, `/finanzas/compras`,
transferencias, caja, controles y ajustes usan el login E1. Cada cartera se consulta por
el `User._id` de su dueño. Una cuenta sin cartera muestra ese estado; no se inventan fondos.
Los permisos administrativos pendientes de E2 siguen denegados, incluso para un admin E1.

**Recompensas y plataforma.** Se incorporaron las 22 páginas E7 como páginas Inertia,
con navegación común, APIs bajo `/api/v1/recompensas` y conexión Mongo `platform`.
Su base deriva de la del alterno con sufijo `_plataforma`; no se duplican usuarios ni contraseñas.
Las pruebas Mongo mantienen el destino obligatorio `campus_virtual_testing`.

El adaptador local consume la proyección pública de roles efectivos de E1 para la sesión
actual: `estudiante` → alumno, `maestro` → profesor, `admin` global → administración E7.
Un cargo de Comunidad o un rol limitado a un negocio no se convierte en admin global.
No se crean roles E1, se eliminan el login paralelo y el cambio de usuario demo de E7.
No hay tokens de sesión en almacenamiento del navegador ni métricas simuladas de respaldo.

Se corrigieron controles de acceso al historial de canje, detalle de auditoría y correlación;
reintentos del canje; y transacción conjunta para puntos, stock local y comprobante.
Un canje reintentado con la misma clave obtiene el mismo registro. Cambiar su contenido
bajo esa clave produce conflicto. Un fallo posterior al débito revierte el conjunto.
Las validaciones físicas contra E4 y concurrencia multiproceso requieren una fase posterior.

## Conexión Comunidad → E7 preparada

`comunidad:publicar-plataforma --limit=100` lee únicamente la auditoría propia de E6
y envía por HTTP `POST /api/v1/plataforma/auditoria` una proyección mínima:
IDs del actor/organización/registro, acción y fecha original. No envía antes/después
crudos, expedientes, archivos, nombres, correos, contraseñas ni tokens.
Los registros históricos no guardan siempre el cargo: se declara `actor_role=not_recorded`,
no se atribuye una autoridad que no está documentada.

El transporte local usa firma HMAC, dominio emisor y fecha con tolerancia de cinco minutos.
Es una adaptación **local**, no un nuevo contrato institucional aceptado por los equipos.
E7 compara una huella canónica: repetición idéntica es duplicado y contenido cambiado es 409.
El origen de auditoría sale del transporte autenticado, no de un campo libre del navegador.
El recibo debe confirmar el ID enviado; fallos y respuestas desconocidas quedan para reintento.
El acuse de auditoría no asigna puntos ni acredita un pago.

**Activado con autorización explícita del usuario el 27 de octubre:**
`PLATFORM_INGEST_ENABLED=true` y `PLATFORM_DELIVERY_ENABLED=true` en el `.env`
privado del alterno. Su scheduler fue reiniciado. La configuración de ejemplo y
el entorno de pruebas conservan ambas banderas apagadas.

Se entregaron **71 auditorías históricas**, con 71 acuses en E6 y 71 registros E7
atribuidos a `community`, sin pendientes fallidos. Prueba HTTP real: repetir el
mismo sobre devuelve 200/duplicado; cambiar el contenido con el mismo ID devuelve
409; repetir el exportador entrega cero registros nuevos. Puntos y wallets/saldos
financieros permanecieron en cero. No se modificaron roles/scopes, autoridades ni
permisos financieros. Evidencia: `activacion-verificada.json` en los logs de esta entrega.

## Qué continúa pendiente

1. E1/E2: autoridad institucional verificable, actor humano, consentimiento, delegaciones,
   receptores y fuentes de financiamiento. Los proveedores `Pending*` siguen cerrados.
2. E2/E3/E4: el checkout con wallet y la conciliación de la reserva se probaron en laboratorio.
   Queda aprobar el cliente de E3, la cuenta receptora del negocio, el transporte firmado y
   la autoridad para ejecutar devoluciones.
3. Regla API: consultas financieras personales pertenecen a E2. E7 no consulta colecciones
   de usuarios de E1. El corte E2 aún contiene adaptadores y servicios con consultas directas
   a usuarios/sesiones/clientes de E1; los flujos afectados siguen sin habilitarse.
   La proyección de rol de sesión usa la API interna pública de E1 en la misma aplicación;
   el directorio HTTP y la autorización institucional final siguen pendientes.
4. E4 → E7: transporte HMAC probado en laboratorio, pendiente de habilitar y acordar los temas del piloto.
   E5 → E7: confirmar el piloto de auditoría y su alcance antes de habilitarlo.
   Recompensas físicas requieren contrato de inventario y compensación.
5. No se importaron usuarios/datos financieros de demostración de E2/E7 ni se depositó saldo.
   Las cuentas documentadas existentes conservan el login y segundo factor de E1.

## Piloto de auditorías de Servicios al Estudiante

E5 prepara una proyección de `library.fine.waived`, `library.fine.cancelled` y `rental.deposit.charged` hacia `POST /api/v1/plataforma/auditoria`. Comparte el ID del evento, el actor cuando tiene un ID canónico, la acción, el tipo e ID de entidad, la fecha y una correlación derivada del evento. Los motivos, importes, detalles del dispositivo y demás datos de la auditoría original se conservan en E5.

El comando `servicios:publicar-auditoria --limit=20` firma el cuerpo con la clave del dominio `services`. Solo marca la auditoría publicada si E7 devuelve el mismo ID y un acuse de aceptación o de duplicado. Los fallos permanecen pendientes y rotan detrás de los registros que aún no se han intentado.

La configuración `PLATFORM_SERVICES_DELIVERY_ENABLED` queda apagada por defecto. Para habilitar el piloto se debe acordar el catálogo de acciones, el origen `PLATFORM_SERVICES_API_URL`, la credencial `PLATFORM_SERVICES_SECRET` y el corte inclusivo `PLATFORM_SERVICES_DELIVERY_SINCE` en UTC con formato `YYYY-MM-DDTHH:MM:SSZ`. El corte determina los registros elegibles y permite pactar por separado el envío histórico. El receptor de E7 debe estar habilitado para ese origen. El acuse registra auditoría y no asigna puntos.

La prueba por HTTP en una red desechable entregó una auditoría E5 y un evento de vencimiento de reserva E4. Repetir ambos envíos conservó un único registro por evento en E7. El registro de puntos quedó vacío. En el alterno se verificó que el receptor E7 está activo y que los emisores de E4 y E5 quedan desactivados para estos pilotos.

## Alistamiento de cobros de Marketplace y Biblioteca

La comprobación del 8 de noviembre encontró en SQL Server del alterno las tablas `marketplace_payment_requests`, `library_fine_payment_requests` y `financial_team4_outbox`. Los dos cobros y el transporte de pagos E2 → E4 permanecen desactivados. Antes de habilitarlos, confirmar los siguientes datos.

| Piloto | Acuerdos necesarios |
| --- | --- |
| Marketplace | Cliente de E3 con `financial:purchase:prepare`, `financial:purchase:read` y `financial:purchase:cancel`, wallet del negocio y destino firmado para el evento de E2 a E4. |
| Biblioteca | Cliente de E5 con `financial:library-fine:prepare`, `financial:library-fine:read` y `financial:library-fine:cancel` y wallet institucional de Servicios al Estudiante. |
| Auditoría E7 | Destino y credencial separados por dominio, más el alcance y corte de fecha del piloto de E5. |

Los casos a repetir al activar se describen en [Marketplace e Inventarios](INCORPORACION_E4_a3ea96d_2026-10-30.md) y [Multas de Biblioteca](API_E2_MULTAS_BIBLIOTECA.md). Los [mensajes para los equipos](MENSAJES_EQUIPOS_2026-11-08.md) reúnen las solicitudes de confirmación.

## Preparación y verificación reproducibles

Desde el alterno, con Docker Desktop conectado a Ubuntu:

```bash
python3 scripts/integracion/preparar_sqlserver.py
docker compose -f compose.integracion.yaml exec --user 1000:1000 app php artisan migrate --database=sqlsrv --path=database/migrations/financial --force
docker compose -f compose.integracion.yaml exec --user 1000:1000 app php artisan migrate --database=platform --path=database/migrations/platform --force
docker compose -f compose.integracion.yaml exec --user 1000:1000 app php artisan migrate --path=database/migrations/2026_10_27_000001_create_community_platform_deliveries.php --force
docker compose -f compose.integracion.yaml exec --user 1000:1000 app npm run build
```

Las contraseñas SQL se generan localmente en `.env`, nunca se incluyen en este documento.
El servicio SQL tiene reinicio automático desactivado: encenderlo tras reiniciar Docker.
Para pruebas nuevas, preparar únicamente el SQL de pruebas con
`SQLSRV_DATABASE=campus_digital_financial_testing` y ejecutar las baterías en serie.
No ejecutar `migrate:fresh` ni apuntar las pruebas al SQL de presentación.

Evidencia local y resultados: `storage/logs/integracion-20261027/`.
Regresión completa del conjunto: **1,368 pruebas, 9,634 aserciones**, sin fallos,
errores ni omisiones (XML `regresion-completa.xml`). Tras la revisión final de dos
casos adicionales, se ejecutó la batería afectada E2/E7: **111 pruebas y 511
aserciones**, todas aprobadas (`e2-e7-final.xml`). Son ejecuciones con casos
compartidos; no se suman como si fueran pruebas distintas.

Se corrigió además la resolución prematura de una dependencia pendiente E1 en
la delegación de comprobantes, mostrando el aviso de indisponibilidad, y se dejó
el reprocesamiento externo de webhooks en 503 sin cambiar su estado: el corte
original sólo los marcaba procesados, sin ejecutar un manejador real.

Navegador Edge aislado, login E1 del estudiante demo: diez pantallas reales de
Finanzas/Recompensas, sin errores JavaScript ni errores en consultas E7. Comprobación
móvil a 390 px sin desbordamiento horizontal. El panel E7 administrativo fue probado
mediante APIs/fixtures; no se modificó el enrolamiento MFA del admin para entrar en
su navegador. Se preserva ese requisito de E1.

## Reversión

El respaldo de código anterior es `campus-antes-e2-e7-20261027.tar` en los artefactos de esta conversación.
Para apagar el transporte, conservar ambas banderas E7 en `false` y reiniciar sólo el scheduler alterno.
El SQL puede detenerse sin borrar su volumen. No revertir migraciones eliminando historiales financieros
ni borrar bases para regresar a la presentación anterior. El proyecto principal no se modificó.
