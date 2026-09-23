# Entrega 7: Staff, continuidad de administración y MongoDB nativo

Fecha: 22 de septiembre de 2026.

## Personal de acceso

La nueva vista está en `/modulo6/staff`, disponible en el menú **Staff · Acceso** de una organización seleccionada. Muestra los eventos publicados y no terminados asignados al usuario. Presidencia puede acceder a los de su organización. Un integrante sin asignaciones ve un estado vacío y no puede validar boletos.

En **Eventos → Gestión → Personal de acceso**, presidencia asigna o retira integrantes activos por evento. Puede hacerlo antes o durante el evento; no se asigna personal a eventos cancelados o terminados. La asignación no concede permisos de edición de eventos, administración de becas, consulta de todos los inscritos ni gestión de otros usuarios.

El staff elige el evento y usa cámara, imagen QR o código escrito/lector. La pantalla muestra entradas registradas, boletos habilitados por ingresar y el resultado de la última validación con nombre y matrícula del asistente. No conserva un historial de personas en el navegador ni expone el padrón de inscritos o sus códigos QR. Los resultados privados usan `Cache-Control: private, no-store`.

La validación reutiliza `/api/eventos/checkin`. El servidor revalida organización activa, membresía y asignación/presidencia en cada operación; el permiso del menú no sustituye esa comprobación. Se acepta solo una inscripción confirmada, pagada o exenta, del evento seleccionado y cuyo usuario exista. El acceso abre 30 minutos antes y termina al finalizar el evento. Un boleto usado, cancelado, en espera, inválido o con pago pendiente no registra entrada.

El registro de asistencia se actualiza condicionalmente y conserva quién lo validó y cuándo. La bitácora identifica al operador real. Asignar o retirar de nuevo no duplica el cambio ni la auditoría. El permiso retirado deja de servir en una pantalla que seguía abierta; la interfaz refresca disponibilidad cada 15 segundos y el servidor comprueba de nuevo al escanear.

Dar de baja a un integrante retira también su staff. Volver a agregarlo no reactiva el permiso: presidencia debe asignarlo de nuevo. El permiso de staff está vinculado al evento, por lo que no habilita entradas fuera de su ventana ni a otro evento. NFC continúa pendiente de identidad del equipo 1.

## Continuidad de administración

**R1 corregido:** la eliminación de cuenta comprueba responsabilidades antes de cerrar la sesión. Si hay una presidencia no retirada, vigente, futura o vencida, bloquea la eliminación y solicita transferencia/regularización. Al eliminar una cuenta sin presidencia se retiran lógicamente sus membresías, otros cargos y staff, conservando auditoría. No se borran expedientes ni asistencias históricas.

La sesión se cierra antes de eliminar físicamente al usuario, dentro de la operación protegida. Esto evita que la renovación de `remember_token` durante el logout vuelva a escribir una cuenta ya eliminada en MongoDB. La asignación de cargos también exige que el usuario exista, además de tener membresía activa.

**R2 resuelto mediante mantenimiento autorizado:** una organización cuya presidencia venció o cuyo titular ya no existe puede recuperarse desde la consola del proyecto. No se extienden automáticamente cargos ni se abre una ruta web para otorgar privilegios. La persona que tiene acceso autorizado al servidor ejecuta:

```bash
docker compose exec --user sail laravel.test php artisan comunidad:recuperar-presidencia sistemas 20260002 --hasta=2027-06-30 --operador="Responsable del mantenimiento" --motivo="Recuperación autorizada por término del periodo anterior"
```

Es un ejemplo: seleccionar la organización, matrícula, vigencia y motivo reales antes de ejecutar. El destinatario debe ser usuario existente e integrante activo. Se rechaza sustituir a una presidencia efectiva o una futura programada; para una presidencia vigente se usa la transferencia normal desde Organizaciones. Se requiere organización activa y fecha final no vencida. La auditoría guarda estado anterior, nuevo titular, motivo y operador declarado, con canal `consola`; ese nombre es una declaración de mantenimiento, no una identidad autenticada del equipo 1.

Bajas, asignaciones y recuperación comparten un bloqueo local para impedir que se asigne una responsabilidad mientras se elimina la cuenta. Las asignaciones/revocaciones de staff comparten además el bloqueo del evento con la validación de entradas.

Quedan pendientes los periodos sucesivos programados y el proceso institucional de recuperación con equipo 1. La consola resuelve el bloqueo local sin inventar una autoridad institucional en la aplicación.

## Normalización de MongoDB

**R3 corregido:** `beneficio`, `requisitos_documentos`, `documentos`, `dictamen`, `contrato` y `destinatarios` dejan de serializarse mediante casts `array`. Se almacenan como estructuras BSON nativas y admiten consultas por campos anidados.

La migración `2026_09_22_000000_normalize_becas_campanas_arrays` recorre solo los seis campos en sus cuatro colecciones. Primero valida todas las cadenas JSON; si encuentra un valor inválido, informa colección/campo/ID sin descartar contenido. Después transforma mediante actualización condicional sobre el valor original. Es repetible, preserva valores nulos y estructuras ya nativas y no elimina registros. El filtro distingue el tipo del campo completo de los tipos de los elementos de un arreglo.

La migración siguiente crea `staff_eventos`, con unicidad por evento/usuario e índice para consultar asignaciones. No se requiere reiniciar ni sustituir la base de demostración.

Para aplicar en otra copia, detener escrituras y el worker durante la normalización, ejecutar las migraciones, compilar frontend y reiniciar el worker con el código nuevo. Si la migración detecta JSON inválido, revisar ese registro antes de abrir la aplicación; no eliminarlo para forzar el paso.

## Demostración local

El seeder adicional se ejecuta después de `DatabaseSeeder`:

```bash
docker compose exec --user sail laravel.test php artisan db:seed --class=StaffEventoSeeder
```

Crea `staff@campus.test`, matrícula `20260005`, contraseña local **CampusDemo2026!**, integrante de Sistemas; una conferencia gratuita de demostración; una asignación de staff; y boletos para `estudiante@campus.test` y `andrea@campus.test` mediante las reglas de inscripción. Solo admite entornos local/testing.

No restablece contraseñas existentes, fechas, permisos retirados ni boletos usados al repetirse. La conferencia se crea con inicio 25 minutos después y fin cuatro horas después; al terminar, crear otro evento desde la interfaz y asignar personal. No se hace pasar un evento terminado por uno nuevo modificando sus fechas.

Recorrido:

1. Presidencia entra en Eventos y revisa **Personal de acceso** de la conferencia.
2. Estudiante abre **Mis boletos** y muestra su QR.
3. Staff abre **Staff · Acceso**, selecciona la conferencia y valida el boleto.
4. La pantalla muestra acceso autorizado; un segundo intento con el mismo boleto se rechaza.
5. Retirar el staff desde presidencia y repetir el intento verifica la revocación, incluso con la pantalla abierta.

Si una cuenta pertenece a varias organizaciones, elegir la correcta desde Organizaciones antes de entrar en Staff. Las reservas de eventos con costo siguen con pago pendiente, sin cobros simulados.

## Validación

Las nuevas pruebas cubren bloqueo de baja de presidencia vigente/futura/vencida, limpieza lógica de responsabilidades, rechazo de usuarios inexistentes, recuperación y auditoría, BSON nativo, migración repetible e inválidos, permisos de staff, separación de organizaciones, ventana de acceso, duplicados, pagos pendientes, revocación y repetición del seeder.

La suite solo limpia `campus_digital_testing`; no ejecutar dos procesos de pruebas sobre esa misma base al mismo tiempo. El resultado completo se conserva en `storage/logs/validacion-entrega-7.log`.

### Resultado comprobado en esta entrega

- Suite completa: **134 pruebas aprobadas, 1185 aserciones**; duración 233.08 s. Log: `storage/logs/validacion-entrega-7.log`.
- Compilación Vue/Vite correcta y `git diff --check` sin errores.
- Migraciones aplicadas en la base local. Lectura nativa posterior confirmó cero cadenas JSON pendientes en los seis campos.
- Edge: inicio de sesión, lectura de una imagen QR real del boleto de Andrea, confirmación de identidad/asistencia y rechazo del mismo código al repetirlo.
- Panel de presidencia: retirar y volver a asignar Staff; la pantalla que permanecía abierta rechazó el acceso tras la revocación.
- Staff sin acceso a lista privada de inscritos ni cancelación de eventos. Pantalla revisada a 390 px sin desbordamiento horizontal ni errores JavaScript no controlados.
- La demostración conserva una entrada de Andrea registrada y el boleto de Estudiante Demo sin usar. Staff quedó reasignado. Aplicación y worker activos.

La lectura probada en esta revisión usó una imagen QR; la cámara física del dispositivo del usuario queda por comprobar. La cámara requiere HTTPS o localhost, como indica el componente cuando no está disponible.

## Límites

No hay nueva integración con wallet, NFC ni identidad institucional. Las asignaciones y recuperación son locales. Se mantiene MongoDB standalone y bloqueos de un solo host; escritura de varias colecciones y auditoría no forman una transacción única. Antes de un despliegue distribuido siguen pendientes bloqueos compartidos y reconciliación/atomicidad, como se describe en la revisión y los contratos.


**Actualización de Entrega 8:** la recuperación por consola también admite organizaciones suspendidas, sin reactivarlas ni reemplazar una presidencia que siga siendo efectiva. Ver [Gestión de organizaciones](Entrega_8_Gestion_Organizaciones.md).
