# Revisión del módulo 6 — Comunidad

Fecha: 21 de septiembre de 2026. Estado revisado: seis entregas locales, con cambios aún sin commit.

**Actualización del 22 de septiembre:** [Entrega 7](Entrega_7_Staff_Continuidad.md) corrige R1 y R3 e incorpora recuperación por consola para R2, además del nuevo Staff por evento. Los periodos sucesivos y la autoridad institucional con equipo 1 siguen pendientes. El resto de este documento conserva el diagnóstico del corte original, no el estado posterior de esas correcciones.

El módulo tiene una base funcional en MongoDB y las pantallas principales trabajan con datos reales del servidor. Todavía no cumple todo el alcance del PDF ni la Definition of Done de integración. Conviene resolver primero la continuidad de administración y completar asociaciones, Consejo y permisos; después conectar los dominios vecinos. No hace falta rehacer las vistas ni volver a empezar la migración.

**Actualización — Entrega 8:** ya existe alta con presidencia inicial, suspensión/reactivación e historial, con permiso local de gestión separado. Ver [Entrega 8](Entrega_8_Gestion_Organizaciones.md). Los documentos, comisiones, periodos sucesivos y permisos institucionales siguen pendientes.

## Fuente y alcance

Se contrastaron los requisitos con [Campus Digital: diseño de módulos](Campus_Digital_Diseno_Modulos.pdf): sección 13, páginas 11–12; contratos, sección 15, página 13; reglas de datos, sección 16, páginas 13–14; Definition of Done, sección 21, página 16; criterio del equipo 6, sección 22, página 17.

Revisión de rutas, modelos, controladores, políticas, servicios, migraciones, seeders, componentes y pruebas del repositorio. Se reprodujeron tres comportamientos con pruebas temporales sobre `campus_digital_testing`. No se modificaron las funciones de la aplicación ni los datos de demostración como parte de esta revisión. Las propuestas de integración aún no están acordadas con otros equipos.

## Hallazgos prioritarios

### R1 — Alta: eliminar la cuenta de presidencia deja la organización sin administrador

[ProfileController::destroy](../app/Http/Controllers/ProfileController.php) elimina físicamente al usuario después de comprobar la contraseña, sin consultar sus responsabilidades. La protección de [OrganizacionController::removeMiembro](../app/Http/Controllers/OrganizacionController.php) contra retirar a presidencia no se ejecuta en esta ruta.

Reproducción comprobada: con los seeders en la base de pruebas, iniciar sesión como presidencia y enviar `DELETE /profile` con su contraseña válida. La respuesta redirige a `/`, el usuario desaparece, pero su membresía y cargo siguen activos. Ninguno de los usuarios restantes puede administrar Sistemas según la política.

Corrección propuesta: bloquear la eliminación mientras existan responsabilidades que deban transferirse y definir una baja coordinada con identidad. Revalidar la existencia del usuario al asignar cargos. Conservar el historial; no borrar en cascada solicitudes, votos ni auditorías. Comprobar eliminación sin cargo, titular vigente, titular futuro y operaciones concurrentes de transferencia/baja.

### R2 — Media: al vencer la presidencia no existe recuperación autorizada local

[OrganizacionPolicy::update](../app/Policies/OrganizacionPolicy.php) concede administración exclusivamente a presidencia vigente. [assignRol](../app/Http/Controllers/OrganizacionController.php) requiere ese permiso para renovar o transferir. El índice y la validación permiten una sola asignación no retirada por cargo, por lo que tampoco se puede preparar una segunda presidencia futura sin sustituir la actual.

Reproducción comprobada: vencer el cargo de Sistemas en la base de pruebas y solicitar su renovación devuelve `403`; ningún usuario de demostración conserva permiso para administrar esa organización. La caducidad de permisos funciona correctamente, pero falta el proceso de sucesión/recuperación.

Corrección propuesta: periodos con sucesor programado sin solapamiento y recuperación explícita por una autoridad acordada con el equipo 1, con motivo y auditoría. No prolongar automáticamente permisos vencidos ni conceder administración a cualquier integrante. Probar los límites de vigencia y el caso sin sucesor.

### R3 — Normalización pendiente: algunos documentos anidados se almacenan como texto JSON

Se comprobó la escritura y lectura nativa de MongoDB, además de la lectura por modelo:

| Modelo | Campos guardados como texto JSON |
| --- | --- |
| [ConvocatoriaBeca](../app/Models/ConvocatoriaBeca.php) | `beneficio`, `requisitos_documentos` |
| [SolicitudBeca](../app/Models/SolicitudBeca.php) | `documentos`, `dictamen` |
| [AsignacionBeneficio](../app/Models/AsignacionBeneficio.php) | `contrato` |
| [Campana](../app/Models/Campana.php) | `destinatarios` |

Laravel devuelve esos valores correctamente gracias a los casts `array`; esto no demuestra pérdida de datos ni dependencia de SQL Server. Sí impide tratarlos directamente como arreglos/documentos en consultas nativas, índices anidados o consumidores externos. Encuestas, votos y reportes ya usan estructuras BSON nativas.

Corrección propuesta: normalización acotada e idempotente de estos seis campos, conservar los registros existentes, detectar JSON inválido y retirar los casts que serializan. Probar lectura de registros antiguos y nuevos, escritura, filtros nativos y repetición de la migración. No ejecutar un reinicio de la base como solución.

## Cobertura frente al documento

“Local” indica funcionamiento dentro de este repositorio, no integración aprobada ni cobertura de todos los escenarios posibles.

| Apartado | Implementado localmente | Pendiente o límite |
| --- | --- | --- |
| 6.1 Asociaciones | Perfil, integrantes por matrícula existente, cargos, vigencias y transferencia auditada. | Alta, gestión de estatus, documentos y periodos sucesivos. Resolver R1/R2. Las organizaciones iniciales proceden del seeder. |
| 6.2 Consejo | Organización Consejo con integrantes y cargos propios. | Comisiones, estructura específica y permisos especiales. Actualmente comparte el mismo flujo de asociaciones. |
| 6.3 Roles y vigencias | Presidencia, vicepresidencia, tesorería, secretaría y comunicación con fechas. | Solo presidencia concede permisos de gestión. Faltan delegaciones, agente de caja, responsables y autorización diferenciada por acción con equipo 1. |
| 6.4 Convocatorias y becas | Publicación, requisitos, fechas, solicitudes, documentos privados, estados y dictamen auditado. | El formulario es el del flujo actual; no hay constructor general de formularios. La evidencia de entrega externa depende de 6.5. |
| 6.5 Beneficios | Catálogo y asignación con contrato estable al aprobar una solicitud. | El estado es `pendiente_integracion`: no acredita dinero, emite bonos ni reserva/entrega servicios. Faltan adaptadores, respuesta externa y conciliación con equipos 2/5. |
| 6.6 Eventos | Cupo, costo, reserva, espera, cancelación, boleto QR y asistencia. | NFC con equipo 1; cobros/reembolsos con equipo 2. Eventos con costo conservan pago pendiente por decisión del usuario. El QR de pago pendiente no habilita acceso. |
| 6.7 Mensajería segmentada | Miembros, confirmados/en espera de evento y solicitantes/aprobados de beca; vista previa, cola y reintentos. | Todos, campus, carrera, semestre y grupo requieren padrón/permisos académicos. Aprobados no equivale a beneficiarios con entrega confirmada. Límite local de 5000 destinatarios. |
| 6.8 Bandeja y notificaciones | Leído/no leído, importante, archivo, enlaces internos, preferencias y contador. | La entrega es interna a esta aplicación; falta acordar el contrato común con identidad. Correo/push no se implementaron, pero el apartado no exige esos canales. |
| 6.9 Encuestas | Preguntas de selección única, población congelada de miembros, fechas, respuesta única y resultados agregados al cerrar. | La población actual es la organización; ampliar segmentación requiere el padrón académico. No es un constructor de encuestas de todos los tipos. |
| 6.10 Votaciones | Padrón, periodo, opciones, un usuario/un voto, cierre y resultados. | Uso académico; guarda vínculo usuario-voto y no promete voto secreto. No asigna cargos automáticamente al ganador. Estas dos últimas capacidades no son exigencias expresas del apartado. |
| 6.11 Transparencia | Reportes de cifras reales, borrador/publicación/retiro, CSV e impresión; dashboard dinámico. | Visibilidad para miembros de la organización. Auditoría/analítica global pendiente. No contabiliza dinero entregado como si estuviera integrado; el PDF descargable se obtiene desde la impresión del navegador. |

Evidencia funcional y escenarios: [Entrega 1](Entrega_1_MongoDB_Asociaciones.md), [Entrega 2](Entrega_2_Eventos.md), [Entrega 3](Entrega_3_Becas.md), [Entrega 4](Entrega_4_Comunicacion.md), [Entrega 5](Entrega_5_Encuestas_Votaciones.md) y [Entrega 6](Entrega_6_Transparencia_Dashboard.md). Las rutas están centralizadas en [comunidad.php](../routes/comunidad.php).

## Estado de MongoDB y autenticación

Los modelos de dominio y usuarios utilizan MongoDB; las migraciones activas son de MongoDB. Las conexiones SQL de plantilla que permanecen en `config/database.php` no prueban que el módulo siga usando SQL Server. Las sesiones y bloqueos locales usan archivos; la cola de campañas tiene almacenamiento MongoDB. La revisión no encontró consultas SQL ni joins SQL en el código de aplicación examinado.

Queda R3 para uniformar estructuras nativas. La instalación actual es MongoDB standalone: los cambios de varias colecciones y su auditoría no son una transacción única. Los bloqueos de archivos sirven para el despliegue local; antes de varias instancias se necesitan bloqueos compartidos y una estrategia de consistencia/recuperación. Esto también afecta al corte de reportes, que recoge estados observados durante su generación, no una foto transaccional simultánea.

El PDF recomienda PostgreSQL, mientras que este proyecto eligió MongoDB. Se mantiene la decisión del proyecto y se debe alinear el contrato de identificadores y consistencia con el grupo; no hace falta compartir motor para compartir contratos. El PDF propone un monolito modular y permite servicios internos, interfaces y eventos: no exige microservicios ni una API HTTP por equipo.

La autenticación observada es el scaffolding de **Breeze con sesiones**; `composer.json` incluye Sanctum, pero no Fortify. El PDF sí contempla Fortify. Debe alinearse con el equipo 1, que también debe proveer matrícula, estado académico y permisos contextuales. El registro local no asigna matrícula, aunque el alta de integrantes la requiere. No atribuir al módulo 6 una integración de identidad que aún no existe.

## Definition of Done

| Criterio de la sección 21 | Evidencia y estado |
| --- | --- |
| Criterios de aceptación y autorización | Flujos locales cubiertos; alcance parcial en 6.1–6.3, 6.5–6.7 y hallazgos R1/R2 abiertos. |
| Migraciones/seeders desde base limpia | La suite usa base MongoDB aislada y migraciones; existen pruebas de seeders repetibles. |
| Validación backend y pruebas críticas | Controladores/requests y 115 pruebas aprobadas en la entrega 6. Los casos nuevos de esta revisión muestran huecos de cobertura. |
| Consola y excepciones en flujo principal | La entrega 6 documenta validación de navegador para reportes/dashboard, incluyendo móvil. Esta revisión no repitió una prueba visual exhaustiva de todas las pantallas. |
| Auditoría sensible | Bitácora local en cambios del dominio. R1 omite coordinación de baja; falta publicación al equipo 7 y atomicidad/conciliación. |
| Carga/error/vacío/éxito | Contemplados en componentes de las entregas. No equivale a una certificación de accesibilidad ni a pruebas de todos los dispositivos. |
| Integración con vecinos probada | Pendiente con equipos 1, 2, 3, 5 y 7. Contrato preparado de beneficio no equivale a entrega probada. |
| PR revisado por otro integrante | Sin evidencia en esta revisión; cambios locales todavía sin commit. |
| Documentación y contratos actualizados | Seis entregas, esta revisión y propuesta de contratos. Falta acuerdo y validación conjunta. |
| Demo realista para Sprint Review | Hay cuentas y datos repetibles para demo local. Falta demo integrada. |

## Comprobaciones de esta revisión

- PDF original extraído y contrastado con las secciones citadas.
- Aplicación, MongoDB y trabajador de campañas en ejecución al revisar.
- Tres pruebas temporales, **31 aserciones**, reproducen R1, R2 y R3 en `campus_digital_testing`. Que estas pruebas pasen significa que confirmaron el comportamiento descrito; no que los defectos estén corregidos.
- La comprobación de R3 leyó los documentos sin casts y también por Eloquent: confirmó texto JSON en los seis campos y lectura funcional por el modelo.
- Resultado previo de la suite completa: **115 pruebas aprobadas, 1004 aserciones**, conservado en `storage/logs/validacion-entrega-6.log`. No se presenta como una nueva ejecución en esta revisión ni como garantía de ausencia de fallos.
- Solo se actualizó documentación; las correcciones funcionales permanecen pendientes.

## Orden de trabajo propuesto

| Orden | Entrega concreta | Criterio para considerarla lista |
| --- | --- | --- |
| 1 | Continuidad de administración y normalización MongoDB | Baja de cuenta no deja responsables huérfanos; sucesión/recuperación autorizada; seis campos nativos, migración repetible y pruebas de regresión. |
| 2 | Completar asociaciones/Consejo | Alta y estatus con autoridad definida, documentos privados, periodos, comisiones y delegaciones con permisos por acción. Ningún miembro puede otorgarse privilegios. |
| 3 | Acordar identidad y contratos vecinos | Identificadores, autoridad, estados, errores, reintentos y eventos versionados acordados. Ver [propuesta de contratos](Contratos_Integracion_Modulo_6.md). Puede avanzarse el acuerdo en paralelo al trabajo local. |
| 4 | Beneficios y pagos reales | Aprobar beca genera exactamente un bono/servicio; pago de evento se confirma por el dominio dueño; timeout/reintento/cancelación no duplican efectos. Mantener pago pendiente hasta entonces. |
| 5 | Segmentación académica, NFC, tienda y auditoría | Padrón autorizado del equipo 1, credencial validada, vínculo de negocio y consumo de eventos por el equipo 7; pruebas conjuntas. |
| 6 | Demo integrada y pulido visual | Recorrido con al menos dos organizaciones, varios roles y datos realistas; permisos denegados demostrables, consola limpia y revisión por otro integrante. |

El siguiente incremento recomendado es el primero: estabilizar la base ya construida antes de ampliar las funciones y mejorar la apariencia.
