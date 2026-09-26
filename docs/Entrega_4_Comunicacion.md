# Entrega 4: Campañas, segmentación y bandeja

## Alcance

Flujo local de los apartados 6.7 y 6.8 del documento del proyecto. La presidencia vigente administra campañas de su organización en `/modulo6/comunicacion`. Cada usuario tiene su bandeja privada en `/modulo6/bandeja`, incluso sin pertenecer a una organización.

Los mensajes se entregan exclusivamente dentro de Campus Digital. No se envían correos, SMS ni push. La política de presidencia es provisional hasta integrar permisos de Comunicación con el equipo 1.

## Audiencias disponibles

| Audiencia | Selección |
| --- | --- |
| Integrantes activos | Membresía activa y vigente en la organización |
| Evento: reservas confirmadas | Inscripciones confirmadas del evento propio |
| Evento: lista de espera | Inscripciones en espera del evento propio |
| Beca: solicitudes enviadas | Pendientes, en revisión, aprobadas o rechazadas de convocatoria propia |
| Beca: solicitudes aprobadas | Aprobaciones locales, aunque la entrega externa siga pendiente |

Se excluyen borradores de beca, solicitudes retiradas/canceladas, inscripciones canceladas, cuentas eliminadas y usuarios que silenciaron esa organización. No se ofrecen filtros ficticios de campus, carrera, semestre o grupo: faltan esos datos y su contrato de identidad. El envío global a todos los alumnos requiere permisos y alcance institucional todavía no definidos.

Cada campaña admite hasta 5000 destinatarios. Se deduplican los IDs y no se muestran listas de personas ni datos personales en la vista previa o las métricas.

## Flujo de campañas

1. Guardar borrador con nombre interno, asunto, texto, audiencia y acción opcional.
2. Abrir Vista previa: muestra el contenido y el número de destinatarios.
3. Confirmar envío: la firma de la vista previa vincula campaña, revisión y audiencia. Si cambia algo entre revisar y confirmar, la API rechaza la confirmación y pide otra vista previa.
4. Se congela la audiencia y se coloca el envío en la cola persistente MongoDB.
5. El trabajador procesa lotes de 100. Revisa permisos del emisor y elegibilidad/preferencias antes de cada lote; los nuevos integrantes no se añaden al envío confirmado.

Las acciones se eligen de un catálogo de destinos internos: eventos, becas, boletos, boleto del evento o solicitud personal de la beca. El servidor genera los enlaces; no admite URLs arbitrarias. El texto se muestra sin interpretar HTML.

Después de confirmar no se puede editar. Se puede cancelar el trabajo pendiente, conservando mensajes ya entregados, o reintentar pendientes si hubo interrupción. Los reintentos conservan la audiencia original y no restablecen mensajes leídos, archivados o eliminados. Revocar permisos del emisor pausa el envío; una presidencia vigente puede reanudarlo con su propia autorización.

`enviada` significa que terminó de procesarse la audiencia; los contadores distinguen entregados y omitidos. Las lecturas cuentan personas que marcaron el mensaje leído al menos una vez. Marcarlo después como no leído no reduce esa métrica. El emisor solo consulta cifras agregadas.

## Bandeja y preferencias

- Recibidos, Sin leer, Importantes y Archivados, con búsqueda por asunto y paginación.
- Abrir un mensaje desde la interfaz lo marca leído; un GET de la API por sí solo no altera su estado.
- Se puede marcar leído/no leído, destacar, archivar y restaurar.
- Cada lectura y cambio exige ser destinatario, sin importar los permisos administrativos del usuario.
- Preferencias permite silenciar o reactivar campañas de una organización. No borra mensajes anteriores. La preferencia se vuelve a comprobar antes de cada lote, sin retirar entregas que ya terminaron.
- La campana muestra cinco mensajes recientes y el contador real de no leídos de Recibidos, aunque haya mensajes sin leer fuera de esos cinco. Se actualiza cada 15 segundos con la pestaña visible.
- La ruta anterior para limpiar notificaciones leídas conserva su borrado lógico por compatibilidad; no elimina entregas ni métricas físicamente.

## Cola y operación local

`config/queue.php` agrega la conexión `comunidad` con driver `mongodb`, colección `jobs_comunidad` y cola `comunidad`. Las otras tareas conservan su configuración. Se usa el soporte de colas del paquete MongoDB ya instalado. `EnviarCampana` lleva solo el identificador y la ejecución, no una copia de la audiencia completa.

Docker Compose incorpora `comunidad.worker`, que reutiliza la imagen de Laravel y monta la misma carpeta WSL. Se reinicia al salir y recicla el proceso cada hora. No se hacen envíos masivos dentro de la petición HTTP.

Desde `/home/daniel/campus_digital`:

```bash
docker compose exec --user sail laravel.test php artisan migrate
docker compose exec --user sail laravel.test php artisan db:seed --class=ComunicacionSeeder
docker compose exec --user sail laravel.test npm run build
docker compose up -d --no-build comunidad.worker
docker compose logs --tail=50 comunidad.worker
```

Después de cambiar código PHP del envío: `docker compose restart comunidad.worker`. Para una copia nueva, construir primero la imagen de Laravel siguiendo la Entrega 1. El worker necesita `vendor`, `.env`, MongoDB y las migraciones.

Diagnóstico sin proceso permanente:

```bash
docker compose exec --user sail laravel.test php artisan queue:work comunidad --queue=comunidad --stop-when-empty
```

Si el worker se detiene, los trabajos quedan en MongoDB. Si una caída deja una campaña a medias sin otro lote programado, Reintentar pendientes vuelve a encolarla. Las excepciones se registran en Laravel; la pantalla muestra un mensaje general, no detalles internos.

## Integridad y límites

Índice único por campaña/destinatario, parcial para conservar notificaciones antiguas sin campaña; preferencias únicas por usuario/organización. Los IDs de audiencia y ejecución no se exponen en JSON.

Los bloqueos por archivos se comparten entre web y worker mediante el volumen del proyecto. Esto corresponde a un único host; antes de distribuirlo hay que usar un almacén de bloqueos compartido. Cambios de campaña, mensajes, auditoría y cola no forman una sola transacción en MongoDB standalone. La unicidad, el cursor, la identificación de ejecución y el reintento cubren interrupciones habituales; no sustituyen outbox/transacciones y conciliación para producción.

Las preferencias y membresías se evalúan al inicio de cada lote; no se revocan retroactivamente mensajes en curso. Cancelar espera a que termine el lote actual. Las restricciones de campus/perfil y reglas globales de notificaciones quedan pendientes del equipo 1.

## Demo

El seeder crea dos borradores, uno por organización. No envía mensajes y no restablece cambios si se repite. Cuentas y contraseñas: Entrega 1.

1. Entrar como `presidencia@campus.test`, abrir Comunicación y editar el borrador de Sistemas.
2. Abrir Vista previa y confirmar el número de destinatarios. Esperar a que los contadores se actualicen.
3. En otra sesión entrar como `estudiante@campus.test` y abrir Mi bandeja. Leer, destacar, archivar y restaurar el mensaje.
4. Silenciar Sistemas en Preferencias y crear otra campaña: la vista previa debe excluir a esa persona.
5. Probar una campaña de evento o beca propios y su enlace personal. Los borradores de beca nunca deben recibir campañas de solicitantes.

Usar exclusivamente las cuentas de demostración para estas pruebas locales.

## Verificación

```bash
docker compose exec --user sail laravel.test php artisan test --compact
docker compose exec --user sail laravel.test npm run build
```

Las pruebas usan exclusivamente `campus_digital_testing`. Incluyen el worker nativo de MongoDB, más de un lote con destinatarios distintos, permisos, privacidad, firmas de vista previa, preferencias, reintentos y cancelación. No ejecutar pruebas en paralelo contra la misma base.

### Resultado de esta entrega

- Suite completa: **86 pruebas aprobadas, 695 aserciones**, en `campus_digital_testing`.
- Compilación de Vue/Vite correcta y revisión de diferencias sin errores de espacios.
- Navegador Edge: creación, vista previa y confirmación desde la interfaz; entrega real por el servicio `comunidad.worker`; aislamiento entre destinatarios; contenido HTML mostrado como texto; lectura, importante, no leído, archivo/restauración y enlace interno.
- Preferencias: silenciar una organización reduce la audiencia y evita la siguiente entrega; al terminar la prueba se restableció la recepción. La métrica de primera lectura se conserva al marcar sin leer.
- Cancelación de borrador y vista restringida para usuarios sin presidencia verificadas. Bandeja revisada a 390 px sin desbordamiento horizontal ni errores JavaScript no controlados.

Las cuentas de demostración conservan las campañas y mensajes creados durante esta comprobación, además de los dos borradores del seeder. Son datos de prueba internos.

## Siguiente bloque

Encuestas y votaciones internas: implementadas en [Entrega 5](Entrega_5_Encuestas_Votaciones.md). Reportes y dashboard con datos agregados: [Entrega 6](Entrega_6_Transparencia_Dashboard.md). Las integraciones externas de identidad, pagos, servicios, correo/push y auditoría global siguen pendientes.
