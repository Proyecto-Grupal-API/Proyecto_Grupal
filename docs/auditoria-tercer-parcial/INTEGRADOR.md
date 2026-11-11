# Documento integrador · Equipo 6 · Comunidad

Revisión: **10 de noviembre de 2026**. Rama: **MERGE_COMPLETO**. Base de código: `2651e5dcee021a96a8931ea013c5921d4f883aeb` más el cambio de versionado de API identificado en el manifiesto. Este expediente es una autoevaluación técnica; la calificación y el dictamen corresponden al docente.

## Identificación y criterio de datos

Repositorio: https://github.com/Proyecto-Grupal-API/Proyecto_Grupal. Entorno integrado: http://127.0.0.1:8086. Carpeta canónica: `/home/daniel/campus_digital_integracion_e1`. La carpeta original se conserva.

El solicitante corrigió el formato: el proyecto utiliza **MongoDB y SQL Server**, no PostgreSQL. Comunidad persiste en MongoDB y Finanzas en SQL Server. Los puntos de persistencia se explican mediante BSON, índices y validaciones de referencias. No se afirma disponer de claves foráneas SQL en MongoDB. El criterio literal de tablas SQL en singular se marca no aplicable; las colecciones existentes usan su convención plural. Esta equivalencia debe quedar visible al docente.

Integrantes recogidos de la guía de presentación previa: Abraham Daniel Beltrán González, Jacquelin, Diego Moran García, Osvaldo Rodriguez Garcia y Yoana. Los apellidos faltantes y las responsabilidades individuales deben confirmarse. La guía de exposición no prueba autoría de código. Responsable de la revisión y coordinación: Abraham Daniel Beltrán González, con asistencia de Codex. Fecha de entrega y firma del auditor: pendientes de completar por sus responsables.

## Alcance y requisitos

Fuente funcional: `docs/Campus_Digital_Diseno_Modulos.pdf`, sección 13, páginas 11–12. Once capacidades; 42 puntos de esfuerzo relativo del documento original. Las historias siguientes son una organización de trazabilidad del alcance existente, no una afirmación de que se registraron históricamente en un tablero Scrum.

| Historia / punto | Criterios de aceptación | Implementación y pruebas | Límite actual |
|---|---|---|---|
| HU-01 / 6.1 Asociaciones | Alta, estatus, perfil, integrantes, mesa, documentos y sucesión conservan historial y exigen autoridad vigente. | OrganizacionController; GestionOrganizacionesTest; DocumentosOrganizacionTest; PeriodosOrganizacionTest | Directorio de E1 conserva modo heredado y necesita cierre operativo. |
| HU-02 / 6.2 Consejo | Cargos, periodos y comisiones del Consejo se administran sin conceder autoridad global a sus integrantes. | ComisionConsejoController; ComisionesConsejoTest | La autoridad de emisión financiera se verifica además en E1/E2. |
| HU-03 / 6.3 Roles y delegaciones | Inicio/fin, revocación y pertenencia limitan el permiso; una delegación no sobrevive a su fuente. | PermisosComunidad; DelegacionesComunidadTest; VigenciasPeriodosTest | Las concesiones productivas siguen su política institucional. |
| HU-04 / 6.4 Becas | Convocatoria con fechas/requisitos; solicitud y archivos privados; comité/recomendación y dictamen auditados. | BecaController; BecasTest; ComiteBecasTest; RevisionArchivosComunidadTest | La publicación no equivale a entregar dinero o emitir un bono. |
| HU-05 / 6.5 Beneficios | Presidencia confirma dictamen, Tesorería avala presupuesto exacto, emisor autorizado ejecuta; reintento consulta la misma referencia. | BecaFinanceContract; EntregaBeneficiosServicios; contratos BECA; pruebas E2/E5 documentadas | La presente auditoría no habilita fondos, permisos ni todos los pilotos financieros. |
| HU-06 / 6.6 Eventos | Cupo, lista de espera, vencimiento de 15 minutos para pagos pendientes, QR/NFC y cancelación preservan integridad. | InscripcionesEvento; EventosTest; IntentosReservasEventosTest; StaffNfcLocalHttpTest | La evidencia actual del navegador cubre un evento gratuito; los cobros tienen pruebas específicas y activación separada. |
| HU-07 / 6.7 Segmentación | Vista previa firmada, audiencia congelada, envío recuperable, deduplicación y preferencias. | AudienciaCampana; ComunicacionTest | No implementa segmentos de todos/campus/carrera/semestre/grupo; requieren datos y alcance acordado con E1. |
| HU-08 / 6.8 Bandeja | Lectura, destacado, archivo, preferencias y enlaces a recursos autorizados son privados por destinatario. | BandejaController; ComunicacionTest; AvisosServiciosTest | Correo/push no forman parte del canal interno acreditado. |
| HU-09 / 6.9 Encuestas | Población objetivo, periodo, opciones, respuesta y resultados respetan organización y padrón. | ConsultaController; ConsultasTest | No se certifica un proceso electoral institucional. |
| HU-10 / 6.10 Votaciones | Un usuario/un voto por proceso; cierre y auditoría, sin exponer padrones en respuestas generales. | ConsultaController; ConsultasTest | Uso académico según el documento. |
| HU-11 / 6.11 Transparencia | Cifras agregadas, filtros por periodo, revisión/publicación y exportación sin expedientes personales. | MetricasComunidad; ReportesTest; reporte-exportado.csv | Saldo de caja no se inventa si Finanzas no lo proporciona. |

Requisitos no funcionales: autenticación y sesiones de E1; permisos evaluados en backend; aislamiento entre organizaciones; CSRF en escrituras del portal; validación de identificadores y montos; claves de idempotencia; persistencia de auditoría; protección de archivos; errores JSON controlados; interfaz con carga/error/éxito y vista móvil; documentación de contratos y pruebas sobre bases aisladas.

## Arquitectura y límites

Vue/Inertia utiliza Axios hacia `/api/v1/comunidad/portal`. Las 114 rutas del portal reutilizan controladores, middleware y políticas de las rutas anteriores. La sesión y CSRF continúan siendo necesarios: API independiente de Vue no significa API anónima.

Las APIs de servicio conservan OAuth client_credentials y scopes propios. El segmento `/portal` evita colisionar con `/api/v1/comunidad/notificaciones`, que recibe avisos de otros módulos. E1 autentica al humano y controla sesiones/2FA; Comunidad aporta membresía/cargo/organización/evento; E2 ejecuta y registra movimientos financieros; E5 administra servicios; E7 recibe auditoría.

La autenticación integrada de E1 puede reutilizarse internamente según los acuerdos. Las lecturas de directorio heredadas de `User` deben distinguirse de esa autenticación compartida: todavía existen y no permiten declarar que todo acceso entre dominios sea exclusivamente HTTP.

## Diccionario lógico de persistencia

| Colección / modelo | Datos principales | Relación y regla |
|---|---|---|
| organizaciones / Organizacion | slug, nombre, tipo, estado, contactos, versión | Entidad propietaria E6; no es una wallet. |
| miembros_organizacion / MiembroOrganizacion | organizacion_id, usuario_id, estado, fechas | Usuario canónico User._id de E1; no crear identidad paralela. |
| asignaciones_roles_organizacion / RolOrganizacion | cargo, usuario, organización, inicio/fin, retirada | Cargo vigente y membresía; sin elevar a admin global. |
| PeriodoOrganizacion / ComisionConsejo / DelegacionOrganizacion | periodos, integrantes, capacidades, origen y vigencia | Asignaciones acotadas, sin solapar ni revivir autoridad retirada. |
| DocumentoOrganizacion / versiones | metadatos, almacenamiento privado, versión y huella | Acceso autorizado, historial y limpieza ante errores. |
| eventos / Evento | organización, fechas UTC, costo, capacidad, espera y estado | Solo publicar con fechas válidas; costo se normaliza a centavos al reservar. |
| registros_eventos / RegistroEvento | evento, usuario, generación, estado, pago, plazo, QR y asistencia | Índice único evento/usuario; QR solo con reserva válida y pago/exención. |
| SolicitudReservaEvento / PagoReservaEvento | clave, acción, generación y comprobante | Repetir operación no reserva/cobra nuevamente. |
| convocatorias_becas / ConvocatoriaBeca | fechas, requisitos, tipo, centavos, cupos, estado | Proyección pública separada de expedientes. |
| solicitudes_becas / SolicitudBeca | folio, usuario, convocatoria, documentos y dictamen | Un expediente por referencia; archivos privados. |
| AsignacionBeneficio | contrato, estado, aval y evidencia externa | Aprobado localmente no equivale a emitido/entregado. |
| campañas / Campana | contenido, audiencia, revisión y cursor | Vista previa firmada y envío deduplicado; no escribir campos académicos inventados. |
| Mensaje / PreferenciaComunicacion | usuario, campaña, leído, destacado, archivo y preferencias | Acceso solo del destinatario. |
| encuestas / Encuesta; Eleccion; respuestas / Voto | padrón, periodo, opciones, revisión y resultados | Un voto/respuesta por proceso y usuario según índices. |
| reportes_transparencia / ReporteTransparencia | periodo, corte, métricas, versión, publicación | Snapshot revisable; no exponer datos personales. |
| AuditoriaComunidad / CommunityPlatformDelivery | acción, entidad, actor, fecha, estado de entrega | Trazabilidad de cambios y salida a E7 recuperable. |

Los nombres de clase de la tabla se incluyen cuando no se reproduce un nombre de colección: la fuente canónica es el atributo table del modelo y la migración correspondiente, no un nombre inferido del título. Montos de beneficios y comprobantes usan centavos enteros; fechas se guardan como BSON DateTime; arreglos y documentos se mantienen nativos donde las migraciones los normalizan. La instantánea `mongo-esquema.json` muestra índices observados.

## Evidencia y verificación

- 303 Feature/API del alcance declarado: **0 fallos, 0 errores, 3,666 aserciones**, 847.53 segundos. Emitieron 303 advertencias por `.env` ausente en el candidato protegido. No se suprimieron esas advertencias.
- 428 unitarias: **428 aprobadas, 942 aserciones**. Es el alcance unitario disponible del repositorio, no 428 pruebas exclusivas de Comunidad.
- Frontend: **8 pruebas aprobadas**; compilación Vite correcta.
- Navegador Edge real: once capturas, cero errores JavaScript, vista móvil 390 px sin desbordamiento horizontal observado. El video contiene fotogramas reales de CDP, sin audio, muestreados a 5 fps; no conserva exactamente la duración original de cada acción.
- Cliente Python sin Vue: **401, 200, 422, 403 y 404** conforme a lo esperado. El recorrido de evento añadió 201 y verificó edición/publicación/inscripción/cancelación con 200.
- NFC: la suite ejecutó un ensayo OAuth/HTTP contra E1 y comprobó revocación persistida en MongoDB.
- Las pruebas financieras anteriores continúan fechadas en sus contratos; no se declara que esta auditoría haya repetido todas las operaciones SQL Server.

Las bases de pruebas y las bases de la demo son temporales y separadas del alterno. Los contenedores de prueba no se convierten en un segundo entregable: la única copia integrada del producto sigue siendo `/home/daniel/campus_digital_integracion_e1`.

## Decisiones y límites del informe

La corrección de PostgreSQL no migra el proyecto ni cambia sus acuerdos de almacenamiento. La autoevaluación usa Cumple=1, Parcial=0.5, No cumple=0 y excluye No aplica dentro de cada área; mantiene las ponderaciones originales. Es una ayuda para preparar la entrega, no una nota oficial ni porcentaje de funcionalidad del sistema.

La matriz registra ocho parciales: recorrido integral con todas las activaciones, alcance completo de segmentación, esquemas exhaustivos de API, directorio sin acceso heredado, documentación API completa, responsabilidades/nombres del equipo, participación verificable de varios integrantes y escaneo exhaustivo de secretos del historial. No deben sustituirse por afirmaciones de 100%.
