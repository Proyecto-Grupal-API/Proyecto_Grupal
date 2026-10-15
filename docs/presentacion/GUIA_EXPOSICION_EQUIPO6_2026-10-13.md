# Campus Digital · Equipo 6 · Comunidad

Guía de exposición para cinco integrantes · Ingeniería en Software

13 de octubre de 2026

## Objetivo y reparto

Presentar únicamente los resultados de Comunidad. Las conexiones con otros equipos se mencionan como dependencias de nuestros procesos. Fecha de corte: 13 de octubre de 2026.

Tiempo máximo: 15 minutos. Cinco bloques de 2:30, 30 segundos entre expositores y dos minutos de preguntas. La demostración de 45 segundos forma parte del bloque de Osvaldo.

[Abrir presentación editable en Canva](https://canva.link/l8yiiqs0zd3nhau)

| Integrante / etapa | Horario | Contenido | Duración |
| --- | --- | --- | --- |
| Abraham Daniel Beltrán González | 00:00–02:30 | Propósito, alcance y arquitectura | 2:30 |
| Cambio de expositor | 02:30–02:38 | Abraham → Jacquelin | 0:08 |
| Jacquelin | 02:38–05:08 | Asociaciones, Consejo y permisos contextuales | 2:30 |
| Cambio de expositor | 05:08–05:16 | Jacquelin → Diego | 0:08 |
| Diego Moran García | 05:16–07:46 | Becas y asignación de beneficios | 2:30 |
| Cambio de expositor | 07:46–07:53 | Diego → Osvaldo | 0:07 |
| Osvaldo Rodriguez Garcia | 07:53–10:23 | Eventos, boletos y Staff; demostración de 45 s | 2:30 |
| Cambio de expositor | 10:23–10:30 | Osvaldo → Yoana | 0:07 |
| Yoana | 10:30–13:00 | Comunicación, participación, transparencia y cierre | 2:30 |
| Preguntas | 13:00–15:00 | Respuestas breves según la especialidad de cada integrante | 2:00 |

Usen el guion como apoyo para ensayar y después expliquen con sus propias palabras. Mantengan el orden y las frases de transición. La validación técnica citada es la registrada en la documentación, no una nueva ejecución de pruebas.

---

## Mapa de diapositivas

| Diapositiva | Título | Quién presenta |
| --- | --- | --- |
| 1 | Campus Digital · Equipo 6 · Comunidad | Abraham Daniel Beltrán González |
| 2 | Propósito y alcance del módulo Comunidad | Abraham Daniel Beltrán González |
| 3 | Arquitectura y comunicación intermodular | Abraham Daniel Beltrán González |
| 4 | Asociaciones y Consejo | Jacquelin |
| 5 | Gestión de autorizaciones y roles | Jacquelin |
| 6 | Flujo de becas | Diego Moran García |
| 7 | Beneficios y conexiones pendientes | Diego Moran García |
| 8 | Eventos y Staff | Osvaldo Rodriguez Garcia |
| 9 | Validación de accesos · nota de demostración | Osvaldo Rodriguez Garcia |
| 10 | Comunicación, participación y transparencia | Yoana |
| 11 | Validación y estado actual | Yoana |
| 12 | Cierre y próximos pasos | Yoana |
| 13 | Preguntas | Todo el equipo |

Si ajustan el diseño o añaden diapositivas, revisen este mapa. Conserven la demostración dentro del tiempo de Osvaldo y abran preguntas al minuto 13.

---

## Abraham Daniel Beltrán González

Propósito, alcance y arquitectura · 2 minutos y medio · Diapositivas 1, 2, 3

### Ritmo sugerido

- 0:00–0:30: presentación y problema que resuelve Comunidad.

- 0:30–1:15: alcance y ejemplo de recorrido del estudiante.

- 1:15–2:10: MongoDB, vistas dinámicas y arquitectura por módulos.

- 2:10–2:30: estado local y transición.

### Guion para ensayar

Buenas tardes. Somos el Equipo 6 y desarrollamos Comunidad dentro de Campus Digital. Nuestro objetivo es que las actividades estudiantiles se administren desde el sistema: organizaciones, becas, eventos, comunicación, participación y transparencia.

Pensemos en un estudiante que se incorpora a una asociación, recibe un aviso, solicita una beca y asiste a una conferencia. Comunidad reúne esos procesos y relaciona cada acción con la organización y con el usuario que puede realizarla.

El avance incluye vistas dinámicas y persistencia de nuestros registros en MongoDB. Migramos el dominio de Comunidad que antes trabajábamos con SQL Server. La aplicación usa Laravel, Vue con Inertia y Vite, y la desarrollamos en Docker y WSL.

El proyecto se unifica en una aplicación Laravel, conservando responsabilidades por módulo. Nuestro módulo expone y consume APIs; los flujos que ya conectamos con otros equipos se comunican por HTTP. Aún quedan adaptaciones heredadas de Identidad, por lo que la regla de consultas exclusivamente por API sigue siendo una tarea de revisión.

Los resultados que presentamos corresponden al entorno local de integración. El inicio de sesión pertenece a Identidad; nuestra aportación comienza en los procesos de Comunidad.

### Transición

Ahora Jacquelin explicará cómo organizamos las asociaciones y cómo se controla quién puede administrarlas.

---

## Jacquelin

Asociaciones, Consejo y permisos contextuales · 2 minutos y medio · Diapositivas 4, 5

### Ritmo sugerido

- 0:00–0:50: organizaciones y membresías.

- 0:50–1:35: cargos, vigencias y diferencia con rol institucional.

- 1:35–2:10: ejemplo de autorización por organización.

- 2:10–2:30: alcance actual y transición.

### Guion para ensayar

En Comunidad manejamos organizaciones como las asociaciones estudiantiles y el Consejo. Cada una tiene integrantes, cargos y períodos de vigencia. Esto permite consultar quién participa y quién tiene una responsabilidad dentro de una organización.

Una decisión importante fue distinguir el cargo organizacional del rol institucional. Ser presidente de una asociación describe una responsabilidad en esa asociación. Ser coordinador de carrera o jefe de departamento representa una autoridad institucional y se coordina con el módulo de Identidad.

Los permisos se aplican considerando la organización seleccionada. Una persona que administra una asociación no obtiene por ese motivo permiso para modificar todas las demás. La presidencia tiene capacidades de gestión en su contexto; el estudiante consulta y realiza las acciones personales que le corresponden.

La vigencia también importa: una responsabilidad tiene que corresponder al período activo. Esta estructura prepara el sistema para cambios de mesa directiva sin confundir la identidad del estudiante con su cargo temporal.

Ya contamos con vistas y registros dinámicos de organizaciones, membresías y cargos. Algunos cargos se registran, pero todavía falta completar capacidades administrativas diferenciadas y los acuerdos de autoridad institucional. Presentamos esa distinción para que los siguientes módulos consulten información con responsabilidades claras.

### Transición

Con las organizaciones y sus responsables definidos, Diego nos mostrará el proceso de becas y cómo se distingue aprobar de entregar un beneficio.

---

## Diego Moran García

Becas y asignación de beneficios · 2 minutos y medio · Diapositivas 6, 7

### Ritmo sugerido

- 0:00–0:55: convocatoria, solicitud, documentación y dictamen.

- 0:55–1:40: beneficio y conexión local de impresión/lockers.

- 1:40–2:10: seguimiento y prevención de duplicados.

- 2:10–2:30: becas monetarias pendientes y transición.

### Guion para ensayar

El proceso de becas inicia con una convocatoria. El estudiante presenta su solicitud y la documentación requerida; después se revisa y se emite un dictamen. Comunidad conserva la solicitud y su estado para dar seguimiento al proceso.

Separamos la aprobación de la entrega del beneficio. Una beca aprobada expresa una decisión, pero no demuestra que el alumno ya recibió dinero, impresiones o un recurso. El estado de entrega se consulta por separado.

En el entorno local ya conectamos los beneficios de impresión y lockers con Servicios al estudiante mediante su API. Comunidad solicita la asignación y conserva el recibo que devuelve ese módulo. De esa manera podemos mostrar si el beneficio fue asignado o si todavía requiere seguimiento.

Cuando la comunicación falla, el proceso puede reintentarse con la misma referencia de solicitud. Esa referencia ayuda a evitar que una respuesta perdida termine duplicando el beneficio. La aprobación se conserva aunque el servicio externo no esté disponible en ese momento.

Las becas monetarias y los bonos aún dependen de Finanzas. Hoy no presentamos depósitos simulados como si fueran entregas reales. Nuestro resultado es el flujo de solicitud, revisión y seguimiento, junto con la conexión local comprobada para beneficios de Servicios.

### Transición

Además de los apoyos, Comunidad gestiona actividades. Osvaldo explicará los eventos y la validación de boletos por Staff.

---

## Osvaldo Rodriguez Garcia

Eventos, boletos y Staff · 2 minutos y medio · Diapositivas 8, 9

### Ritmo sugerido

- 0:00–0:45: publicación, cupo, lista de espera e inscripción.

- 0:45–1:15: boleto y reglas de acceso.

- 1:15–2:00: demostración preparada de 45 segundos.

- 2:00–2:30: alcance de Staff, pago pendiente y transición.

### Guion para ensayar

En eventos podemos publicar una actividad, definir su cupo, gestionar inscripciones y mantener una lista de espera. El estudiante consulta su registro y su boleto QR. Staff tiene una vista para validar entradas en los eventos que le fueron asignados.

El boleto debe corresponder a una inscripción con lugar confirmado y a un pago válido o exento. Un evento gratuito nos permite mostrar el recorrido completo. Si un evento tiene costo y el pago está pendiente, el boleto no autoriza la entrada.

Ahora mostraremos un boleto de prueba para un evento gratuito. En la sesión de Staff seleccionamos el evento, validamos el código y vemos el resultado de acceso. Si volvemos a validar el mismo boleto, el sistema debe indicar que la entrada ya se registró.

Este control evita contar dos veces el mismo acceso. Tener una credencial o conocer un QR no sustituye la revisión de la inscripción y de su estado.

Staff se limita a la operación de acceso de sus eventos. Los eventos con costo permiten reservas pendientes, y los cobros reales se conectarán con la wallet cuando se cierre esa integración.

### Transición

Para terminar, Yoana explicará cómo informamos a los estudiantes, cómo participan y qué resultados hemos validado.

---

## Yoana

Comunicación, participación, transparencia y cierre · 2 minutos y medio · Diapositivas 10, 11, 12

### Ritmo sugerido

- 0:00–0:45: campañas, avisos, bandeja y preferencias.

- 0:45–1:20: consultas, votaciones y reportes.

- 1:20–1:55: validación registrada y entorno local.

- 1:55–2:30: dependencias pendientes y cierre.

### Guion para ensayar

Comunidad también reúne la comunicación con los estudiantes. Podemos trabajar campañas y avisos segmentados, consultar una bandeja común, marcar lecturas y respetar preferencias. Esto ayuda a que cada usuario reciba los mensajes relacionados con sus actividades.

La participación se apoya en consultas y votaciones. Para transparencia contamos con reportes y registros publicados que permiten consultar información de la organización. Estos reportes todavía no representan una conciliación automática de la wallet.

La documentación registra una revisión específica del 9 de octubre con 30 pruebas y 327 aserciones sobre avisos e integración de Comunidad con Identidad y Servicios. Es un conjunto concreto de pruebas, no la totalidad de Campus Digital. También quedaron documentadas la compilación y las comprobaciones HTTP de los flujos locales.

Los siguientes pasos de nuestro módulo son terminar las adaptaciones de APIs y conectar las dependencias pendientes: pagos y bonos, recursos para eventos, Marketplace de asociaciones y auditoría central. Cada conexión necesita pruebas conjuntas y debe conservar los permisos de su contexto.

El resultado es un módulo con procesos dinámicos y reglas de acceso, listo para seguir integrándose por etapas. Gracias; podemos responder sus preguntas.

### Transición

Abrimos dos minutos de preguntas. Cada integrante responde principalmente sobre el bloque que presentó.

---

## Demostración preparada · 45 segundos

Elegir un evento gratuito para mostrar el ciclo de Comunidad sin depender de la wallet. Este es un plan de demostración: no se ejecutó ni se crearon datos durante la preparación de estos materiales.

| Tiempo | Acción | Preparación / resultado |
| --- | --- | --- |
| 0–10 s | Mostrar boleto ya preparado | Cuenta de estudiante; /modulo6/mis-boletos. Elegir evento gratuito con lugar confirmado y estado exento. |
| 10–20 s | Cambiar a Staff | Segunda sesión previamente iniciada en /modulo6/staff. Elegir ese mismo evento. |
| 20–35 s | Validar el código | Usar el mecanismo habilitado en la vista; mostrar la respuesta de acceso y el registro de asistencia. |
| 35–45 s | Repetir validación | Mostrar el rechazo de una segunda entrada con el mismo boleto y regresar a las diapositivas. |

### Antes de exponer

- Antes de clase, comprobar que http://127.0.0.1:8086/login carga y que las cuentas de prueba pueden abrir Comunidad. La dirección solo corresponde al entorno local de esta laptop.

- Preparar un evento gratuito publicado, con cupo disponible, una inscripción confirmada y un boleto exento. Verificar que la fecha y el horario estén dentro de la ventana de acceso; la configuración local abre el check-in 30 minutos antes del evento.

- Asignar la cuenta de Staff a ese evento y abrir dos perfiles de navegador o sesiones separadas. Dejar el boleto visible en una y el evento de Staff listo en la otra.

- Ensayar la validación con un boleto de prueba y reservar otro sin usar para la exposición: el primer acceso consume la entrada.

- Staff permite escanear o introducir el código. Si se usará cámara, comprobar sus permisos antes; dejar también listo el código del boleto para usar la entrada manual como alternativa.

- Abraham controla las diapositivas; los demás hablan. Acordar un aviso discreto cuando falten 20 segundos. El reparto sirve para la exposición y no atribuye autoría individual del código.

- Ensayar una vez completa con cronómetro: 12:30 de explicación y demostración, 0:30 de cambios, 2:00 de preguntas.

- Tener capturas reales propias del flujo como respaldo si falla el entorno; explicar que corresponden a una prueba previa. Las ilustraciones de las diapositivas son diagramas, no capturas del software.

- Compartir la presentación y esta guía con los cinco. Conservar los nombres exactamente como fueron proporcionados. No mostrar contraseñas, tokens, datos de alumnos reales ni el contenido de .env.

---

## Respuestas para preguntas

### ¿Por qué MongoDB? · Abraham

Comunidad adoptó MongoDB en el acuerdo de arquitectura del grupo; migramos nuestros registros y mantenemos sus reglas e índices. Finanzas conserva SQL Server. La elección no elimina la necesidad de validar relaciones y consistencia.

### ¿Todas las consultas son por API? · Abraham

Las vistas de Comunidad usan sus APIs y los flujos intermodulares conectados usan HTTP. Hay adaptaciones heredadas de Identidad que todavía debemos reemplazar; no certificamos todo el sistema como concluido.

### ¿Un cargo concede permiso en todo Campus Digital? · Jacquelin

Su alcance depende de la organización y la vigencia. Un cargo de asociación no sustituye un rol institucional ni una autorización financiera.

### ¿Una beca aprobada ya está entregada? · Diego

No. El dictamen y la entrega son estados distintos. Impresión y lockers tienen conexión local con Servicios; dinero y bonos esperan a Finanzas.

### ¿Ya cobran los eventos? · Osvaldo

Todavía no hay cobros reales conectados. Se aceptan reservas pendientes; para entrar se exige inscripción confirmada y pago válido o exención.

### ¿Qué pasa si reutilizan un boleto? · Osvaldo

La validación debe impedir una segunda entrada y conservar el primer registro de asistencia. Se puede demostrar con el mismo boleto de prueba.

### ¿Ya está integrado todo el proyecto? · Yoana

No. Exponemos resultados locales de Comunidad y sus flujos conectados. Las demás dependencias se incorporarán por etapas y con pruebas conjuntas.

### ¿Qué demuestran las 30 pruebas? · Yoana

Son la revisión registrada del 09/10/2026 de tres conjuntos concretos de avisos e integración. No son la suite completa ni pruebas ejecutadas durante la preparación de esta presentación.

### Fuentes internas del contenido

- docs/integration/INTEGRACION_1_5_6.md: flujos HTTP conectados, estados pendientes y validación registrada.

- app/Models/Documento.php: conexión MongoDB del dominio de Comunidad.

- routes/web.php y routes/comunidad.php: vistas y endpoints de Comunidad.

- resources/js/Pages/Modulo6/: vistas dinámicas de asociaciones, becas, eventos, Staff, comunicación, participación y transparencia.

- config/comunidad.php: horario y ventana de validación de acceso.

Los archivos citados corresponden al checkout alterno /home/daniel/campus_digital_integracion_e1. Este material documenta el avance; no modifica las vistas, los datos ni los contenedores.
