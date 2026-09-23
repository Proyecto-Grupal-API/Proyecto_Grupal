# Entrega 5: Encuestas y votaciones internas

Implementación local de los apartados 6.9 y 6.10 del documento del proyecto.

## Uso

En el menú aparecen **Encuestas** (`/modulo6/encuestas`) y **Votaciones** (`/modulo6/votaciones`). La presidencia vigente crea, edita y publica borradores de su organización. Los integrantes ven las consultas publicadas de cuyo padrón forman parte.

- Encuestas: 1–10 preguntas, cada una con 2–10 opciones y respuesta única obligatoria.
- Votaciones: una pregunta con 2–20 opciones o candidaturas. Es un proceso académico; no asigna cargos automáticamente, no requiere mayoría mínima ni resuelve empates.
- Título, descripción, apertura y cierre. Las fechas del formulario corresponden a Ciudad de México y se guardan en UTC.
- Borrador → revisar contenido/padrón → confirmar publicación → participar → resultados agregados al cierre.
- Antes de enviar se muestran las respuestas seleccionadas para confirmarlas o corregirlas. Después no se modifican.

## Población y autorización

La población inicial son los integrantes activos de la organización. Al publicar se guarda un padrón fijo de hasta 5000 cuentas existentes, excluyendo membresías futuras, inactivas o eliminadas. La firma de la vista previa vincula contenido, revisión y padrón; si cambian, se requiere una nueva revisión.

Las altas posteriores no se añaden. Para consultar o participar se exige además membresía activa actual. Una baja impide nuevas participaciones, sin borrar las ya registradas. Las preferencias de mensajes no afectan el derecho a participar. No hay filtros ficticios de campus/carrera/semestre: esos contratos siguen pendientes del equipo 1.

El padrón nominal no se expone en la API. La gestión exige presidencia vigente y organización activa; conserva la protección de organización seleccionada entre pestañas. Los integrantes solo ven borradores cuando tienen permiso de gestión.

## Periodo y resultados

La apertura es inclusiva y el cierre exclusivo: inicio ≤ ahora < fin. El servidor valida estas fechas en cada envío. El cierre por fecha funciona aunque nadie ejecute un cron; el estado visible se calcula a partir del periodo. Los GET no modifican la base.

Después de publicar quedan fijos preguntas, opciones, fechas y padrón. La presidencia puede cerrar anticipadamente una consulta iniciada, indicando un motivo; se muestran entonces los resultados. No existe reapertura. Puede cancelar antes del cierre, con motivo: conserva las participaciones pero no publica sus resultados.

Ni siquiera la presidencia consulta conteos por opción mientras está abierta. Al cerrar se muestran total de participaciones, tamaño del padrón y cantidades/porcentajes por pregunta. Los porcentajes se calculan sobre respuestas válidas, no sobre todo el padrón. Cero respuestas muestra ceros; los empates conservan sus conteos sin inventar un ganador. Transparencia muestra los resultados agregados de la última votación cerrada de la organización, también cuando cerró por fecha. Esa pantalla es visible para los integrantes actuales de la organización.

## Privacidad e integridad

Las respuestas se vinculan a la cuenta en la base para exigir unicidad. **No es voto secreto ni anonimato criptográfico**. Las interfaces y API solo exponen resultados agregados al cierre y el indicador personal de participación registrada. Los administradores de la aplicación no tienen una API para ver respuestas individuales; quien tenga acceso directo a la base sí podría relacionarlas. En grupos pequeños los agregados pueden permitir inferencias.

Modelos: `Encuesta`, `Eleccion`, `RespuestaEncuesta`, `Voto`. Las definiciones y preguntas se guardan en `encuestas` y `elecciones`; todas las respuestas de cada participante se guardan juntas en un único documento de `respuestas_encuestas` o `votos`. Un índice único `(consulta_id, usuario_id)` impide duplicados; en `votos` es parcial para conservar registros anteriores sin `consulta_id`. Preguntas, padrón y respuestas usan arreglos BSON nativos, sin el cast `array` de Laravel que los serializa como texto JSON en esta versión del adaptador. Una migración repetible convierte los documentos de prueba de esta entrega que habían usado JSON, conservando su contenido. Las opciones nuevas son embebidas, con UUID generados por el servidor. Transparencia conserva la lectura del formato histórico.

Se comprueba que todas las preguntas tengan exactamente una respuesta y que cada opción pertenezca a su pregunta. El cliente no elige usuario, organización, padrón ni estado. Repetir un envío devuelve confirmación de que ya se registró, conservando la primera selección, incluso si cerró entre los intentos. No se añade otro voto.

Los bloqueos por consulta serializan publicar, editar, cerrar, cancelar y responder en este host. Cada participación se inserta atómicamente como un documento; los resultados se calculan de las participaciones, sin contadores secundarios. La bitácora administrativa registra creación, edición, publicación, cierre y cancelación, sin respuestas ni padrón nominal; la propia participación conserva su fecha. El cierre por fecha es una regla derivada y no genera un evento de bitácora por cron.

Como en entregas anteriores, los bloqueos por archivos son locales a un host y los cambios de estado/auditoría no comparten una transacción en MongoDB standalone. Una instalación distribuida necesita bloqueos compartidos y una estrategia de auditoría transaccional/conciliación. Estas votaciones son académicas, no un sistema electoral certificado.

## Preparación y demo

Ejecutar desde `/home/daniel/campus_digital` en WSL:

```bash
docker compose up -d --no-build
docker compose exec --user sail laravel.test php artisan migrate
docker compose exec --user sail laravel.test php artisan db:seed --class=ParticipacionSeeder
docker compose exec --user sail laravel.test npm run build
```

El seeder requiere las organizaciones/cuentas de Entrega 1. Crea un borrador de encuesta y otro de votación por organización; no publica ni genera votos. Repetirlo conserva cambios manuales. Si el borrador envejece, editar sus fechas antes de publicarlo.

1. Iniciar sesión como `presidencia@campus.test` (contraseña de demo en Entrega 1).
2. Revisar un borrador, corregir fechas si hace falta y confirmar publicación.
3. En otra sesión, entrar como `estudiante@campus.test`, elegir respuestas, revisarlas y enviarlas.
4. Recargar: debe aparecer «Participación registrada» sin permitir otro voto.
5. Con presidencia, cerrar anticipadamente indicando el motivo; revisar los agregados desde ambas cuentas.
6. Para votaciones, consultar también Transparencia. Para cancelación, usar otra consulta: las participaciones se conservan sin resultados visibles.

## Verificación

```bash
docker compose exec --user sail laravel.test php artisan test --compact
docker compose exec --user sail laravel.test npm run build
```

Las pruebas usan exclusivamente `campus_digital_testing`, separada de la base de la aplicación. No correr pruebas en paralelo contra la misma base. Se verifican permisos, alcance por organización, validación de preguntas/opciones, revisiones, padrón fijo, fechas, unicidad, reintentos, cancelación, cierre, privacidad, empates, resultados vacíos y seeders repetibles.

### Resultado comprobado

- **101 pruebas aprobadas, 886 aserciones** en la suite completa. Las 15 pruebas nuevas incluyen la consulta del padrón en BSON nativo y la conversión repetible del formato JSON de prueba.
- Vue/Vite compila correctamente y `git diff --check` no reporta errores.
- Edge: crear, editar, revisar y publicar; participar con cuenta estudiantil; corregir antes de confirmar; impedir una segunda selección; cerrar y mostrar un empate real en Votaciones y Transparencia.
- Encuesta de dos preguntas: exige ambas respuestas y calcula sus agregados correctamente. Navegación entre las dos rutas que comparten la vista comprobada.
- Dos solicitudes HTTP simultáneas del mismo estudiante: una registra y la otra confirma la participación existente; total final de un voto.
- Votaciones revisada a 390 px, sin desbordamiento horizontal ni errores JavaScript no controlados. Cuenta sin organización muestra la indicación de membresía requerida.

Las cuentas de demostración conservan consultas y participaciones creadas durante estas pruebas. Docker quedó montando `/home/daniel/campus_digital`; los comandos de esta guía deben ejecutarse desde esa carpeta WSL, no desde la copia del Escritorio de Windows.

## Pendiente

Reportes de transparencia y dashboard implementados en [Entrega 6](Entrega_6_Transparencia_Dashboard.md). Las integraciones de identidad/permisos del equipo 1 y auditoría global del equipo 7 siguen pendientes, así como voto secreto, texto libre y otros tipos de preguntas si el alcance académico los requiere.
