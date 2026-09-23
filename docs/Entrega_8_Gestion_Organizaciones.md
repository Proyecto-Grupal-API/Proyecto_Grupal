# Entrega 8: Alta y estado de asociaciones y Consejo

Fecha: 22 de septiembre de 2026.

## Qué permite

La vista `/modulo6/gestion-organizaciones` permite registrar asociaciones y consejos, consultar su ficha e historial y suspenderlos/reactivarlos conservando los datos. Aparece en el menú para cuentas con permiso específico de **Gestión de organizaciones**. También hay un acceso desde la pantalla de Organizaciones para esas cuentas.

El gestor captura nombre, tipo, propósito, contacto, matrícula de presidencia y último día del cargo. La búsqueda exacta de matrícula muestra al titular para revisar la asignación; no enumera usuarios ni devuelve su correo. El alta exige una cuenta ya registrada. Crea su membresía y cargo de presidencia con vigencia inicial y solo después activa la organización.

El nuevo titular puede seleccionar su organización y usar los flujos existentes de perfil, integrantes, cargos, eventos, becas y comunicación. El gestor no se convierte en integrante ni en presidente automáticamente. No hay eliminación física de organizaciones en este flujo.

## Autoridad y límites de permisos

| Cuenta | Facultades |
| --- | --- |
| Gestor de organizaciones | Listar fichas de organizaciones, consultar la matrícula del titular, registrar organizaciones, suspender/reactivar y consultar historial de estado. |
| Presidencia vigente | Administrar su propia organización activa según las funciones existentes. No crea otras organizaciones ni cambia su estado general por ser presidente. |
| Staff | Validar acceso únicamente en sus eventos asignados y dentro de las reglas del evento. |
| Integrante | Consulta y participación autorizadas, sin gestión general. |

El permiso de gestión vive en una colección propia y se comprueba en el servidor en cada petición. No forma parte de los campos editables del usuario, registro o perfil; ocultar el menú no es la barrera de autorización. Revocarlo también bloquea una pantalla abierta. La baja de una cuenta retira el permiso si lo tenía.

`AutoridadOrganizaciones` concentra la comprobación local. **No es una integración ya acordada con el equipo 1.** Se sustituirá por su contrato institucional cuando esté definido. No se otorga automáticamente el permiso a presidencias, Staff ni estudiantes.

Desde una consola autorizada del proyecto:

```bash
docker compose exec --user sail laravel.test php artisan comunidad:gestor-organizaciones 20260006 --operador="Responsable del mantenimiento" --motivo="Designación autorizada para gestionar el registro de organizaciones"
```

Agregar `--revocar` retira el permiso. Requiere matrícula existente, operador y motivo. La bitácora identifica el canal como consola y guarda el operador declarado; no lo presenta como identidad autenticada del equipo 1. Repetir el mismo estado no duplica la operación. El comando solo concede gestión del registro, nunca permisos sobre expedientes privados de otras organizaciones.

## Alta consistente y reintentos

La aplicación genera una clave UUID por formulario de alta. Repetir la misma solicitud devuelve la organización existente; reutilizar la clave con datos diferentes o desde otro usuario devuelve conflicto. El nombre se normaliza para detectar duplicados, incluyendo organizaciones antiguas cuyo slug no se derivaba del nombre. También se aplica la comprobación al editar el perfil, bajo el bloqueo común de responsables.

El alta usa temporalmente `configurando`: la organización no se puede seleccionar ni administrar como activa hasta tener membresía y presidencia válidas. Cada paso es repetible. Si una operación se interrumpe antes de activar, el gestor ve **Alta pendiente → Completar alta** y puede terminarla sin crear otra organización o duplicar cargos. El sistema no reinicia manualmente membresías ya retiradas. Si el titular desapareció o venció el periodo inicial antes de completar, se requiere revisión de mantenimiento; no se activa sin responsable.

Este procedimiento evita publicar un alta incompleta en MongoDB standalone; no se declara como transacción entre varias colecciones. El alta deja auditoría y el historial inicial antes de quedar disponible. No hay creación automática de tiendas, wallet, comisiones ni documentos en esta entrega.

## Suspensión y reactivación

Cambiar de estado requiere motivo y versión del estado que el gestor revisó. Una pestaña con una versión antigua recibe conflicto en vez de sobrescribir otro cambio. Las solicitudes tienen clave propia para reintentos: no duplican historial ni auditoría. Estado, versión e historial se guardan juntos en el documento de organización. La ficha muestra fecha, motivo y nombre actual del usuario que registró cada cambio; si esa cuenta ya no existe, aparece como no disponible.

| Efecto de suspender | Comportamiento |
| --- | --- |
| Integrantes/cargos | Permanecen guardados. La organización deja de estar disponible como activa y sus integrantes ven un aviso de suspensión. |
| Administración | Se deniega aunque la página estuviera abierta. La política comprueba el estado actual almacenado. |
| Eventos/Staff | No se admiten nuevas inscripciones ni validaciones. Los boletos propios se conservan, pero no muestran QR habilitado mientras la organización esté suspendida. |
| Cancelaciones propias | Se mantienen las reglas existentes de cancelación. No se promueve la lista de espera durante la suspensión. |
| Becas | Se retiran del catálogo activo y se bloquean nuevas solicitudes/envíos por la regla de recepción. Los expedientes y documentos propios no se eliminan. |
| Campañas | El trabajador revisa permisos al iniciar cada lote y pausa el envío si la organización está suspendida. Lo entregado permanece en la bandeja. |
| Encuestas/reportes | La organización no permite su operación mediante las rutas internas mientras esté suspendida. Se conservan resultados e historial. |

Una operación que ya había sido autorizada e iniciada puede terminar antes de que la suspensión sea observada; no hay una transacción global con todos los dominios. En particular, el worker controla el estado al comenzar cada lote. Para uso distribuido hace falta acordar bloqueo/consistencia compartidos.

Reactivar requiere usuario titular existente, membresía activa y presidencia vigente. Si venció el cargo, el comando de recuperación de la entrega 7 ahora permite regularizar una organización suspendida, sin reactivarla ni reemplazar una presidencia efectiva que todavía esté vigente. Después el gestor puede reactivar.

No se reinician fechas, cargos, reservas, asistencias o convocatorias al reactivar. Los permisos de Staff que no se retiraron vuelven a funcionar solo si siguen cumpliendo membresía y ventana del evento. Las campañas pausadas requieren revisión y reintento desde Comunicación. La espera vuelve a evaluarse con las operaciones de inscripción/cancelación existentes; reactivar no ejecuta promociones masivas.

## Instalación y demostración

```bash
docker compose exec --user sail laravel.test php artisan migrate
docker compose exec --user sail laravel.test php artisan db:seed --class=GestionOrganizacionesSeeder
docker compose exec --user sail laravel.test npm run build
```

El seeder adicional solo admite local/testing. Crea `gestion@campus.test`, matrícula `20260006`, contraseña inicial **CampusDemo2026!**, y su permiso de gestión. No añade organizaciones ni restablece contraseñas existentes. Si el permiso ya existía y fue revocado, no lo reactiva al repetir el seeder.

Recorrido sugerido:

1. Entrar como Gestión y abrir **Nueva organización**.
2. Capturar datos, consultar la matrícula del titular y revisar su nombre antes de confirmar.
3. Entrar como ese titular: la nueva organización aparece en su selector y puede administrarla.
4. Desde Gestión, suspender con un motivo. Comprobar el aviso del integrante y que no puede administrar ni validar entradas.
5. Reactivar con un motivo y revisar la ficha: el historial conserva ambas operaciones.

Si el permiso se entrega a una cuenta que tiene membresías, la gestión general sigue siendo independiente de su organización seleccionada. Las rutas del registro usan un ID explícito y autorización específica; el encabezado de contexto de otra pestaña sigue protegiendo las operaciones internas de cada organización.

## Pruebas y pendientes

Pruebas nuevas: permisos denegados a invitados/presidencia/integrantes; alta y primera presidencia; idempotencia; validación y nombres duplicados; recuperación de alta interrumpida; historial y versiones; suspensión de catálogo, boletos y permisos; pausa de campañas; reactivación con vigencia; comandos de permiso; perfil sin autoasignación; baja de gestor y seeder repetible.

La suite usa exclusivamente `campus_digital_testing`. Su resultado completo se guarda en `storage/logs/validacion-entrega-8.log`.

### Resultado comprobado en el entorno local

- Suite completa: **146 pruebas aprobadas, 1308 aserciones**; registro en `storage/logs/validacion-entrega-8.log`.
- Compilación de producción con Vite completada; `git diff --check` sin errores.
- Migración de índices aplicada y cuenta de Gestión creada con el seeder adicional.
- Recorrido en navegador Edge: alta desde el formulario con matrícula verificada, acceso de la presidencia inicial, separación de permisos, suspensión, rechazo de edición desde una pestaña abierta, reactivación e historial con operador y motivo. Sin errores JavaScript no controlados.
- Vista de 390 px: búsqueda funcional, campo legible de 324 px y sin desbordamiento horizontal, después del ajuste de distribución móvil.

La demostración deja activa **Asociación de Robótica · Demo de gestión**, con Andrea Demo (`andrea@campus.test`, matrícula `20260004`) como presidencia inicial hasta el 30 de junio de 2027. Su historial conserva la suspensión y reactivación de prueba. Las organizaciones anteriores se mantienen.

Siguen pendientes el acuerdo de autoridad con equipo 1, delegaciones y permisos por cargo, periodos sucesivos programados, documentos y comisiones. Tampoco se implementan aquí las integraciones de tiendas, pagos y servicios. La recuperación por consola y la gestión local no sustituyen esos acuerdos del grupo.
