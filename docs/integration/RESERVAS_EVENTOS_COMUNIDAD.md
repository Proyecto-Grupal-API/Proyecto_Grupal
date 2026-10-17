# Reservas y reinscripciones de eventos

Incremento local del **17 de octubre de 2026**, entorno alterno integrado
E1–E3–E4–E5–E6. Atiende reservas y continuidad de inscripciones del apartado
**6.6**, página 12 de `Campus_Digital_Diseno_Modulos.pdf`.

## Comportamiento

- Un lugar confirmado con pago pendiente dura **15 minutos** desde su asignación,
  con límite en el inicio del evento. El vencimiento es exclusivo: al alcanzar
  la fecha límite ya no cuenta como lugar reservado.
- La lista de espera no consume ese plazo. Al obtener un lugar por orden FIFO,
  empieza su propio plazo, aunque la inscripción general ya haya cerrado; nunca
  se promueve después del inicio del evento. Las nuevas inscripciones siguen
  limitadas por su ventana de apertura/cierre.
- Un evento gratuito, un pago confirmado o una asistencia registrada no se
  vencen por esta regla. El sistema no confirma pagos ni concede excepciones
  financieras por un cargo o por presentar un comprobante.
- Al vencer se conserva la inscripción como `vencida`, se invalida su token y
  queda auditoría. La reserva pendiente no expone un QR de acceso.
- Los pendientes anteriores sin plazo reciben **una sola gracia de 15 minutos**
  al conciliarlos, limitada también por el inicio. Si el evento ya inició,
  esa reserva pendiente se reconoce vencida. Los pagados no se alteran.
- Catálogo, boletos, inscritos, resúmenes, métricas y audiencias de campañas
  reconocen el vencimiento por fecha aunque el scheduler esté detenido. El
  comando guarda la transición y promueve la espera al volver a ejecutarse.
- Escanear un boleto no promueve inscripciones en espera. Staff sigue exigiendo
  inscripción confirmada, pago pagado/exento y ventana de acceso válida.

## Vistas

El catálogo muestra la fecha límite y permite **Volver a reservar** cuando la
reserva anterior terminó y todavía hay disponibilidad/inscripción abierta.
**Mis boletos** muestra el plazo, la espera y la reserva vencida; conserva su
actualización periódica de 20 segundos. La lista administrativa filtra
inscripciones **Vencidas** y muestra su plazo. Horarios en Ciudad de México.

El pago todavía no está disponible en esta integración. Reservar no cobra,
no crea saldo adeudado ni habilita acceso para un evento con costo. Los pagos,
respuestas tardías, reembolsos y confirmaciones financieras requieren el contrato
y la operación reales de E2. Este incremento no implementa NFC de identidad.

## API de sesión e intentos

Se conservan los endpoints autenticados y CSRF existentes:

- `POST /api/eventos/{id}/inscripcion`: reservar o reinscribirse expresamente.
- `DELETE /api/eventos/{id}/inscripcion`: cancelar la inscripción propia.
- `GET /api/eventos`, `/api/eventos/{id}/boleto`, `/api/estudiante/boletos` y
  `/api/eventos/{id}/inscritos`: añaden estado/plazo/generación en sus resúmenes.
  Inscritos admite el filtro `estado=vencida` con su paginación existente.

La vista envía estos campos en POST o en el cuerpo de DELETE:

```json
{
  "clave_solicitud": "UUID generada una vez por intención",
  "generacion": 0
}
```

`generacion` es la versión observada: **0** si nunca existió inscripción; el valor
recibido en el resumen si ya existe. No es el número que el cliente desea asignar.
Ambos campos se requieren juntos. La UUID se conserva al repetir la misma acción,
evento y generación en la vista. El importe y los estados se calculan en servidor.

Se mantiene una inscripción actual por persona/evento. Cada reinscripción tras
cancelación o vencimiento incrementa `generacion` y guarda el estado anterior
en auditoría, incluidos importe, moneda y fechas. Promover la espera conserva
la generación y el precio de esa inscripción.

- Misma clave/actor/acción/evento/generación: devuelve el **estado actual**, con
  `repetida: true` y `generacion_solicitud` del resultado original, sin repetir
  la operación ni ampliar plazos. El estado actual puede ser de otra generación.
- Reutilizar la clave con otra intención o enviar una generación desactualizada:
  **409**. Datos inválidos o campos incompletos: **422**.
- Una cancelación antigua no cancela una reinscripción posterior. Repetir el
  alta antigua tampoco reabre una reserva vencida; se necesita otra intención.
- Los clientes anteriores conservan su contrato en registros que no han optado
  por solicitudes versionadas. Al recibir una solicitud versionada, ese registro
  exige clave/generación para sus siguientes cambios; llamadas antiguas sin ellas
  reciben 409 y deben actualizarse. Una reserva vencida exige reinscripción explícita.
- Registros heredados sin generación se proyectan como generación 1; no se inventa
  una serie histórica de intentos anteriores.

Los recibos se guardan en `solicitudes_reservas_eventos`, con índice único por
usuario/clave, sin TTL. La clave también se vincula a evento, acción y generación.
No contienen QR ni representan intentos o recibos financieros de E2.

## Ejecución y recuperación

Se reutilizan el bloqueo de responsabilidades y el bloqueo por evento. La clave
del bloqueo usa el identificador normalizado. Las escrituras, sus recibos y
auditorías comparten una transacción MongoDB. Un fallo de auditoría o recibo
revierte el cambio. No se ejecutan efectos externos desde esa transacción.

El scheduler del alterno concilia **cada minuto**, con protección frente a
solapamiento. Se puede conciliar manualmente, sin ejecutar cobros:

```bash
php artisan comunidad:expirar-reservas-eventos
```

Para una copia preparada, aplicar como UID/GID `1000:1000`, sin reseed:

```bash
php artisan migrate --path=database/migrations/2026_10_17_000000_index_reservas_temporales_eventos.php --force
php artisan migrate --path=database/migrations/2026_10_17_000100_create_solicitudes_reservas_eventos.php --force
```

Ambas migraciones ya están aplicadas al alterno de esta laptop. No borrar los
recibos para reintentar ni ejecutar rollback para pausar. Un código anterior a
este incremento no conoce las generaciones: antes de una reversión se deben
detener las escrituras y conservar inscripciones, recibos y auditoría del mismo
corte. Volver a ejecutar el comando no repite transiciones ya aplicadas.

Las lecturas heredadas de identidad siguen registradas en
`Dependencias_Equipo_1_Final.md`; este cambio no añade consultas nuevas a E1 ni
modifica OAuth, roles institucionales o autoridad financiera.

## Verificación del incremento

Cierre local del **17 de octubre de 2026**. Código verificado: `15a737b`
(junto con `1c3c6c6`, primer incremento de vencimiento).

- Vencimiento: siete pruebas. RED observado por ausencia de fecha límite y por
  lectores que seguían contando reservas vencidas.
- Solicitudes: cinco pruebas. RED observado por ausencia de generación.
- Registro/vencimiento/solicitudes: **12 pruebas aprobadas, 149 aserciones**, 46.02 s.
- Regresión con eventos, Staff, Comunidad, comunicación y reportes:
  **81 pruebas aprobadas, 769 aserciones**, 260.44 s.
- Suite integrada completa: **1,173 pruebas aprobadas, 8,476 aserciones**,
  sin fallos, errores ni omisiones. Se ejecutaron en serie **85 archivos en 15
  bloques**, únicamente contra `campus_virtual_testing`, como UID/GID `1000:1000`.
  Tiempo acumulado de los bloques: **1884.25 segundos**. La comprobación
  independiente de los XML cotejó todos los casos con el inventario del mismo
  commit: sin pruebas duplicadas ni faltantes.
- Compilación final de las vistas y ajuste de contraste correcta: **12.12 segundos**.

En Edge aislado se descartó una respuesta de alta ya procesada y la vista repitió
la misma UUID/generación, sin duplicar la inscripción ni cambiar su fecha límite.
La reserva quedó con pago pendiente y QR bloqueado; la espera inicial no tenía
plazo. Se comprobó el título blanco legible y ausencia de desbordamiento horizontal
a 320/768/1024/1440 píxeles.

Se esperaron los **15 minutos reales**: el scheduler venció la primera reserva y
promovió a la siguiente persona, con su propio plazo de 15 minutos. Se verificó
el boleto vencido, la promoción y el filtro administrativo de vencidos. Al terminar
se cancelaron la reserva promovida y el evento de demostración desde la interfaz;
el registro vencido se conservó. No se generaron cobros ni accesos pagados.

Durante esta comprobación se observó un error transitorio de red
`net::ERR_NO_BUFFER_SPACE` en la consulta periódica de notificaciones. El flujo
funcional y la limpieza concluyeron, pero falló la comprobación final que exigía
consola vacía; se conservó el registro original. No se modificó el código de
notificaciones para ocultarlo. En la comprobación posterior, tres sesiones nuevas
verificaron los estados y la limpieza, con **13 respuestas HTTP 200** de
notificaciones, sin errores JavaScript, de consola ni de red. El error no se
reprodujo; no se establece una causa permanente con esta evidencia.

Evidencia local excluida de Git: `storage/logs/reservas-eventos-20261017/`, incluidos
`validacion/manifest.xml`, `progress.json`, los 15 reportes JUnit y registros de
navegador. Las capturas y el estado de la demostración se conservan en la carpeta
de visualizaciones de este chat.

Los informes fechados anteriores conservan sus cifras. El avance queda local;
su publicación/fusión y la revisión de otro integrante corresponden al equipo.
La integración financiera real de E2 y NFC quedan fuera de este incremento.
