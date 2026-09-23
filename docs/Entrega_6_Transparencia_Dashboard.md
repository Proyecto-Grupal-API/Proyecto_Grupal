# Entrega 6: Transparencia y dashboard

Flujo local del apartado 6.11: publicación de cifras agregadas sin expedientes ni respuestas individuales. Transparencia está en `/modulo6/transparencia` y el dashboard en `/modulo6`.

## Reportes

La presidencia vigente genera un borrador indicando título, contexto general y periodo de hasta 366 días. Las fechas son días de Ciudad de México, ambos inclusive; no se admite fecha final posterior a hoy. Los datos se calculan en el servidor y se guardan como arreglos BSON nativos. El cliente no puede proporcionar indicadores, organización, estado ni fechas de publicación.

La revisión muestra cada cifra con su criterio de cálculo. Confirmar publicación hace visible la misma copia para los integrantes actuales de la organización. No se recalcula al publicar ni al consultarla: para corregir el periodo o actualizar los datos se genera otro reporte. Borradores y retirados solo están disponibles para presidencia.

La clave de creación evita duplicados si se reintenta la solicitud. Reutilizarla con otros datos devuelve conflicto. Repetir una publicación no duplica su auditoría. El retiro requiere motivo, conserva la copia para revisión y bloquea su consulta/exportación por integrantes. No existe edición de cifras, borrado físico ni republicación de retirados. El retiro no puede revocar archivos que se hayan descargado antes.

Los textos de título y contexto los proporciona presidencia: debe revisar que no incluyan nombres, matrículas ni detalles personales antes de publicarlos. La aplicación calcula exclusivamente cifras agregadas, pero no anonimiza texto libre escrito por un administrador. En organizaciones pequeñas, los agregados pueden permitir inferencias; no se promete anonimato estadístico.

## Definición de indicadores v1

| Grupo | Selección y cifras |
| --- | --- |
| Eventos | Eventos publicados con inicio dentro del periodo y ya iniciado al generar; reservas confirmadas/en espera, pagos pendientes y asistencias con check-in. Los eventos cancelados se cuentan aparte según su inicio previsto. |
| Becas | Solicitudes enviadas dentro del periodo, clasificadas por su estado al generar. Excluye borradores y expedientes nunca enviados. |
| Apoyos | Solicitudes aprobadas cuyo dictamen cae en el periodo, aunque su envío sea anterior; cantidad de aprobaciones y personas distintas. Asignaciones pendientes de integración vinculadas a esas aprobaciones y suma de sus montos MXN. |
| Comunicación | Mensajes de campañas propias entregados en el periodo. Conserva entregas archivadas/eliminadas lógicamente. Cuenta primera lectura acumulada al generar, no solo lecturas ocurridas dentro del periodo. No publica contenido ni destinatarios. |
| Participación | Encuestas/votaciones cuyo cierre efectivo cae en el periodo, anticipado o por fecha, y cantidad de participaciones. Excluye abiertas, programadas, canceladas y borradores. No expone opciones elegidas ni padrón. |

Los estados son los observados al generar, **no una reconstrucción histórica de cómo estaba todo al final del periodo**. Las solicitudes retiradas/canceladas que se enviaron sí forman parte del total enviado. Las solicitudes enviadas y los dictámenes tienen distintas fechas de selección; sus totales no tienen por qué coincidir.

Aprobaciones, asignaciones pendientes y montos aprobados no significan dinero acreditado, pagos cobrados o servicios entregados. Caja y entrega externa siguen sin contrato integrado; no se presenta saldo o entrega ficticios. Los montos se almacenan/exportan en centavos enteros MXN y se muestran como moneda en pantalla.

Los reportes del formato anterior, sin `version = 1`, se conservan en la base, pero no se publican automáticamente: sus campos genéricos no garantizan los criterios ni el esquema de privacidad actuales. Las votaciones históricas conservan su lectura agregada en la sección de resultados.

## Exportación

- **CSV**: descarga autenticada con título, organización, estado, periodo, corte, contexto e indicadores/criterios. Los campos que podrían interpretarse como fórmulas se neutralizan; los importes indican explícitamente centavos MXN.
- **Imprimir / guardar PDF**: abre una vista HTML autenticada preparada para impresión; su botón abre el diálogo del navegador para imprimir o guardar como PDF. No requiere un generador PDF en Laravel ni envía datos a terceros. Incluye el estado, también para borradores/retirados consultados por presidencia.
- Lista, detalle y exportaciones vuelven a verificar organización y permisos; las respuestas usan caché privada sin almacenamiento. No hay enlaces públicos anónimos.

## Dashboard

- Miembros activos y eventos publicados próximos/en curso; los eventos que ya terminaron no cuentan como activos.
- Próximo evento o evento en curso con reservas confirmadas/capacidad y asistencias reales por check-in. Enlaces funcionales a Eventos y gestión de asistencia; se retiró el botón de escáner sin implementación.
- Presidencia ve expedientes enviados y solicitudes pendientes/en revisión. Cada estudiante ve únicamente sus solicitudes de la organización, incluidos sus propios borradores. No se devuelven nombres, motivos, documentos ni IDs del solicitante en el resumen.
- Mensajes sin leer personales de toda la bandeja; reservas propias de eventos próximos/en curso de la organización.
- Encuestas/votaciones abiertas en cuyo padrón está el usuario y que aún no ha respondido. No muestra participaciones de otros ni conteos de votaciones abiertas.
- Pendientes administrativos: campañas con entregas pendientes y reportes borrador, solo para presidencia.
- Actualización explícita con fecha/hora del servidor. Cuenta sin organización recibe una indicación útil y enlace a su bandeja.

## Operación y demo

Ejecutar desde `/home/daniel/campus_digital` en WSL:

```bash
docker compose exec --user sail laravel.test php artisan migrate
docker compose exec --user sail laravel.test php artisan db:seed --class=ReporteSeeder
docker compose exec --user sail laravel.test npm run build
```

El seeder requiere las organizaciones de Entrega 1 y crea un borrador por organización con cifras reales del mes actual. No publica, no inventa registros y conserva la primera copia al repetirse. Las cuentas de demostración y contraseñas siguen en Entrega 1.

1. Como `presidencia@campus.test`, entrar a Transparencia y generar o abrir un borrador.
2. Revisar periodo, contexto y criterios; confirmar publicación.
3. Como `estudiante@campus.test`, consultar el reporte publicado y exportarlo; no debe ver borradores.
4. Generar otra copia después de modificar actividades: el reporte anterior conserva sus cifras.
5. Retirar un reporte con motivo; comprobar que los integrantes ya no pueden consultarlo ni exportarlo.
6. Abrir el dashboard con cada cuenta y comprobar los distintos pendientes y enlaces.

## Verificación

```bash
docker compose exec --user sail laravel.test php artisan test --compact
docker compose exec --user sail laravel.test npm run build
```

Las pruebas limpian exclusivamente `campus_digital_testing`. No ejecutarlas en paralelo contra la misma base. Cubren permisos de lista/detalle/exportaciones, periodos y zona horaria, selección de poblaciones, cifras vacías, apoyos pendientes, privacidad, publicación y retiro, idempotencia, exportación segura, dashboard y seeder repetible.

### Resultado comprobado

- Suite completa: **115 pruebas aprobadas, 1004 aserciones**. Salida conservada en `storage/logs/validacion-entrega-6.log`.
- Compilación Vue/Vite correcta y revisión de diferencias sin errores de espacios.
- Edge: generar borrador, comprobar privacidad, publicar, consultar como estudiante, descargar CSV y renderizar la vista imprimible como PDF.
- Copia de 23 indicadores conservada al publicar y consultar; los permisos se vuelven a comprobar en cada exportación.
- Retiro verificado desde la interfaz: bloquea detalle, CSV e impresión para integrantes y conserva la copia para presidencia.
- Dashboard comprobado con presidencia y estudiante: pendientes distintos según rol, solicitudes privadas y cifras reales. Ambas vistas revisadas a 390 px sin desbordamiento horizontal ni errores JavaScript no controlados.

Las cuentas de demostración conservan los borradores y reportes creados durante la validación, incluido un reporte retirado para comprobar su acceso. Docker quedó funcionando con el proyecto de WSL.

## Límites e integraciones

Los cálculos leen varias colecciones de MongoDB standalone. La copia guarda las cifras observadas durante esa generación, no un snapshot transaccional simultáneo de toda la base. Cambios concurrentes pueden reflejarse de forma distinta entre indicadores. La bitácora local registra generación, publicación y retiro con metadatos; los cambios y su auditoría no forman una única transacción. Los bloqueos por archivos son para un host. Para despliegue distribuido faltan bloqueos compartidos, auditoría transaccional/conciliación y contratos de datos acordados.

Quedan las integraciones externas: identidad y permisos del equipo 1; pagos/bonos/caja del equipo 2; tiendas del equipo 3; servicios/beneficios del equipo 5; auditoría global del equipo 7. La siguiente revisión debe comparar el alcance completo del módulo 6 con el documento y preparar esos contratos y una demostración integrada.
