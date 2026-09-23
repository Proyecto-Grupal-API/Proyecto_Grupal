# Propuesta de roles y permisos — Campus Digital, módulo 6

Fecha: 22 de septiembre de 2026. Documento para compartir y acordar con los equipos 1 y 7 y responsables de los dominios vecinos. **Es una propuesta de autorización; no significa que todos estos permisos estén implementados ni que hayan sido aprobados institucionalmente.**

## Base y alcance

Se revisaron el documento `Campus_Digital_Diseno_Modulos.pdf` (sección 2, páginas 2–3; sección 13, páginas 11–12; contratos y propiedad de datos, página 13), las políticas de acceso actuales y las entregas locales. El catálogo principal cubre Comunidad; al final se enumeran los roles de otros módulos necesarios para completar el catálogo del campus.

El PDF contempla roles globales y contextuales. Coordinador de carrera y Jefe de departamento se incorporan a petición del equipo; sus facultades académicas concretas requieren acuerdo. No se presume que exista una aprobación obligatoria de cada evento o beca por esas autoridades.

Una persona tiene una sola cuenta y puede acumular asignaciones. Ser estudiante es una condición académica; pertenecer a una organización es una membresía; desempeñar un cargo es una asignación con periodo; asistir a un evento o recibir una beca es un estado de participación. Estos conceptos no deben convertirse en cuentas separadas ni en un único campo de “tipo de usuario”.

## Catálogo principal que se solicita acordar

Todas las acciones están limitadas al ámbito asignado y a su vigencia. Consultar un catálogo o información publicada no concede acceso a expedientes privados.

| ID propuesto | Rol | Ámbito | Facultades propuestas | Límites principales |
| --- | --- | --- | --- | --- |
| `estudiante` | Estudiante | Propia cuenta; actividades elegibles | Consultar catálogo, inscribirse, gestionar boletos y solicitudes propias, cargar documentos propios, recibir mensajes y participar cuando esté en el padrón. | No administrar organizaciones ni consultar solicitudes ajenas. Ser beneficiario o asistente no añade permisos administrativos. |
| `integrante` | Integrante de asociación o Consejo | Organización con membresía activa | Consultar información interna permitida, reportes publicados y participar en consultas según padrón. | Es una membresía; no habilita edición ni acceso a documentos privados de otras personas. |
| `presidencia` | Presidencia de asociación o Consejo | Una organización y periodo | Administrar perfil, integrantes y cargos permitidos; delegar funciones; supervisar eventos, campañas, consultas y reportes. Gestionar convocatorias y designar revisores conforme al acuerdo de becas. | No crear autoridades globales, intervenir en otras organizaciones ni aprobar su propia beca. La decisión de becas se propone como permiso explícito, no inherente al título. |
| `vicepresidencia` | Vicepresidencia | Organización y periodo | Consulta de seguimiento y funciones expresamente delegadas; suplencia temporal cuando exista una designación formal registrada. | No sustituye automáticamente a presidencia ni hereda todos sus permisos por ausencia o vencimiento. |
| `secretaria` | Secretaría | Organización y periodo | Gestionar actas y documentos organizativos; mantener información administrativa de integrantes cuando se delegue. | No otorgar cargos, transferir presidencia, dictaminar becas ni consultar documentación de solicitantes por defecto. |
| `tesoreria` | Tesorería | Organización y cuentas autorizadas | Consultar movimientos, conciliaciones y reportes financieros; preparar información financiera para transparencia mediante contratos del equipo 2. | No editar saldos ni ejecutar recargas/retiros sin un permiso operativo adicional de caja. |
| `comunicacion` | Responsable de comunicación | Organización y audiencias autorizadas | Preparar, previsualizar, enviar y dar seguimiento a campañas; gestionar encuestas si recibe esa función. | No enviar a todo el campus por defecto; no descargar expedientes de becas ni leer bandejas privadas ajenas. |
| `responsable_comision` | Responsable de comisión | Comisión, organización, periodo y recursos asignados | Coordinar integrantes, documentos y actividades de su comisión; recibir permisos específicos de eventos o consultas según su encargo. | No administrar toda la organización. Ser integrante de comisión tampoco concede automáticamente acceso a becas. |
| `responsable_evento` | Organizador / responsable de eventos | Evento o conjunto de eventos delegado | Crear o editar eventos dentro de su encargo, publicar/cancelar si tiene autorización, gestionar cupo e inscritos y asignar Staff si se le delega. | No modificar pagos, validar pagos pendientes como liquidados ni administrar becas u otros eventos. |
| `staff_evento` | Staff de acceso | Evento y ventana de acceso | Validar boleto QR/NFC habilitado y registrar asistencia; ver solo los datos necesarios para esa validación. | No editar eventos, emitir cortesías o marcar pagos, ni consultar el padrón completo por defecto. NFC depende del equipo 1. |
| `revisor_becas` | Integrante del comité de becas | Convocatorias o expedientes asignados | Revisar documentos enviados, registrar observaciones y emitir dictamen si dispone de `becas.dictaminar`. | No consultar borradores privados, revisar su propia solicitud ni entregar dinero o servicios directamente. Revisión y decisión pueden separarse. |
| `gestor_organizaciones` | Gestor de organizaciones | Campus o conjunto explícito de organizaciones | Registrar asociaciones/consejos, designar primera presidencia, consultar historial y suspender/reactivar con motivo. Recuperar responsables solo mediante permiso y procedimiento específicos. | No obtiene por este rol acceso a expedientes ni administración interna de cada organización. |
| `coordinador_carrera` | Coordinador de carrera | Carrera(s) y campus asignados | Consultar organizaciones vinculadas a su carrera, actividades y reportes agregados; enviar avisos a su población autorizada; registrar seguimiento académico. | No ve expedientes de beca, wallet, votos individuales ni mensajes privados por su cargo. Puede proponer altas y responsables; aprobarlas requiere otro permiso explícito. |
| `jefe_departamento` | Jefe de departamento | Departamento(s), campus y carreras vinculadas | Consultar seguimiento agregado del departamento, emitir avisos a su población y atender propuestas institucionales dentro de su ámbito. | No hereda todos los permisos de coordinadores, presidencias o comités. Gestionar organizaciones o designar coordinadores exige atribución institucional explícita. |
| `administrador_plataforma` | Administrador de plataforma | Plataforma o campus autorizado | Administrar cuentas, catálogos y configuraciones; asignar roles dentro de su autoridad mediante el servicio de identidad. | No es una autorización universal para leer expedientes o bandejas, alterar votos o modificar transacciones. Las intervenciones excepcionales requieren procedimiento y auditoría. |
| `auditor` | Auditor / consulta institucional | Dominios y ámbito autorizados | Consultar bitácoras y reportes; exportar evidencia autorizada. | Solo lectura. La auditoría no concede acceso indiscriminado a documentos personales ni permite modificar historial o resultados. |

**Consejo Estudiantil es una organización, no una cuenta ni un permiso absoluto.** Su presidencia, secretaría, tesorería, comunicación y comisiones utilizan los mismos roles contextuales, con las facultades especiales que se acuerden explícitamente. Una cuenta puede presidir Sistemas y ser integrante del Consejo sin administrar el Consejo.

## Coordinación y Jefatura: propuesta concreta

La diferencia por defecto es el ámbito: Coordinación consulta y comunica dentro de su carrera; Jefatura lo hace dentro de su departamento y las carreras formalmente vinculadas. Los reportes de supervisión deben ser agregados. Los listados nominales o documentos académicos requieren una finalidad y permiso adicionales.

Propuesta de proceso, pendiente de aprobación: Coordinación propone el alta o cambio de responsable; la autoridad institucional designada decide, y Gestión ejecuta el cambio con referencia a esa decisión. Si el grupo decide que Jefatura será esa autoridad, deberá recibir un permiso de aprobación limitado a su departamento. No se incorpora ese poder solo por el nombre del cargo ni se añade un veto obligatorio a todos los eventos o becas.

La relación campus–departamento–carrera y la adscripción de organizaciones deben proceder de catálogos acordados. **Actualmente no existen suficientes vínculos académicos en el módulo para aplicar ese alcance de forma real.** No se debe deducir pertenencia por nombres, correos o membresías estudiantiles.

## Permisos por acción para acordar con identidad

Los siguientes nombres son identificadores propuestos, no rutas ya implementadas ni un contrato aprobado. Cada verificación incluye recurso, ámbito y vigencia.

| Familia | Acciones que conviene separar |
| --- | --- |
| Registro institucional | `organizaciones.crear`, `organizaciones.suspender`, `organizaciones.reactivar`, `organizaciones.historial.ver`, `presidencias.inicial.asignar`, `presidencias.recuperar` |
| Gobierno interno | `organizacion.perfil.editar`, `miembros.gestionar`, `cargos.asignar`, `cargos.revocar`, `presidencia.transferir`, `delegaciones.gestionar` |
| Documentos y comisiones | `documentos.organizacion.ver`, `documentos.organizacion.gestionar`, `comisiones.gestionar` |
| Eventos | `eventos.crear`, `eventos.editar`, `eventos.publicar`, `eventos.cancelar`, `eventos.inscritos.ver`, `eventos.staff.asignar`, `eventos.acceso.validar` |
| Becas | `becas.convocatorias.gestionar`, `becas.convocatorias.publicar`, `becas.expedientes.revisar`, `becas.dictaminar`, `becas.beneficios.asignar` |
| Comunicación | `campanas.crear`, `campanas.enviar`, `campanas.cancelar`; restricción separada de audiencia por organización, carrera, departamento o campus |
| Participación | `encuestas.gestionar`, `votaciones.gestionar`, `consultas.resultados.ver`; responder/votar depende del padrón, no de un cargo administrativo |
| Supervisión | `reportes.agregados.ver`, `reportes.generar`, `reportes.publicar`, `reportes.exportar`, `auditoria.ver` |
| Identidad institucional | `cuentas.gestionar`, `roles.institucionales.asignar`, `roles.institucionales.revocar`; equipo 1 valida quién puede otorgar cada rol |

La autoatención utiliza titularidad del recurso: cada persona consulta sus boletos, solicitudes, documentos y bandeja. No requiere asignar un rol administrativo para cada pantalla. Ser coordinador o jefe no convierte automáticamente a alguien en estudiante elegible para una beca.

## Asignaciones, autoridades y restricciones comunes

Cada asignación necesita identificador de usuario, rol o permisos, tipo e identificador de ámbito (campus, departamento, carrera, organización, comisión, convocatoria o evento), fecha de inicio/fin, estado, persona que lo otorga y referencia o motivo. Las delegaciones especifican acciones y recursos; caducan al terminar la autoridad que las sustenta.

| Asignación | Autoridad propuesta, por acordar |
| --- | --- |
| Estudiante y adscripción académica | Fuente institucional validada por equipo 1. No editable libremente desde el perfil. |
| Coordinación, Jefatura, Administración, Auditoría y Gestión | Autoridad institucional mediante identidad; nadie se otorga su propio cargo. |
| Primera presidencia | Gestor autorizado, dentro de su ámbito. |
| Renovación, transferencia o recuperación de presidencia | Procedimiento del dominio con autoridad explícita, vigencias, motivo e historial; suplencia no automática. |
| Cargos internos y comisiones | Presidencia con autorización para ese cargo y organización. |
| Responsable de evento y Staff | Presidencia o responsable autorizado; Staff no puede crear más Staff por defecto. |
| Comité/revisores de becas | Autoridad de la convocatoria, con expedientes/convocatorias definidos y control de conflicto de interés. |
| Roles de caja, tienda y servicios | Validación del dominio propietario junto con identidad; un cargo del módulo 6 no basta para concederlos. |

Reglas transversales propuestas: denegar acciones no concedidas; evaluar permisos en el servidor en cada operación; impedir escalamiento de privilegios; conservar autor y motivo; revocar acceso al vencer o retirar la asignación; limitar exportaciones al mismo ámbito que la consulta. Una suspensión conserva historial y bloquea operación según las reglas del dominio. Combinar roles no elimina restricciones como la prohibición de dictaminar una solicitud propia.

Los pagos de eventos permanecen pendientes mientras no exista confirmación del equipo 2. Ni presidencia, ni Jefatura, ni Staff deben simular el cobro. El permiso de becas solicita beneficios por contrato; no escribe saldos ni asigna físicamente lockers por su cuenta.

## Roles de los demás módulos para el catálogo global

El PDF enumera también estos roles. Deben coordinarlos sus equipos propietarios; no todos requieren una vista nueva en Comunidad.

| Rol | Dominio / equipo | Alcance y permiso principal |
| --- | --- | --- |
| Propietario de negocio | Comercio, 3 | Administrar su negocio, personal, catálogo y configuración comercial permitida. |
| Gerente de negocio | Comercio, 3 | Operar pedidos, precios, promociones y reportes delegados. |
| Cajero de negocio | Comercio/Wallet, 3 y 2 | Cobros, tickets y devoluciones permitidas en negocio/caja asignados. |
| Responsable de inventario | Inventario, 4 | Existencias, almacenes, conteos y ajustes autorizados. |
| Comprador / abastecimiento | Inventario, 4 | Proveedores, órdenes de compra, recepción y devoluciones autorizadas. |
| Agente de recarga/retiro | Wallet, 2 | Operaciones autorizadas de efectivo, con caja, turno, límites y arqueo. Es distinto de Staff de entradas y de tesorería. |
| Responsable de lockers | Servicios, 5 | Disponibilidad, asignación, renovación e incidencias de lockers bajo su cargo. |
| Bibliotecario | Servicios, 5 | Préstamos, devoluciones, reservas y sanciones conforme al servicio. |
| Responsable de instalaciones | Servicios, 5 | Calendarios, reservas, capacidad y acceso de espacios asignados. |
| Operador de equipos/impresiones | Servicios, 5; especialización propuesta | Entrega/devolución de equipos o atención de órdenes del servicio asignado. No tiene que recibir todos los permisos de biblioteca o instalaciones. |
| Agente de soporte | Servicios, 5 | Tickets e incidencias asignados; acceso acotado a sus evidencias. |
| Administrador de recompensas | Recompensas, 7 | Reglas, campañas, canjes y controles del programa según su autoridad. |
| Proveedor externo | Abastecimiento, 4; opcional en PDF | Solo órdenes y documentos de su proveedor, si se habilita el portal. |

Administrador de plataforma y Auditor, incluidos en el catálogo principal, se comparten con identidad y gobierno (equipos 1 y 7). Una tienda de asociación utiliza roles de negocio además de los cargos de organización; no se presume que presidencia pueda operar automáticamente toda caja o inventario.

No se añade un rol genérico “docente” con poderes administrativos sin un caso de uso definido. Si un docente organiza un evento, integra un comité o coordina una carrera, recibe esa asignación concreta. Invitados, ponentes y proveedores sin portal no necesitan una cuenta privilegiada por aparecer en un registro.

## Diferencia frente a la implementación actual

- Funciona localmente: estudiante/autoatención, membresías, presidencia contextual, Staff por evento y Gestión de organizaciones con permiso separado. Hay una cuenta de demostración de cada perfil principal; Andrea es actualmente presidencia de Robótica.
- Vicepresidencia, tesorería, secretaría y comunicación se registran como cargos, pero todavía no habilitan permisos administrativos diferenciados.
- Presidencia concentra hoy buena parte de la gestión, incluyendo revisión de becas. Separar comité/revisión/dictamen y delegaciones requiere cambios; la matriz anterior es el objetivo propuesto.
- Coordinación y Jefatura no están implementadas, ni existe aún su vinculación académica, supervisión institucional o audiencia por carrera/departamento.
- Comisiones, documentos organizativos y responsable de evento delegado requieren desarrollo adicional. El responsable que administra eventos hoy es presidencia; Staff ya tiene autorización independiente para validar acceso.
- Administración institucional, auditoría transversal, caja, tiendas y servicios requieren integración con los respectivos equipos. No se crean cuentas ni se conceden permisos con este documento.

## Decisiones para cerrar el acuerdo

1. Confirmar quién puede otorgar Coordinación, Jefatura y Gestión, y quién autoriza altas, suspensiones y recuperaciones de presidencia.
2. Definir catálogo y relaciones de campus/departamento/carrera/organización, incluyendo organizaciones que abarcan varias carreras.
3. Confirmar quién dictamina becas y si requiere varias aprobaciones; no asumir un comité de una sola persona o acceso de toda Jefatura a expedientes.
4. Definir qué acciones puede delegar presidencia y sus límites; separar publicación, consulta, dictamen, exportación y validación de acceso.
5. Acordar audiencias institucionales y privacidad de reportes. El envío global requiere concesión explícita.
6. Publicar un contrato de identidad y autorización compartido. El módulo 6 aporta contexto, cargos y periodos de organización; el equipo 1 controla la autorización institucional acordada.
