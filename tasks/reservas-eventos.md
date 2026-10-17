# Reservas de eventos: vencimiento e intentos

Base local `987b620`, alterno `/home/daniel/campus_digital_integracion_e1`.
Alcance: reservas, reinscripciones y reintentos de E6 (apartado 6.6 del PDF).
El usuario confirmó **15 minutos** para el pago pendiente; la espera no consume
ese plazo. No se implementan cobros, callbacks financieros ni devoluciones E2.

## Decisiones verificables

- La reserva confirmada con pago pendiente ocupa cupo durante 15 minutos desde
  su asignación/promoción, con límite en el inicio del evento. El fin es exclusivo.
  Gratuitos, exentos, pagados y asistencias registradas no vencen por esta regla.
- Al vencer conserva el registro como `vencida`, invalida su token, registra
  auditoría y promueve la espera FIFO. El reloj de quien esperaba empieza entonces.
  Se puede promover tras el cierre de inscripción, hasta el inicio del evento;
  no se admiten nuevas inscripciones fuera de la ventana existente.
- Los pendientes heredados sin plazo reciben una sola gracia de 15 minutos en
  su primera conciliación. No se inventa un pago ni se usa su antigüedad como cobro.
- Lecturas, cupos, campañas y resúmenes reconocen un vencimiento por fecha aunque
  el scheduler esté detenido. Conciliación bajo el mismo bloqueo por evento y
  transacción MongoDB; no repetir auditoría/promoción al reintentar.
- Se mantiene un registro actual por evento/persona. Cada reinscripción incrementa
  `generacion`; el historial anterior se conserva en auditoría, sin tokens QR.
- La vista envía una UUID estable por intención y la generación que observó.
  La misma clave/actor/datos devuelve el estado actual sin repetir efectos ni
  ampliar plazos. Otra intención con la clave devuelve 409. Una petición de una
  generación antigua no cancela ni resucita una inscripción posterior.
- Los endpoints existentes conservan compatibilidad con clientes anteriores en
  registros heredados. Al optar por solicitudes versionadas, ese registro exige
  clave/generación en sus siguientes cambios; una petición antigua sin ellas
  recibe 409. Un vencimiento siempre exige reinscripción explícita con nueva clave.
- Recibos de solicitudes en colección propia con unicidad de actor/clave, sin TTL.
  Resultado de reintento identifica su generación de origen y estado actual.
- Scheduler cada minuto y comando manual reanudable. Sin dependencias nuevas,
  ni lecturas nuevas de E1, cambios OAuth o autoridad financiera.

## Incrementos

- [x] Vencimiento: modelo, índices, transacción/bloqueo compartidos, promoción,
  comando y lecturas consistentes. RED, GREEN y regresión de eventos/Staff.
- [ ] Generaciones e idempotencia: recibos, comparación de versión, compatibilidad
  y rollback; pruebas de respuestas perdidas, claves incompatibles y bajas antiguas.
- [ ] Vistas: plazo en catálogo/boleto/lista de inscritos, reserva vencida,
  reinscripción explícita y reintento de la misma intención; compilar/navegador.
- [ ] Guía y revisión del alcance; suite serial reanudable, commits locales y
  árbol limpio. No publicar ni modificar la copia principal.

Las pruebas ejecutan únicamente `campus_virtual_testing`, en serie, UID/GID 1000.
Docker Desktop lo enciende el usuario. Se conservan informes anteriores y sus cifras.

## Verificación del vencimiento

- RED: una reserva pendiente se guardaba sin fecha límite.
- Primer GREEN: 6 pruebas aprobadas, 67 aserciones, 14.90 segundos.
- RED de lecturas: el listado de confirmados incluía la reserva vencida sin scheduler.
- La regresión detectó dos problemas del incremento: agrupación de confirmados/espera
  en MongoDB y promoción de espera al escanear un boleto. Se corrigieron con
  agrupación explícita y conciliación limitada al flujo de reservas, edición y comando.
- Regresión final: **76 pruebas aprobadas, 699 aserciones**, 237.22 segundos
  (ReservasEventos, Eventos, StaffEventos, Comunidad, Comunicacion y Reportes).
- El scheduler nuevo se mantiene sin activar hasta completar solicitudes y vistas.
