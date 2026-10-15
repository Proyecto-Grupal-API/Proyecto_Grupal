# Respuesta del Equipo 6 al Equipo 2

Integración de eventos, becas y consulta de caja

## 1. Información general

| Campo | Valor |
| --- | --- |
| Proyecto | Campus Digital |
| Emisor | Equipo 6 — Comunidad |
| Destinatario | Equipo 2 — Finanzas, wallet, bonos y caja |
| Documento atendido | Solicitud_Equipo2_a_Equipo6_Formato.docx, elaborada el 12 de octubre de 2026 |
| Referencia relacionada | REQ-M6-E2-001, solicitud de Equipo 6 a Equipo 2 del 23 de septiembre de 2026 |
| Fecha de esta respuesta | 12 de octubre de 2026 · America/Mexico_City |
| Estado | Propuesta de alcance y acuerdos. Responsables, contrato final y fecha de entrega por confirmar. |
| Prioridad propuesta | P1: pagos de eventos, beneficios y recuperación. P2: consulta de caja. |

Proponemos atender los seis requerimientos E6 01–E6 06. Comunidad conserva eventos, inscripciones, cupo, convocatorias, dictámenes y cargos; Finanzas conserva fondos, ledger, cobros, emisión financiera, cancelaciones/reembolsos y caja. Las decisiones de fondos y autoridad se acuerdan con los responsables competentes; esta respuesta no las concede.

La integración será en una aplicación Laravel modular: MongoDB para Comunidad y SQL Server para Finanzas. El intercambio entre módulos será por HTTP/API. La sesión común puede identificar al actor, pero no autoriza leer tablas o colecciones ajenas.

## 2. Respuesta a los requerimientos

En la copia local de integración 1–5–6 ya existen asignaciones de beca con clave estable, importe en centavos y vigencia de fin exclusivo; dictamen y entrega están separados. Lockers e impresiones ya tienen entrega por API a E5 con recibo y reintentos. Este avance es local y aún no está publicado en Testings; no representa entrega de dinero o bonos de E2.

Las inscripciones de eventos mantienen el pago pendiente y el acceso exige cupo confirmado y estado pagado o exento. La palabra confirmada en la reserva no acredita cobro. El resumen de organizaciones existente no informa cuentas/caja ni concede permisos financieros.

| Código | Respuesta de Equipo 6 |
| --- | --- |
| E6 01 | Prepararemos un adaptador independiente para las APIs financieras acordadas: pago, beneficio, consulta por clave, cancelación, reembolso y caja. Mantendrá origen, claves estables y referencias externas. La integración financiera está pendiente. |
| E6 02 | Añadiremos operaciones recuperables con contenido versionado, intentos, resultado desconocido, evidencia y consulta por clave antes de reenviar. Cada reinscripción tendrá una generación de cobro nueva y conservará el historial anterior. |
| E6 03 | Aportaremos contexto autorizado de evento, inscripción, organización, pagador y administrador. Identidad y precio se obtendrán del servidor; el contrato de actor/permisos debe acordarse con E1 y E2. |
| E6 04 | Separaremos vigencia/cupo de estado financiero. Solo se solicitará cobro para un lugar autorizado; espera, vencimiento, cancelación y pago tardío seguirán la política acordada. |
| E6 05 | Versionaremos el beneficio financiero con tipo de entrega, restricciones estructuradas y referencia de financiamiento validable por E2. reglas conservará su carácter informativo; se mantendrá vigencia_fin_exclusiva y no se enviarán expedientes. |
| E6 06 | Conservaremos dictamen y seguimiento de entrega por separado. La revocación dependerá de la emisión/uso confirmados por E2. Para caja añadiremos el vínculo de organización y el contexto autorizado; saldos y operación de efectivo seguirán en Finanzas. |

## 3. Acuerdos que solicitamos confirmar

Solicitamos que E2 responda numerando A1–A8. Cuando una decisión dependa de E1 o de autoridad institucional, indicar quién debe resolverla. Los valores pendientes no se sustituirán por permisos, fondos o políticas inventados.

| Punto | Confirmación requerida |
| --- | --- |
| A1 · Financiamiento de becas | ¿Qué fuentes de fondos se admiten, qué referencia verificable proporciona E2 y quién puede autorizar su uso? Indicar el resultado ante fondos insuficientes. E6 conservará la referencia; E2 validará y ejecutará el efecto financiero. |
| A2 · Organización receptora | ¿Cómo se vincula organizacion_id con asociación/cuenta financiera y receptor del cobro? Precisar el caso del Consejo Estudiantil. Confirmar evidencia de la recepción/contrapartida, sin asumir que todas las organizaciones tienen caja. |
| A3 · Tipos y restricciones | Confirmar dinero digital frente a bono, tipos admitidos, catálogo e IDs de negocio/categoría, combinación de restricciones, uso parcial y pagos mixtos cuando aplique. El texto libre de requisitos no será política. Un bono no se convertirá automáticamente en saldo retirable. |
| A4 · Reserva y pago tardío | Proponemos plazo configurable por evento y vencimiento de cobro ligado a una reserva válida. Confirmar duración, límites con cierre/inicio del evento y cuándo promover la espera. Proponemos que un pago tardío sin lugar válido no habilite boleto: se conserva y concilia, con reembolso si corresponde. |
| A5 · Cancelación y devolución | Confirmar cuándo una cancelación queda firme, cuándo se libera cupo y quién puede solicitar/autorizar reembolso. Resolver pago concurrente, cancelación del evento, límites y devolución parcial/total. Cada devolución referirá al cobro original y tendrá clave propia. |
| A6 · Identidad y autoridad | Con E1, definir actor, prueba/contexto autorizado y vigencia; con E6, cargo/organización y finalidad; con E2, permiso financiero por acción. Diferenciar nuevas operaciones de la conciliación de una operación ya autorizada cuando el cargo venza. El nombre del cargo y los roles business no conceden permisos financieros por sí solos. |
| A7 · Contrato de APIs | Entregar contrato versionado con rutas, autenticación, scopes si aplican, estados, errores, límites, ejemplos y consulta por clave. Precisar qué operaciones están implementadas y cuáles dependen de autorización pendiente; no basta una ruta declarada. |
| A8 · Revocación de beneficios | Confirmar tratamiento de beneficio no emitido, emitido sin uso y consumido parcial/totalmente, incluyendo dinero digital ya acreditado. E6 registra decisión/motivo e invoca la API; E2 decide y registra cancelación/reverso permitido, sin borrar historial. |

Los cargos internos y sus asignaciones siguen siendo datos de Comunidad; E1 conserva la identidad y su autorización institucional. Solicitamos coordinar el acuerdo de A6 con E1 sin duplicar cargos ni restablecer la propuesta de roles rechazada.

## 4. Contrato mínimo y primera entrega

Para P1 proponemos un worker de E6 que envíe las operaciones y consulte su resultado por clave. El callback autenticado puede añadirse después; no es obligatorio para la primera entrega. El listado de pendientes puede ser interno.

| Dueño | Operación requerida y resultado mínimo |
| --- | --- |
| E2 · P1 | Iniciar pago de evento y consultar por clave: referencia, estado real, importe/moneda, receptor y vencimiento. La respuesta debe distinguir pendiente, rechazo y confirmación. |
| E2 · P1 | Cancelar un pago pendiente o solicitar/consultar reembolso: resultado trazable, conflicto con pago liquidado y vínculo al cobro original. |
| E2 · P1 | Emitir dinero/bono de beca y consultar por clave: beneficiario, origen, tipo, restricciones, financiamiento, vigencia, referencia financiera y estado confirmado/rechazado/pendiente. |
| E2 · P1 | Consultar y cancelar/revertir beneficio según emisión/uso: resultado y evidencia, o rechazo explícito cuando no proceda. |
| E6 · P1 | Contexto de inscripción/evento y beneficio aprobado: identidad, organización, lugar autorizado, importe entero, generación/versión y vigencia; sin datos personales innecesarios. |
| E6 · P1/P2 | Contexto mínimo de organización: ID, tipo/estado, versión y vínculo financiero aprobado. El contexto no otorga permiso por sí mismo. |
| E2 · P2 | Consulta autorizada de caja/turno/resumen, con aislamiento por organización y paginación cuando corresponda. |

Las rutas del Word recibido y de REQ-M6-E2-001 siguen siendo propuestas; solicitamos el contrato final del equipo propietario. E6 adaptará su cliente a ese contrato. Los datos personales de solicitudes, documentos y motivaciones permanecen en Comunidad.

Convenciones propuestas: JSON UTF-8; cuentas referidas por User._id canónico de E1; otros IDs como cadenas opacas con mapeo explícito; importes enteros en centavos/MXN; fechas ISO 8601 con zona; fin de beneficio exclusivo. La matrícula y los nombres no sustituyen los IDs.

Misma clave y contenido devuelve la misma operación; contenido cambiado produce conflicto. Tras timeout el resultado es desconocido y se consulta antes de reenviar. Una respuesta de una generación anterior se conserva, pero no habilita una inscripción nueva. No se sobrescribe una beca enviada bajo la misma clave con otro importe/restricciones.

La autenticación del canal no sustituye el permiso del actor. Deben acordarse la autoridad del worker y la resolución de resultados tardíos/fuera de orden. El importe, los roles y la confirmación financiera no provienen del navegador; el acceso se valida en backend.

## 5. Entregables, verificación y coordinación

- E6: adaptador financiero, contextos autorizados, snapshots/versiones, generaciones de inscripción, seguimiento y recuperación, reglas de cupo/pago, estados de entrega y actualización de interfaz. Preservará la entrega existente a E5.
- E2: contrato versionado y servicios financieros sobre su dominio, consultas por clave, evidencia de fondos/recepción, cancelación/reembolso/beneficios y sus validaciones de autoridad.
- Conjunto: ejemplos válidos/rechazados, datos de prueba, salida de pruebas y rama/commit reproducible para integrar en Testings.

Criterios de aceptación conjunta:

- Repetición del mismo cobro/beneficio produce una única operación; contenido distinto bajo la misma clave devuelve conflicto.
- Respuesta perdida o interrupción del worker permite recuperar el resultado sin duplicar efectos.
- Fondos insuficientes, actor no autorizado o cargo vencido no permiten una nueva operación ni habilitan acceso.
- Inscripción en espera/vencida/cancelada no genera un cobro nuevo sin lugar autorizado; se prueba el pago tardío.
- Cancelación concurrente y devolución conservan resultado conciliable y referencia original.
- Reinscripción usa una generación y clave nuevas; el resultado anterior no la confirma.
- Aprobación de beca no se muestra como entrega; la revocación respeta emisión y consumo.
- Vigencia de fin exclusivo y restricciones estructuradas se aplican; se mantienen separación entre organizaciones y regresión de E5/Staff.

Orden propuesto: cerrar A1–A8 y contrato mínimo; construir recuperación/contextos; conectar beneficios financieros; conectar eventos y devoluciones; incorporar consulta de caja P2. La preparación puede avanzar en paralelo a la integración de Inventarios. Los movimientos reales se habilitan al resolver fondos, autoridad y APIs.

| Coordinación | Estado |
| --- | --- |
| Responsable E6 / responsable E2 | Por confirmar entre equipos. |
| Intervención E1 / autoridad institucional | Por confirmar para A1/A6 según atribuciones. |
| Estimación y fecha de entrega | Se definirán después de confirmar contrato, políticas y dependencias; la fecha de este documento es de elaboración. |
| Conformidad de alcance y políticas | Pendiente de respuesta; este documento no acredita aprobación ni firma de los equipos. |

Nota para implementación y agentes de IA: separar hechos actuales, propuestas y acuerdos confirmados. No presentar endpoints, callbacks, firmas, pagos, roles o pruebas como implementados/aprobados sin su evidencia. El trabajo de E6 indicado aquí es alcance propuesto; se proporcionará rama/commit y salida de verificación al entregarlo.
