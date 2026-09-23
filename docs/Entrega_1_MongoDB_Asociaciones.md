# Entrega 1: MongoDB y organizaciones

## Alcance implementado

- Laravel utiliza MongoDB para usuarios y los datos del módulo 6. Las consultas que contenían joins SQL se adaptaron a lecturas por referencias con Eloquent.
- Asociaciones y Consejo comparten organizaciones, integrantes y cargos con fechas de vigencia. La presidencia vigente administra únicamente su propia organización; los integrantes consultan. Esta política provisional debe alinearse con el contrato de identidad del equipo 1.
- La vista permite seleccionar entre organizaciones propias, editar el perfil, buscar integrantes, incorporar usuarios por matrícula, dar bajas, asignar/editar/retirar cargos y transferir la presidencia.
- Ser integrante no otorga permisos administrativos. Un cargo futuro, vencido o retirado no concede permisos. Una baja revoca los cargos; una reincorporación no los restablece.
- Los cambios de organizaciones, membresías y cargos generan registros en `auditoria_comunidad` con actor, antes y después.
- Las rutas JSON de la interfaz usan sesión web y CSRF. Las escrituras de eventos existentes también validan permisos y entradas. Notificaciones se filtran por destinatario.

## Entorno

La carpeta principal es `/home/daniel/campus_digital`. Ejecutar Docker Compose desde ella. MongoDB está fijado a la imagen verificada 8.3.11 por digest y Laravel espera a que su healthcheck responda. El contenedor debe montar esa carpeta en `/var/www/html`, no la antigua carpeta del Escritorio.

```bash
cd /home/daniel/campus_digital
docker compose up -d --build
docker compose exec --user sail laravel.test composer install
docker compose exec --user sail laravel.test php artisan key:generate # solo al preparar un .env nuevo
docker compose exec --user sail laravel.test php artisan migrate
docker compose exec --user sail laravel.test php artisan db:seed
docker compose exec --user sail laravel.test npm ci
docker compose exec --user sail laravel.test npm run build
```

Para una copia nueva, copiar `.env.example` a `.env` antes de iniciar. `WWWUSER` y `WWWGROUP` deben coincidir con `id -u` e `id -g` de WSL. La conexión dentro de Docker usa `mongodb://mongodb:27017`, base `campus_digital`.

Abrir http://localhost/login. Después de iniciar sesión se abre Organizaciones. Para desarrollar Vue con recarga automática usar `docker compose exec --user sail laravel.test npm run dev -- --host 0.0.0.0` en otra terminal.

En esta entrega las sesiones y la caché se guardan en archivos, y las tareas se ejecutan de forma síncrona. No necesitan SQL Server. La [Entrega 4](Entrega_4_Comunicacion.md) agrega una conexión MongoDB y worker específicos para campañas; no debe enviarse una campaña grande dentro de la petición web.

## Cuentas de demostración

Contraseña local para las cuatro cuentas: `CampusDemo2026!`.

| Correo | Matrícula | Uso |
| --- | --- | --- |
| presidencia@campus.test | 20260001 | Administración de Asociación de Sistemas |
| estudiante@campus.test | 20260002 | Consulta de Sistemas y Consejo; probar selector |
| consejo@campus.test | 20260003 | Administración de Consejo Estudiantil |
| andrea@campus.test | 20260004 | Sin organización inicial; probar alta por matrícula |

El seeder solo permite ambientes local/testing. Repetir `db:seed` no duplica cuentas ni organizaciones y no restablece perfiles, contraseñas, bajas o transferencias ya realizadas. No requiere borrar una base. Los documentos de eventos que existían antes se conservan; sus referencias antiguas no se reasignan automáticamente a las organizaciones nuevas.

## Modelo y contratos

- Identificadores `_id` de MongoDB expuestos como `id` de texto en JSON. Las referencias `usuario_id`, `organizacion_id` y `evento_id` se guardan como strings consistentes.
- Fechas guardadas como BSON Date; timestamps del dominio `creado_en` y `actualizado_en`. Usuarios mantienen los nombres estándar de Laravel.
- Índices únicos: correo, matrícula cuando exista, organización por slug, membresía por organización/usuario, cargo no retirado por organización/tipo, inscripción por evento/usuario y token de boleto.
- Solo se permite una asignación no retirada por tipo de cargo en una organización, incluso si está programada o vencida. Editar ese cargo cambia su titular/vigencia y conserva el cambio en auditoría. La planificación de múltiples periodos futuros queda pendiente.
- La presidencia vigente se transfiere editando el cargo; no se permite borrarla ni dar de baja a su titular sin transferirla antes.
- Crear organizaciones y asignar matrícula a usuarios reales requiere definir el proceso con el equipo 1. Esta entrega arranca con dos organizaciones de demostración y usuarios conocidos.
- `DocumentoConsulta` normaliza identificadores/fechas al leer colecciones de las pantallas que aún no tienen un flujo completo de escritura. En sus siguientes entregas se añadirán modelos y reglas específicos.
- Los bloqueos de administración son locales al despliegue mediante caché de archivos. Los registros de auditoría y cambios de varias colecciones aún no forman una transacción distribuida; antes de un despliegue con varias instancias debe configurarse bloqueo compartido y, si se requieren transacciones, MongoDB replica set.

## Comprobaciones

```bash
docker compose exec --user sail laravel.test php artisan test --compact
docker compose exec --user sail laravel.test npm run build
```

Las pruebas usan exclusivamente `campus_digital_testing`. El trait `RefreshMongoDatabase` comprueba el ambiente y el nombre exacto antes de limpiar esa base. No emplea transacciones SQL ni `RefreshDatabase`, y no elimina `campus_digital`. No ejecutar estas pruebas en paralelo contra la misma base de pruebas.

Recorrido manual: iniciar como presidencia, editar descripción, añadir matrícula 20260004, asignarle un cargo, cambiar su vigencia, darla de baja y comprobar que desaparece de integrantes y cargos. Iniciar como estudiante para comprobar que consulta ambas organizaciones y no puede administrar. Transferir la presidencia desde Editar cargo y verificar que el anterior titular pierde la administración.

## Próximas entregas

1. Eventos: implementado en [Entrega 2](Entrega_2_Eventos.md), con reservas de pago pendiente hasta integrar la wallet.
2. Becas: flujo local implementado en [Entrega 3](Entrega_3_Becas.md); entrega externa pendiente con equipos 2 y 5.
3. Campañas y bandeja: [Entrega 4](Entrega_4_Comunicacion.md). Encuestas/votaciones: [Entrega 5](Entrega_5_Encuestas_Votaciones.md). Reportes/dashboard: [Entrega 6](Entrega_6_Transparencia_Dashboard.md).
4. Integración con identidad y auditoría global, y revisión visual/responsive de todas las pantallas.

El saldo de caja figura como «Por integrar»: no se inventa un saldo ni se implementa la wallet del equipo 2. Campañas y bandeja están implementadas en [Entrega 4](Entrega_4_Comunicacion.md). Los reportes ya permiten generar borradores, publicar, retirar y exportar; ver [Entrega 6](Entrega_6_Transparencia_Dashboard.md). El alcance pendiente y los hallazgos de revisión están en [Revisión del módulo 6](Revision_Modulo_6.md).

## Validación realizada

- Pruebas de autenticación/perfil y del módulo en MongoDB aislado.
- Compilación de producción de Vue con Vite.
- Comprobación en Edge: inicio de sesión, guardado de perfil, rechazo de matrícula duplicada, rechazo de petición sin CSRF y cambio de organización con cuenta de consulta; sin errores JavaScript no controlados.


Continuidad de presidencia y baja de cuentas: ver [Entrega 7](Entrega_7_Staff_Continuidad.md), incluida recuperación auditada por consola.
