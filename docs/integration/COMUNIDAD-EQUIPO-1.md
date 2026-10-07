# Integración local de Identidad y Comunidad

Base Comunidad: `055af14005837f73327691df4f13ca0a592fabda`.
Equipo 1: `f41c78066cb8e6dde2a1605233e832395cb20841` (entrega descargada el 7 de octubre de 2026), rama `feat/modulo-1-equipo-1`.
Rama de trabajo: `integracion/equipo1-comunidad`.

## Entorno independiente

Carpeta: `/home/daniel/campus_digital_integracion_e1`.
URL: http://127.0.0.1:8086/login (usar 127.0.0.1 para separar las cookies del original en localhost).
Docker Compose: `compose.integracion.yaml`, proyecto `campus_integracion_e1`.
MongoDB usa un volumen independiente y un replica set `rs0` para las transacciones de Identidad. No publica el puerto de base de datos.
El original en `/home/daniel/campus_digital`, su rama y su base permanecen separados.

Desde esta carpeta, para reanudar después de reiniciar Docker:

```bash
docker compose -f compose.integracion.yaml up -d
```

Para detener solamente esta copia:

```bash
docker compose -f compose.integracion.yaml stop
```

No usar `down -v` salvo que se pretenda borrar los datos de esta copia. No ejecutar el compose predeterminado aquí: corresponde al entorno original y usa otros puertos.

## Qué se integró

- Laravel 13 y dependencias del equipo 1. Imagen PHP derivada independiente con extensión MongoDB actualizada.
- Autenticación y activación de cuentas, 2FA, perfiles, gestión de estudiantes, QR de identidad, NFC, dispositivos, sesiones y roles del equipo 1.
- Navegación compartida con el diseño de Comunidad; acceso a Identidad desde Mi Perfil.
- Revocación de sesiones aplicada también a vistas y endpoints de Comunidad.
- Eliminación de cuenta mantiene las restricciones y la limpieza de responsabilidades del módulo 6.
- Eventos, Staff, becas, comunicación, participación, transparencia y registro de organizaciones conservan las reglas del módulo 6.
- El resumen de Comunidad consulta la condición académica mediante OAuth y HTTP contra la API del equipo 1. Solo consulta el usuario autenticado. Ante indisponibilidad muestra un error; no inventa condición ni elegibilidad.

## Cuentas locales

Contraseña de las cuentas siguientes: `CampusDemo2026!`. Son exclusivamente demostración local.

| Cuenta | Función |
| --- | --- |
| presidencia@campus.test | Presidencia de Sistemas; también perfil académico de Identidad |
| estudiante@campus.test | Integrante de Sistemas y Consejo |
| consejo@campus.test | Presidencia del Consejo |
| andrea@campus.test | Estudiante de demostración |
| staff@campus.test | Validación de acceso al evento de demostración |
| gestion@campus.test | Registro institucional de organizaciones |
| identidad@campus.test | Administración del módulo 1: estudiantes, roles y NFC |

Los permisos de presidencia, gestión y staff siguen perteneciendo a Comunidad; un administrador de Identidad no obtiene esos cargos automáticamente. Los datos son nuevos y no incluyen las modificaciones manuales hechas en la demo original.

## Pendientes para la unificación definitiva

Esta es una integración funcional inicial; no convierte todavía todo acceso a identidad en llamadas API. El código heredado de Comunidad sigue usando `User` para búsquedas por matrícula y nombres. Se conserva temporalmente `users.matricula` para los flujos existentes; el registro académico canónico del equipo 1 es `student_profiles.enrollment_number`. Las altas nuevas de Identidad no se sincronizan automáticamente con el directorio provisional de Comunidad.

El equipo 1 todavía debe acordar/publicar el contrato de directorio por matrícula y consulta de permisos contextualizados. Después se sustituirán esas consultas y el permiso institucional provisional. No se añadieron roles institucionales inexistentes a su catálogo. La condición académica no equivale a elegibilidad de beca: `benefits_eligible` permanece nulo según el contrato.

El QR de identidad no reemplaza el boleto de un evento: el boleto y su control de acceso siguen bajo las reglas de Comunidad. No hay cobros simulados: las reservas con costo siguen pendientes de Wallet.

El avance previo se publicó en `integracion/equipo1-comunidad` mediante la [solicitud #2](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/pull/2) dirigida a `Testings`. Los nuevos cambios locales no se publican automáticamente.

## Configuración de API

Variables privadas: `IDENTIDAD_API_URL`, `IDENTIDAD_CLIENT_ID`, `IDENTIDAD_CLIENT_SECRET`. La copia local tiene un cliente OAuth con alcance `students:read`. Su secreto está solo en `.env`; nunca en código ni en el frontend.

En esta copia `IDENTIDAD_API_URL=http://app`, dentro de la red Docker. PHP sirve con varios workers para atender la llamada HTTP interna.

## Reconstruir una copia nueva

1. Copiar `.env.example` a `.env`, generar APP_KEY y usar MongoDB `mongodb://mongodb:27017/?replicaSet=rs0` con `MONGODB_DATABASE=campus_integracion`, `SESSION_DRIVER=file`, `CACHE_STORE=file`, `APP_URL=http://127.0.0.1:8086` y cookie de sesión propia.
2. Construir con `docker compose -f compose.integracion.yaml build app` (requiere imagen base `sail-8.5/app`).
3. Arrancar MongoDB y, solo si el volumen es nuevo, inicializar: `docker compose -f compose.integracion.yaml exec mongodb mongosh --eval 'rs.initiate({_id:"rs0",members:[{_id:0,host:"mongodb:27017"}]})'`.
4. Instalar dependencias, ejecutar migraciones y `php artisan db:seed --class=IntegracionEquipo1Seeder` dentro del contenedor app.
5. Crear cliente con `php artisan oauth:client comunidad --scope=students:read` y guardar las credenciales únicamente en `.env`.
6. Ejecutar `npm ci --ignore-scripts` y `npm run build`; arrancar app y worker con el compose de integración.

Las pruebas usan únicamente `campus_virtual_testing` en el MongoDB aislado; el fixture rechaza otro nombre antes de borrar. No ejecutar varias suites simultáneamente contra esa misma base.

## Validación realizada

- Suite completa: **358 pruebas aprobadas, 2688 aserciones**.
- Después de conservar el índice único parcial de matrículas: **4 pruebas de integración aprobadas, 12 aserciones**, incluida la nueva comprobación de duplicados y cuentas sin matrícula. Tres de estas pruebas también estaban en la suite completa.
- Migraciones ejecutadas correctamente en la base aislada. La migración adicional repara copias inicializadas sin el índice provisional y conserva la restricción de Comunidad.
- Compilación de producción con Vite completada y `composer validate` correcto.
- Navegador: acceso con las cuentas de Comunidad e Identidad; perfil/2FA, condición académica, generación real de QR dinámico, dispositivos, NFC, estudiantes y roles. Vistas revisadas en escritorio y móvil; las páginas administrativas comprobadas no produjeron errores de JavaScript.
- La consulta real de Comunidad a Identidad respondió HTTP 200 con OAuth. El QR dinámico se generó con vigencia de 30 segundos.

Registros locales (ignorados por Git): `storage/logs/integracion-tests.log` y `storage/logs/integracion-indice-tests.log`.


## Apariencia del login

Solo el login usa la vista, el logotipo y la tipografía del equipo 1. Su presentación está aislada en `LoginLayout.vue`; las demás pantallas mantienen el diseño integrado anterior. El inicio final es `/dashboard` del Equipo 1, con acceso a Comunidad. El backend de autenticación del equipo 1 se conserva.

Validado con compilación Vite y navegación en navegador: login del equipo 1 en escritorio/móvil, recuperación con el diseño previo, redirección a Comunidad y perfil, condición académica, QR, dispositivos y NFC con el diseño integrado anterior. Sin errores JavaScript.

## Entrada y cierre de sesión

La página principal `/` redirige al login cuando no hay sesión y a Comunidad cuando hay sesión. Cerrar sesión conserva la respuesta a `/` del Equipo 1; la entrada redirige al login y se retiró la página de bienvenida de Laravel. Comprobado en navegador con el botón de cierre de sesión y el bloqueo posterior de vistas privadas; 9 pruebas de autenticación/entrada aprobadas y compilación Vite correcta.

## Autenticación y administración de sesiones

El enlace **Autenticación** abre `/profile/authentication`, con los controles de contraseña y verificación en dos pasos dentro del diseño integrado. Las acciones de dispositivos y sesiones devuelven JSON para las peticiones de la pantalla, muestran sus resultados y cierran inmediatamente la sesión local si se revoca o se elimina su dispositivo. La confirmación de contraseña vence tras el intervalo configurado y debe repetirse para continuar.

Se reparó el propietario de los archivos generados en esta integración. Ejecutar pruebas, Artisan y compilaciones con `docker compose -f compose.integracion.yaml exec -T --user sail app ...` para evitar archivos de caché creados como root. La caché de archivos de pruebas usa `storage/framework/cache/testing`; la aplicación usa `storage/framework/cache/data`, de modo que las pruebas no interfieran con los límites de intentos de la aplicación.

Validación de esta corrección: **38 pruebas aprobadas, 289 aserciones**, compilación Vite correcta y verificación en Edge con una cuenta temporal eliminada al terminar. Se comprobó el acceso a Autenticación, el inicio de configuración de 2FA, el aviso de contraseña incorrecta, la revocación de otra sesión, el bloqueo posterior de esa sesión y el regreso al login al eliminar el dispositivo actual. Sin errores JavaScript ni respuestas 5xx. Registros locales: `storage/logs/sesiones-tests.log`, `storage/logs/sesiones-build.log` y `storage/logs/sesiones-browser.log`.

## Primer contrato de Comunidad para Equipo 7

Se implementó `GET /api/v1/organizations/{organizationId}/summary`, protegido por OAuth y `community:organizations:read`. Devuelve el estado real de asociaciones y Consejo sin miembros ni datos privados. Contrato, configuración y pendientes de notificaciones/auditoría en [API_Integracion_Equipo_7.md](../API_Integracion_Equipo_7.md). La validación exclusiva del resumen de Comunidad verifica también que el cliente siga activo y conserve el permiso solicitado.


## Adaptación a la entrega final del Equipo 1 — 7 octubre 2026

Se incorporó la rama `feat/modulo-1-equipo-1` en `f41c78066cb8e6dde2a1605233e832395cb20841`, partiendo del avance local de Comunidad `9cff2dd`. El Equipo 1 es la referencia para identidad/autorización; los contratos y las reglas de su entrega prevalecen. Se conservaron los flujos propios de Comunidad y la apariencia integrada acordada, con el login del Equipo 1. El inicio es su `Dashboard`, al que se añadió el acceso a Comunidad.

- Roles: `RoleAssignment` es la autoridad, `User.roles` es histórico y no se usa para el menú. Las vistas consumen `effective_roles`; revocación/vigencia se resuelven en Identidad. El seeder integrado usa su escritor oficial de asignaciones.
- Sesiones revocadas: se conserva la redirección a login del middleware final del Equipo 1, también en solicitudes AJAX. El heartbeat de la interfaz detecta la respuesta de login y vuelve a la pantalla de acceso.
- La contraseña inicial obligatoria y el 2FA requerido por la política de Identidad se aplican también a rutas y APIs de sesión de Comunidad. No se excluyen cuentas administrativas para facilitar la demo: `identidad@campus.test` debe configurar 2FA al entrar si no lo tiene confirmado.
- Cierre de cuenta: Identidad ejecuta su baja lógica, invalida credenciales y sesiones y conserva auditoría. Comunidad retira sus memberships/cargos/Staff dentro de la misma transacción. Una presidencia sin transferir sigue bloqueando el cierre; un fallo revierte ambas partes.
- NFC: se incorporó el reemplazo enlazado y su validación OAuth según el contrato del Equipo 1. El boleto de evento sigue siendo un recurso propio de Comunidad.
- OAuth: no se cambió la semántica del middleware del Equipo 1. El resumen de organizaciones para Equipo 7 valida el cliente y sus grants vigentes mediante un middleware exclusivo de Comunidad.
- La base de pruebas adoptó el nombre `campus_virtual_testing` exigido por los guards del Equipo 1, dentro del MongoDB **del contenedor alterno**. No se toca la base principal ni se reutiliza la base real de integración para pruebas.

### Límites que siguen abiertos

Esta unión no implica que todas las dependencias entre módulos estén finalizadas. El Equipo 1 no publica un directorio por matrícula ni una API de permisos institucionales en este snapshot. Sus contratos business no sirven como sustituto. No se añadieron endpoints en Identidad, catálogos duplicados ni asignaciones institucionales inventadas.

El adaptador `AutoridadOrganizaciones` y `PermisoGestionOrganizaciones` siguen siendo una solución **provisional de la demo heredada**, no una integración con `organization_manager(campus)`. Las búsquedas heredadas de Comunidad por `users.matricula` y las lecturas locales de nombres/existencia de usuarios **siguen pendientes de reemplazo**: no cumplen todavía la regla de consumir identidad exclusivamente por API y no deben presentarse como integración definitiva. La consulta de condición académica sí usa HTTP/OAuth y mantiene `User._id` como identificador externo.

No convertir nombres de campus/programa de la API en IDs de ámbito. El vínculo organización↔campus/programa, los IDs canónicos disponibles por API, la autoridad otorgante y el catálogo de departamento siguen siendo dependencias. El jefe de departamento no debe recibir permisos positivos mientras su fuente canónica esté sin resolver. Los cargos internos de Comunidad no se materializan como roles de Identidad.

Detalle de dependencias: [Dependencias_Equipo_1_Final.md](Dependencias_Equipo_1_Final.md).

Antes de las migraciones se generó un respaldo privado de la base alterna en `storage/backups/antes-equipo1-final-20261007.archive.gz`; esa carpeta está excluida de Git. Referencia del código anterior: `respaldo/integracion-e1-antes-final-20261007`.


### Validación de la adaptación final

- Primera revisión específica: 79 pruebas aprobadas, 637 aserciones (API de organizaciones, OAuth, perfil, baja de cuenta, autorización institucional y consumo académico).
- Suite completa de ambos módulos: 914 casos revisados; 890 aprobaron inicialmente y 24 detectaron diferencias de integración. Se corrigieron la entrada al dashboard del Equipo 1, la respuesta de logout y la expectativa heredada de borrado físico. Los casos de sesión que antes fallaban en la entrada al dashboard también revelaron nuestra respuesta JSON anterior de revocación; se restauró la redirección oficial del Equipo 1.
- Verificación posterior de todas las clases afectadas más las nuevas pruebas de integración: **107 pruebas aprobadas, 764 aserciones, cero fallos**. No es una segunda ejecución de toda la suite: el resto de los casos ya había aprobado. Logs: `storage/logs/e1-final-suite.log` y `storage/logs/e1-final-recheck.log`.
- Las seis pruebas nuevas verifican que 2FA y contraseña inicial bloqueen Comunidad, que el menú use roles efectivos, que un rol de Identidad no cree membership/presidencia, que la vigencia se respete y que un fallo revierta la baja de Identidad y Comunidad en una transacción.
- Compilación Vite de producción correcta; `composer validate` y comprobación de diff correctas.
- Edge: login → dashboard final → Comunidad; consulta académica real HTTP/OAuth 200 con matrícula de StudentProfile y elegibilidad nula; vistas de eventos/becas/comunicación/transparencia, Autenticación, Roles y NFC; una sola navegación, móvil sin desbordamiento, logout → login y administración sin 2FA bloqueada (vista redirigida/API 423). Sin errores JavaScript ni respuestas 5xx. Cuentas temporales eliminadas al terminar. Log: `storage/logs/e1-final-browser.log`.

El avance permanece local en la rama de integración; no se publicó ni se modificaron ramas remotas en este trabajo.
