# Campus Digital · Comunidad · Guía profunda

Presentación nueva: [Abrir en Canva](https://canva.link/e9g7ow7qw5qdgxk).

23 diapositivas: 17 principales, incluida Preguntas, y 6 anexos. Cinco bloques de 2:30, 0:30 de cambios y 2:00 de preguntas. Corte del contenido: 14/10/2026.

| Integrante | Diapositivas | Horario |
| --- | --- | --- |
| Abraham Daniel Beltrán González | 1–4 | 00:00–02:30 |
| Jacquelin | 5–7 | 02:38–05:08 |
| Diego Moran García | 8–10 | 05:16–07:46 |
| Osvaldo Rodriguez Garcia | 11–13 | 07:53–10:23 |
| Yoana | 14–16 | 10:30–13:00 |
| Preguntas | 17 | 13:00–15:00 |

Transiciones de 8, 8, 7 y 7 segundos. Demo de 45 segundos dentro del bloque de Osvaldo. Anexos 18–23 según las preguntas. La asignación de exposición no atribuye autoría individual del código.

## 1. Campus Digital · Comunidad

Expositor: Abraham Daniel Beltrán González

- Campus Digital · · Comunidad

- Flujos, reglas y resultados

- Equipo 6 · Comunidad · Ingeniería en Software

- Abraham Daniel Beltrán González · Jacquelin · Diego Moran García · Osvaldo Rodriguez Garcia · Yoana

**Qué explicar:** Presentar al equipo y plantear el resultado central: el módulo ya administra procesos, estados y permisos, además de mostrar vistas. La exposición sigue un caso ilustrativo de organización, apoyo y evento. La portada no incluye una fecha de exposición no confirmada.

## 2. Problema y recorrido de Comunidad

Expositor: Abraham Daniel Beltrán González

- Organización

- Membresía / cargo

- Actividad

- Solicitud / participación

- Reporte / auditoría

- Caso · impresiones

- Estudiante A solicita impresiones → se valida elegibilidad → queda evidencia de asignación.

- Caso · conferencia

- Se inscribe → obtiene boleto → asiste → el check-in queda auditado.

- Regla transversal

- La organización administra integrantes y actividades; Comunidad enlaza datos, estados y autorización.

- Resultado

- Un ciclo completo, trazable, desde la intención de la persona hasta el reporte.

**Qué explicar:** Usar el caso para conectar funciones: la membresía permite participar en procesos internos, una solicitud deja historial y un evento produce una inscripción. Las pantallas consultan registros que cambian de estado; las acciones no se quedan en una maqueta.

## 3. Arquitectura técnica y comunicaciones

Expositor: Abraham Daniel Beltrán González

- Vue + Inertia + Vite

- Laravel 13 · dominios

- MongoDB

- APIs de servicio

- Desarrollo

- Docker + WSL. Una aplicación modular; no siete servidores Laravel.

- Identidad

- E1 aporta usuario y roles. User._id es referencia externa; matrícula no es ID canónico.

- Seguridad

- Sesión + CSRF en la interfaz; APIs HTTP con autorización de servicio entre módulos.

- Decisión central · La autoridad se evalúa en contexto: identidad, cargo vigente, recurso y acción.

**Qué explicar:** Explicar la propiedad de datos: Comunidad administra su dominio; conocer un usuario no equivale a tener autoridad para cualquier acción. MongoDB conserva referencias, índices y reglas. La migración no implica que desaparezcan las relaciones ni que todo Campus Digital esté certificado como exclusivamente API.

## 4. Propiedad y persistencia de datos

Expositor: Abraham Daniel Beltrán González

- Organización

- Organizaciones · membresías · cargos

- Operación

- Eventos · reservas · Staff

- Beneficios

- Convocatorias · solicitudes · asignaciones

- Participación

- Campañas · consultas · reportes · auditoría

- MongoDB

- IDs, referencias y fechas UTC; relaciones explícitas.

- Archivos

- Storage privado para documentos de becas.

- Finanzas

- SQL Server se conserva por acuerdo del dominio.

- Pendiente

- Adaptar consultas heredadas de Identidad.

- Propiedad clara + referencias auditadas · persistencia mixta sin confundir responsabilidades.

**Qué explicar:** Explicar la propiedad de datos: Comunidad guarda sus procesos y referencias en MongoDB; los archivos se conservan en Storage privado. Fechas UTC y visualización local. Finanzas mantiene SQL Server. Cambiar de motor no elimina relaciones ni autoriza consultar datos ajenos directamente.

## 5. Alta y ciclo de vida de organización

Expositor: Jacquelin

- Captura datos

- Configurando

- Membresía + presidencia válidas

- Activa

- Suspender

- Exige motivo, conserva historial y bloquea operaciones sensibles.

- Reactivar

- Revalida titular, membresía y cargos vigentes antes de habilitar.

- Completar alta

- Retoma una configuración interrumpida sin crear duplicados.

- Estados y versiones explícitos convierten bloqueos y validaciones en prevención, no en reparación.

**Qué explicar:** Mostrar el sentido de configurando: nadie administra una organización incompleta. La suspensión modifica permisos sin borrar la historia. El gestor institucional actual utiliza una autorización local auditada; el contrato institucional final con Identidad sigue pendiente.

## 6. Gestión de membresías y cargos

Expositor: Jacquelin

- Presidencia

- Autoridad vigente; su baja exige transferencia previa.

- Vicepresidencia

- Cargo registrado con vigencia; capacidades diferenciadas pendientes.

- Tesorería

- Cargo organizacional; no implica permisos financieros institucionales.

- Secretaría

- Vigencia y retiro quedan en historial.

- Comunicación

- Cargo registrado. Envío actual de campañas: presidencia vigente.

- Integrante

- Pertenecer no otorga autoridad. La baja revoca cargos y no los restaura.

- Vista funcional · editar perfil · alta/baja · asignar/retirar cargo · consultar historial preservado.

**Qué explicar:** Distinguir persona, membresía y cargo temporal. El nombre de un cargo registrado no garantiza que todas sus capacidades diferenciadas estén implementadas. La presidencia vigente ya tiene gestión contextual y las bajas conservan historial.

## 7. Matriz de alcance y ejemplo de impacto

Expositor: Jacquelin

- Estudiante

- Acciones personales.

- Integrante

- Consulta y participación elegible.

- Presidencia

- Gestiona su organización.

- Staff

- Sólo evento asignado.

- Gestor

- Alta, suspensión y reactivación; no acceso universal.

- Baja de integrante

- Revoca cargos y Staff; conserva historial y evidencia.

- Suspensión

- Bloquea nuevas inscripciones y check-in; pausa campañas por lote y conserva expedientes.

- Roles institucionales ≠ cargos organizacionales ≠ permisos financieros. Autorización granular y auditada.

**Qué explicar:** Leer la matriz por alcance, no como jerarquía universal. No se debe asumir que ser administrador o tener QR autoriza operar una wallet. Coordinador y jefe de departamento son roles institucionales de Identidad; no inventamos su asignación dentro de Comunidad.

## 8. Ciclo de convocatoria y solicitudes

Expositor: Diego Moran García

- Borrador editable

- Publicación fija reglas

- Recepción

- Cierre

- Dictámenes

- Borrador + folio

- Documentar + enviar

- Revisión

- Aprobada / rechazada

- Una solicitud por estudiante y convocatoria · borradores privados no consumen cupo ni entran a revisión · retirar es definitivo.

- Inmutabilidad

- Requisitos y beneficios quedan fijos al publicar.

- Control

- Estados únicos evitan revisión prematura y solicitudes duplicadas.

**Qué explicar:** Separar el recorrido de la convocatoria del de cada solicitud. Después del envío el expediente se congela; después del cierre se revisa. El cupo cuenta aprobaciones, por lo que dos revisores no deben conceder el último lugar a dos personas.

## 9. Documentos y validaciones

Expositor: Diego Moran García

- Entrada

- PDF, JPG o PNG · máximo cinco archivos · 5 MB cada uno.

- Asociación

- Cada obligatorio corresponde a un requisito específico.

- Acceso

- Estudiante edita el borrador; responsable accede después del envío.

- Descarga

- Autorizada, auditada y sin enlace público.

- Alcance

- Validar tipo y tamaño no equivale a antivirus.

- Resultado

- Privacidad y control por rol + estado del expediente.

- Principio · un archivo no se vuelve visible por existir; se vuelve accesible por una decisión autorizada.

**Qué explicar:** Explicar quién ve el expediente y cuándo. La validación de tipo y tamaño no es un análisis antivirus. Las decisiones aprobada/rechazada son definitivas en el alcance actual, y el responsable no puede dictaminar su propia solicitud.

## 10. Dictamen y entrega de apoyos

Expositor: Diego Moran García

- Dictamen aprobado

- pendiente_integracion

- Worker → Servicios

- asignada + recibo

- Dictamen

- Motivo y evidencia obligatorios. Nadie autoaprueba su solicitud.

- Cupo

- Aprobar consume cupo; decisiones definitivas en el alcance actual.

- Idempotencia

- Clave beca:{solicitud_id}; reintentos no duplican la asignación.

- Ejemplo · 100 impresiones aprobadas → asignación vía API → recibo conservado. Dictaminar no es entregar.

**Qué explicar:** La aprobación permanece aunque el servicio no responda. El estado asignada describe la confirmación del recurso o saldo en Servicios, no un depósito monetario. Los servicios usan cantidad y monto cero; el dinero se expresa en centavos enteros.

## 11. Eventos: flujos y gestión de cupo

Expositor: Osvaldo Rodriguez Garcia

- Borrador

- Publicado

- Inscripción

- Confirmada / espera

- Cupo

- Las reservas confirmadas ocupan cupo, incluso con pago pendiente; reintentar no duplica.

- Promoción

- Cancelación o ampliación promueve FIFO antes del inicio.

- Pago

- La reserva no expira automáticamente en el alcance actual.

- Cupo 2 · A y B confirmados · C en espera · B cancela → C se promueve de forma trazable.

**Qué explicar:** Explicar qué cambia en el sistema cuando alguien cancela. La implementación no expira automáticamente reservas pendientes de pago. Con reservas activas se protegen precio, ubicación y fechas; el cupo no se reduce por debajo de confirmados.

## 12. Boleto, Staff y validaciones de acceso

Expositor: Osvaldo Rodriguez Garcia

- Debe cumplir

- Confirmada · pagado/exento · organización y evento activos.

- Contexto

- Staff vigente y asignado · ventana: 30 min antes al final.

- Un solo acceso

- Boleto sin asistencia previa y perteneciente al evento.

- Rechazos

- Pendiente · espera · cancelación · segundo acceso · evento distinto.

- Privacidad

- QR privado, tokens ocultos y auditoría estricta.

- Métodos

- Cámara · imagen QR · captura manual.

- Staff sólo ve eventos asignados: la herramienta reduce exposición además de validar reglas.

**Qué explicar:** El QR no es una autorización aislada. El servidor revisa los estados y el evento asignado a Staff. La repetición debe rechazarse aunque la imagen del boleto sea idéntica. Eventos gratuitos permiten comprobarlo sin depender de Finanzas.

## 13. Demostración · boleto en 45 segundos

Expositor: Osvaldo Rodriguez Garcia

- Evento de prueba

- Vista Staff lista

- Escanear boleto válido

- Acceso registrado

- Repetir → rechazo

- 0–15 s

- Abrir evento gratuito y mostrar inscripción confirmada.

- 15–30 s

- Validar: respuesta exitosa y asistencia registrada.

- 30–45 s

- Repetir token: rechazo seguro. Reserva pendiente tampoco accede.

- Sin datos reales · cuenta y evento de prueba · cambios bloqueados cuando existen reservas activas.

**Qué explicar:** Dejar dos sesiones abiertas antes de comenzar. La demostración forma parte de los 2:30 del bloque. Si el entorno falla, usar capturas reales preparadas y explicar que son una comprobación previa, sin inventar una ejecución en vivo.

## 14. Comunicación: campañas y entregas

Expositor: Yoana

- Borrador

- Vista previa

- Confirmar + congelar

- Cola MongoDB

- Lotes de 100

- Entregado / omitido

- Incluye

- Integrantes activos; confirmados/espera; envíos/aprobados de becas.

- Excluye

- Retirados, borradores y personas que silencian la organización.

- Reintentos

- Conservan audiencia; no duplican entregas ni reinician lecturas.

- Mensajería interna de Campus Digital · no SMS, correo ni push externo.

**Qué explicar:** Explicar por qué no se envía en la petición web: el worker continúa un trabajo persistente. Una campaña terminada distingue entregados y omitidos. Los nuevos integrantes no se incorporan a un envío que ya se confirmó y nadie lee mensajes ajenos por ser administrador.

## 15. Participación y reportes con transparencia

Expositor: Yoana

- Consulta

- Padrón fijo al publicar + membresía actual al responder. Una participación inmutable por persona; resultados agregados al cierre; voto asociado a cuenta.

- Reporte

- Periodo → cálculo servidor → borrador → publicación fija → CSV/impresión. Retirar conserva copia y restringe acceso; actualizar exige reporte nuevo.

- Límite

- No hay ganador automático ni asignación de cargos.

- Lectura responsable

- Montos aprobados no equivalen a pagos realizados.

- Transparencia significa resultados reproducibles, límites explícitos y evidencia que no se reescribe.

**Qué explicar:** La consulta responde quién puede participar y cuándo se muestran los resultados. El reporte responde qué se calculó y cuál copia fue publicada. Son procesos diferentes que evitan exponer expedientes o respuestas individuales en las interfaces.

## 16. Resultados, conexiones e integraciones propias

Expositor: Yoana

- E1 · identidad

- E6 · Comunidad

- E5 · servicios

- Marketplace local

- E7 · pendiente

- Operativo

- E6→E5 asigna impresiones/locker; E5→E6 envía avisos.

- Comercio

- E6 verifica organización, membresía y presidencia; Marketplace no es obra completa de E6.

- Pendiente

- E2 pagos/bonos/caja; recursos de eventos; legado Identidad; recompensas E7.

- Evidencia · regresión conjunta E1/E5/E6 documentada; no equivale a despliegue productivo.

**Qué explicar:** La API comercial comprueba organización activa, membresía y presidencia vigente. Hay una integración local nueva con Marketplace, pero no atribuimos sus funciones al equipo 6. Inventarios incorporado no implica que nuestros eventos ya reserven sus recursos. No se ejecutaron pruebas nuevas al preparar este material.

## 17. Preguntas

Expositor: Todo el equipo

- Preguntas

- Los anexos están disponibles para consulta durante las preguntas

**Qué explicar:** Abrir preguntas al minuto 13. Cada integrante responde sobre su bloque. No recorrer todos los anexos: abrir únicamente el que apoye la pregunta.

## 18. Modelo MongoDB por referencias

Apoyo para preguntas

- Organizaciones ↔ miembros / roles

- Eventos → registros / Staff

- Convocatorias → solicitudes → beneficios

- Campañas / consultas / reportes

- Convenciones

- Fechas UTC con vista local México · dinero en centavos.

- Índices únicos

- organización+usuario · evento+usuario · convocatoria+usuario.

- Más unicidad

- campaña+destinatario · consulta+usuario.

- MongoDB no elimina relaciones: las hace explícitas, referenciadas e indexadas.

**Qué explicar:** Los índices evitan membresías y registros repetidos, una solicitud por convocatoria/usuario y una entrega por campaña/destinatario. El diseño por referencias sustituye joins SQL; no elimina relaciones. La consistencia entre documentos y servicios requiere coordinación y conciliación.

## 19. API y propiedad de endpoints clave

Apoyo para preguntas

- Eventos

- GET /api/eventos · POST /api/eventos/{id}/inscripcion · POST /api/eventos/checkin

- Becas

- POST /api/becas/solicitudes/{id}/dictamen · Sesión + CSRF + autorización contextual

- Servicios

- POST /api/v1/servicios/asignaciones · scope services:benefits:write + Idempotency-Key

- Comercio

- GET /api/v1/comunidad/organizaciones/comercio?subject_id=... · scope community:organizations:read

- OAuth habilita al servicio; no reemplaza la autoridad de la persona. Contratos locales reales, no promesas externas.

**Qué explicar:** La API comercial recibe subject_id y devuelve organizaciones elegibles con can_request_store. Requiere membresía y presidencia vigentes en una organización activa. Los endpoints financieros propuestos no se presentan como implementados.

## 20. Beneficio recuperable · ficha y control

Apoyo para preguntas

- Entrada

- tipo=impresiones · cantidad=100 · monto_centavos=0

- Clave estable

- beca:{solicitud_id} · fin exclusivo

- Aprobación

- Confirma Comunidad con motivo y evidencia.

- pendiente_integracion

- Worker registra intento y próximo intento.

- asignada

- Servicios confirma con recibo persistido.

- Recuperación

- Timeout + misma clave idempotente = sin duplicar.

- Dinero, bonos y caja quedan fuera de esta integración hasta cerrar contratos con E2.

**Qué explicar:** Un timeout no obliga a crear otra solicitud ni a inventar una entrega. El recibo se guarda cuando Servicios devuelve asignacion_id. Dinero y bonos permanecen fuera de esta conexión y requieren fuente de fondos, autoridad y contrato de Finanzas.

## 21. Casos límite organizacionales

Apoyo para preguntas

- Vigencia futura / vencida

- Entrada → cargo no vigente · Regla → evaluar intervalo · Resultado → sin autoridad.

- Transferencia

- Entrada → baja de presidencia · Regla → sucesor válido · Resultado → transferir antes de baja.

- Reincorporación

- Entrada → alta posterior · Regla → no restaurar cargos · Resultado → nueva asignación explícita.

- Concurrencia

- Entrada → dos pestañas / alta interrumpida · Regla → versión + completar alta · Resultado → conflicto controlado, sin duplicar.

- La suspensión bloquea nuevas operaciones y conserva datos; el worker revalida por lote.

**Qué explicar:** La suspensión se consulta en el servidor, también si una pantalla ya estaba abierta. Los trabajos autorizados en curso pueden terminar antes de observarla: no existe transacción global que detenga todos los dominios al mismo instante.

## 22. Casos límite QR y eventos

Apoyo para preguntas

- Cupo lleno

- → espera

- Cancelación

- → promoción FIFO

- Pago pendiente

- → acceso rechazado

- Boleto usado

- → segundo acceso rechazado

- Staff no asignado

- → denegado

- Evento suspendido

- → denegado

- Reinscripción

- → token nuevo

- Reserva activa

- → cambios sensibles bloqueados

- Reintento

- → inscripción no duplicada

- El QR contiene el código privado del boleto. Ese código no se publica en listados ni auditoría.

**Qué explicar:** La reserva pendiente mantiene el lugar; no tiene vencimiento automático en el alcance actual. Cancelar y reinscribirse cambia el token. Cuando se habiliten pagos, la conciliación y los reembolsos deberán preservar estas reglas.

## 23. Comunicación, participación y evidencia

Apoyo para preguntas

- Campaña

- Audiencia congelada; permisos y preferencias se revalidan por lote.

- Consulta

- Padrón fijo; participación única e inmutable; agregado al cierre.

- Reporte

- Retirar no revoca un CSV ya descargado; anonimato limitado y explícito.

- Pruebas núcleo

- BecasTest.php · EventosTest.php · StaffEventosTest.php

- Pruebas comunidad

- ComunidadTest.php · ConsultasTest.php · ReportesTest.php

- Integración

- IntegracionServiciosComunidadTest.php · resultado esperado por regla.

- Evidencia existente, no pruebas nuevas. Producción exige revisión, conciliación y observabilidad operativa.

**Qué explicar:** Una baja impide nuevas participaciones sin borrar las anteriores. Los administradores no consultan conteos por opción mientras una consulta está abierta. Los textos libres de un reporte se deben revisar; los agregados no anonimizan automáticamente lo escrito por el responsable. Esta matriz conecta una afirmación de la exposición con su comprobación. La última integración documenta una regresión conjunta E1/E5/E6; su cifra no debe presentarse como una suite exclusiva del módulo 6. No se promete consistencia distribuida solo por tener índices únicos.
