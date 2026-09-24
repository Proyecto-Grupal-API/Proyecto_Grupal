# Integración local de Identidad y Comunidad

Base Comunidad: `055af14005837f73327691df4f13ca0a592fabda`.
Equipo 1: `7a30c3f`, rama `feat/modulo-1-equipo-1`.
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

No se publicó esta integración a GitHub ni se modificaron `Modulo6_Comunidad`, `Testings` o `main`.

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

Las pruebas usan únicamente `campus_digital_testing` en el MongoDB aislado; el fixture rechaza otro nombre antes de borrar. No ejecutar varias suites simultáneamente contra esa misma base.

## Validación realizada

- Suite completa: **358 pruebas aprobadas, 2688 aserciones**.
- Después de conservar el índice único parcial de matrículas: **4 pruebas de integración aprobadas, 12 aserciones**, incluida la nueva comprobación de duplicados y cuentas sin matrícula. Tres de estas pruebas también estaban en la suite completa.
- Migraciones ejecutadas correctamente en la base aislada. La migración adicional repara copias inicializadas sin el índice provisional y conserva la restricción de Comunidad.
- Compilación de producción con Vite completada y `composer validate` correcto.
- Navegador: acceso con las cuentas de Comunidad e Identidad; perfil/2FA, condición académica, generación real de QR dinámico, dispositivos, NFC, estudiantes y roles. Vistas revisadas en escritorio y móvil; las páginas administrativas comprobadas no produjeron errores de JavaScript.
- La consulta real de Comunidad a Identidad respondió HTTP 200 con OAuth. El QR dinámico se generó con vigencia de 30 segundos.

Registros locales (ignorados por Git): `storage/logs/integracion-tests.log` y `storage/logs/integracion-indice-tests.log`.
