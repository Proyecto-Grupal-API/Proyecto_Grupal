# Contratos de integración — propuesta del equipo 6

Fecha: 21 de septiembre de 2026. **Propuesta para revisión grupal; no son APIs implementadas ni acuerdos confirmados.**

Base: secciones 13 y 15 del [documento del proyecto](Campus_Digital_Diseno_Modulos.pdf). El módulo puede integrarse mediante servicios/interfaces y eventos internos del monolito. Elegir HTTP, colas u otro transporte después de acordar los contratos. No acceder directamente a colecciones ajenas para modificar reglas de otro dominio.

## Contrato que ya existe localmente: beneficio aprobado

[BeneficiosBeca::preparar](../app/Services/BeneficiosBeca.php) crea una única asignación por solicitud, con estado `pendiente_integracion`. El objeto `contrato` contiene:

| Campo actual | Significado |
| --- | --- |
| `version` | Versión 1 del contenido local. |
| `clave_idempotencia` | `beca:{solicitud_id}`, estable al reintentar. |
| `equipo_destino` | Dueño de ejecutar el beneficio, según catálogo. |
| `beneficiario_id`, `organizacion_id`, `convocatoria_id`, `solicitud_id` | Referencias de origen como texto. |
| `folio` | Identificador legible de la solicitud. |
| `tipo`, `cantidad` | Tipo y unidades del apoyo. |
| `monto_centavos`, `moneda` | Entero y MXN; no saldo disponible ni comprobante de entrega. |
| `vigencia_inicio`, `vigencia_fin_exclusiva` | Fechas ISO 8601; fin exclusivo. |
| `reglas` | Texto de requisitos de la convocatoria. No es un lenguaje de reglas ejecutables. |

No existe todavía envío, confirmación externa ni reconciliación. No enviar documentos de solicitud ni motivaciones personales junto con el contrato. Si el receptor necesita restricciones estructuradas para bonos o servicios, acordar un esquema y versionar la adaptación: el texto `reglas` actual no garantiza compatibilidad semántica.

## Operaciones a acordar

| Dueño | Necesidad del equipo 6 | Entrada mínima propuesta | Respuesta/evidencia requerida |
| --- | --- | --- | --- |
| Equipo 1 | Identidad y matrícula | Identificador estable de usuario o matrícula, actor autorizado y finalidad. | Usuario, estado y atributos académicos estrictamente necesarios. Acordar cambios de matrícula y baja. |
| Equipos 1/6 | Permisos contextuales | Actor, acción, organización, recurso y momento. Equipo 6 aporta el cargo/periodo del dominio. | Permitir/denegar y vigencia; autoridad que puede crear organizaciones, delegar o recuperar presidencia. No confiar en roles enviados por el navegador. |
| Equipo 1 | NFC para acceso a evento | Credencial, actor lector autorizado y contexto del evento. | Usuario identificado y estado de credencial. Equipo 6 verifica reserva, pago y asistencia. Bloqueo/reemplazo debe respetarse. |
| Equipo 1 | Población académica | Filtros de campus/carrera/semestre/grupo, organización y emisor autorizado. | Identificadores elegibles y versión/corte del padrón; límites y paginación acordados. La resolución debe negar audiencias fuera del alcance del emisor. |
| Equipo 2 | Bono o dinero de beca | Adaptación del contrato existente, identidad de actor y referencia de origen. | Identificador de operación, estado, monto/moneda y fecha; posibilidad de consultar por clave de idempotencia. |
| Equipo 5 | Locker u otro servicio becado | Beneficiario, convocatoria/solicitud, tipo, cantidad, periodo y clave idempotente. | Referencia de asignación y estado real; sin disponibilidad devuelve rechazo explícito. Acordar selección del recurso y cancelación. |
| Equipo 2 | Pago de evento | Registro/evento, pagador, organización receptora, monto entero, moneda y clave estable por operación. | Referencia de pago, estado confirmado por el servidor dueño y consulta por clave. Definir expiración, cancelación y reembolso. |
| Equipo 2 | Caja de organización | Organización, operador con permiso de caja y referencia al flujo del equipo 2. | Caja/turno y operación auditada. Equipo 6 muestra o enlaza; no calcula ledger ni edita saldos. |
| Equipo 3 | Tienda oficial | Organización, tipo asociación/Consejo, responsables autorizados y clave de solicitud. | Identificador de negocio, estado de autorización y enlace seguro. Crear negocio no equivale a publicarlo. |
| Equipo 7 | Auditoría y resultados | Evento versionado: ID único, origen, acción, entidad, organización, actor cuando corresponda, instante y correlación. Métricas sin expedientes ni votos individuales. | Confirmación de recepción, deduplicación y consulta/reintento. Acordar catálogo de eventos y acceso a información sensible. |

## Convenciones propuestas

- Identificadores como cadenas opacas entre dominios. No exigir al equipo vecino ObjectId, aunque el módulo local lo use. Las rutas externas requieren su propio mapeo/validación; las rutas actuales `/api/*` de la interfaz usan sesión y CSRF.
- Importes enteros en centavos con moneda explícita; acordar conversiones si el otro dominio usa decimal fijo. Fechas de intercambio con zona explícita, preferentemente UTC, y vigencias con fin exclusivo.
- Toda operación externa sensible tiene origen, referencia, actor/contexto validado, versión y clave de idempotencia. La autenticación del canal no sustituye el permiso para actuar por esa organización.
- Repetir una clave con el mismo contenido devuelve la misma operación; con contenido diferente devuelve conflicto sin nuevos efectos. Distinguir la clave de asignación de una eventual clave de cancelación o reverso.
- Respuesta de negocio separada de fallos técnicos: permiso denegado, usuario inactivo, periodo inválido, sin cupo/fondos, conflicto y fallo temporal. El contrato final debe definir cuáles son reintentables.
- Un timeout significa resultado desconocido: consultar por clave antes de repetir. Nunca marcar como entregado/pagado por el clic del usuario, una redirección del navegador o el mero envío de la solicitud.
- Las notificaciones de resultado deben autenticarse, correlacionarse y tolerar duplicados y llegada fuera de orden. Acordar cuándo prevalece una consulta al dueño y cómo se resuelve una cancelación concurrente.
- Mantener referencia externa y evidencia mínima del resultado. La aprobación de una beca es un estado distinto de su entrega; no sobrescribir una con la otra.

## Estados y consistencia por definir

Para beneficios, conservar la aprobación local y añadir seguimiento externo separado. Propuesta conceptual: pendiente de envío, enviado/resultado desconocido, confirmado, rechazado y cancelación/reverso cuando el receptor lo soporte. Los nombres definitivos y transiciones deben acordarse; hoy solo existe `pendiente_integracion` en la asignación.

Para eventos, conservar el comportamiento actual de reservas con pago pendiente hasta conectar el equipo 2. Antes de habilitar cobros acordar cuánto dura una reserva pendiente, qué sucede al perder cupo o cancelar durante el cobro, y cómo conciliar un pago confirmado tardíamente. El QR de asistencia solo se habilita al cumplir las reglas del registro.

La bitácora local no es una bandeja de salida garantizada. Antes de enviar automáticamente, implementar un mecanismo recuperable de salida/conciliación que registre intentos, no pierda operaciones confirmadas localmente y permita consultar el resultado. En MongoDB standalone no asumir transacciones entre varias colecciones. Elegir con el grupo un patrón consistente de documento único, conciliación o replica set/transacciones según el despliegue.

## Pruebas conjuntas de aceptación

| Escenario | Resultado a demostrar |
| --- | --- |
| Aprobar una beca y repetir envío | Un solo bono o recurso externo; misma referencia al consultar. |
| Receptor confirma, pero la respuesta se pierde | Consulta por clave recupera la confirmación sin duplicar el beneficio. |
| No hay locker o el receptor rechaza la petición | Se informa el rechazo real; no se muestra entrega ni se altera silenciosamente el dictamen. |
| Cargo vence o estudiante es dado de baja | Se niega la operación que ya no tiene autorización y se conserva historial. |
| Cobro y cancelación se cruzan | Estado consistente, evidencia de resolución y reverso cuando proceda; no boleto habilitado por pago no confirmado. |
| Callback duplicado, falso o fuera de orden | Se autentica y deduplica; no retrocede un estado confirmado por una notificación antigua. |
| Credencial bloqueada o de otra persona | No se registra asistencia; el QR/NFC no sustituye las reglas de pago/cupo. |
| Mensajería a un grupo académico | Solo población autorizada, con preferencias y vista previa; no acceso masivo por cambiar filtros del cliente. |
| Crear tienda y repetir solicitud | Un negocio oficial vinculado, con responsables y autorización del equipo 3. |
| Enviar auditoría/métricas al equipo 7 | Eventos deduplicados y correlacionados, sin documentos privados ni respuestas individuales de votación. |

## Decisiones pendientes del grupo

Confirmar autoridad para alta/recuperación de organizaciones y baja de cuentas; identificador institucional estable; adopción del Fortify del equipo 1; catálogo de permisos y delegaciones; semántica estructurada de beneficios; vencimiento de reservas; transporte/autenticación; estados finales, errores y límites; estrategia de consistencia y entorno de pruebas compartido.

Cada acuerdo debe incluir responsables de ambos equipos, versión, ejemplo válido, ejemplo rechazado y prueba conjunta. Este documento prepara ese trabajo; no implica que se haya contactado a otros equipos ni desplegado integraciones.
