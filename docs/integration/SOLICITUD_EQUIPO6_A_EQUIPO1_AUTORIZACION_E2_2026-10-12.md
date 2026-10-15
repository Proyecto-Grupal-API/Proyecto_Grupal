# Solicitud a Equipo 1: identidad y autorización

Integración de Comunidad y Finanzas · Propuesta redactada por Equipo 6

## 1. Información general y alcance

| Campo | Valor |
| --- | --- |
| Proyecto | Campus Digital |
| Emisor | Equipo 6 — Comunidad |
| Destinatario | Equipo 1 — Identidad y autorización |
| Coordinación solicitada | Equipo 2 — Finanzas. Esta propuesta es redactada por E6; la validación y participación de E2 están pendientes. |
| Asunto | Aclarar identidad, autoridad y contexto del actor para integrar eventos, becas y caja. |
| Fecha de elaboración | 12 de octubre de 2026 · America/Mexico_City |
| Referencias | Solicitud de E2 a E6 del 12/10 y respuesta de E6 a E2, especialmente el acuerdo A6. |
| Estado / prioridad | Propuesta para coordinación · Alta. Responsables, alcance final y fecha de entrega por confirmar. |

Solicitamos definir cómo consumir correctamente Identidad en la integración E6–E2. E1 es la fuente de verdad de identidad y de los roles/permisos institucionales que administra. E6 conserva organizaciones, cargos, periodos, delegaciones de su dominio, reservas y dictámenes. E2 conserva la autorización y ejecución de sus operaciones financieras conforme a las atribuciones aprobadas.

E6 y E2 se adaptarán al contrato propietario de E1. Primero necesitamos aclarar responsabilidades y mecanismo oficial de consumo; después, si hace falta una capacidad adicional, acordar su alcance y dueño. No se da por aprobado un nuevo rol, scope, endpoint o esquema de delegación.

La aplicación es un monolito Laravel modular. El intercambio entre dominios se hará por HTTP/API; la sesión compartida puede identificar al actor humano, pero no autoriza consultas directas a las colecciones de Identidad desde E2/E6.

## 2. Base publicada y necesidad de coordinación

Referencias verificadas el 12/10: E1, rama feat/modulo-1-equipo-1, commit 86111548a7659ffa3dab4fd6ee441bb70160b3d9; E2, rama feat/modulo-2-equipo-2, commit 39de155c98469c8a1972814a3e9d508f1bd70410.

- E1 define User._id como identificador externo canónico. Matrícula y StudentProfile._id no lo sustituyen.
- OAuth de servicios autentica a una aplicación; no identifica automáticamente al actor humano. El subject_id de una consulta business es el sujeto evaluado y no demuestra quién originó la petición.
- Las APIs públicas de check/read de autorización descritas por E1 tienen ámbito business. La autorización institucional existe internamente y sus responsabilidades/ámbitos no equivalen a un contrato financiero externo.
- QR/NFC o estado académico aportan identificación/información; no autorizan por sí mismos cobros, uso de fondos, becas o acceso al evento.
- E2 mantiene proveedores de permisos financieros y delegación pendientes. Sus contratos de transferencias/comprobantes proponen validar actor y solicitud, pero ese mecanismo no se presume aprobado ni se extrapola a pagos de eventos o becas.

Los nombres FinancialRoleProvider, FinancialWebAuthorizer, BonusAuthorizationProvider, CashAuthorizationProvider, TransferDelegationProvider y ReceiptDelegationProvider son interfaces internas de Finanzas. Se citan para localizar la dependencia; no implican que E1 deba implementar esas clases ni asumir las reglas financieras.

## 3. Aclaraciones solicitadas: I1–I8

Solicitamos responder numerando I1–I8. Para cada punto, indicar responsabilidad y contrato publicado que podemos consumir; si no existe una capacidad aplicable, señalar el acuerdo o equipo/autoridad que debe resolverla. No necesitamos que definan todavía endpoints nuevos ni que publiquen permisos financieros sin un acuerdo funcional.

| Punto | Necesidad y respuesta solicitada |
| --- | --- |
| I1 · Identidad y estado de cuenta | ¿Cómo verificamos por contrato oficial el actor, pagador o beneficiario conocido por User._id y las condiciones de cuenta/sesión que deben impedir una operación? Distinguir cuenta activa, perfil/estado académico, contraseña inicial, sesión revocada y política 2FA. Precisar qué puede comprobar E1 y qué decide E2/E6. Un perfil académico ausente no debe interpretarse automáticamente como cuenta inexistente. |
| I2 · Actor en llamadas HTTP | ¿Qué mecanismo oficial permite vincular el usuario de la sesión humana a una solicitud de E6 hacia E2? Explicar cómo se distingue cliente OAuth de actor y cómo se evita confiar en user_id, roles o encabezados arbitrarios del navegador. Si se admite contexto de sesión validado por servidor, definir su frontera de confianza sin leer colecciones ajenas. |
| I3 · Consentimiento de la operación | ¿Cómo debe acreditarse el consentimiento del pagador de un evento, separado de la facultad del administrador de la organización? Precisar confirmación, alcance y vigencia; qué datos/operación quedan vinculados y cómo se invalida. Para becas, distinguir autorización del emisor/fondos de identidad del beneficiario. E2 debe validar que el mecanismo sea compatible con su ejecución. |
| I4 · Cargo y permiso financiero | Confirmar cómo componemos la identidad/autorización institucional de E1, el cargo y periodo de E6 y el permiso financiero de E2. Presidencia/tesorería son cargos de Comunidad; no deben duplicarse como roles generales. Consultar caja, operar efectivo, solicitar un cobro, emitir apoyo y autorizar devolución son capacidades distintas; el nombre del cargo o admin global no las concede automáticamente. |
| I5 · Autoridad y ámbitos | ¿Qué autoridad institucional puede otorgar/revocar las atribuciones requeridas y qué parte corresponde resolver a E1, E2, E6 o a la institución? Precisar ámbito: organización/asociación, campus, negocio u otro aprobado. Usar IDs de sus fuentes canónicas, sin inferirlos de nombres. organization_manager/career_coordinator no se considerarán permisos de uso de fondos por defecto. |
| I6 · Vigencia y revocación | ¿Qué debe revalidarse al ejecutar una operación sensible y con qué política de revocación/caché? Distinguir cargo vencido o revocado, suspensión de organización, estado de cuenta, sesión y cliente de servicio. Solicitar o poseer un token no demuestra que esos estados sigan vigentes. Acordar la respuesta ante dependencia no disponible y los casos que deben negar una nueva acción. |
| I7 · Worker, reintentos y conciliación | ¿Cómo se autoriza el proceso de E6 que entrega una beca o recupera un resultado después de que la sesión termine o el cargo cambie? Diferenciar iniciar una operación nueva, ejecutar una ya solicitada, consultar evidencia previa y pedir un reverso nuevo. Definir autoridad del servicio/origen y qué se conserva como evidencia; no fabricar una sesión humana ni reutilizar pruebas vencidas. La recuperación debe seguir siendo idempotente. |
| I8 · Contrato consumible y coordinación | Indicar la documentación, versión y rama/commit oficiales aplicables; autenticación, contexto y campos mínimos verificables, límites y errores. Si se propone delegación, E1/E2 deben acordar cliente, usuario, finalidad, acción, solicitud, vigencia, revocación y repetición; el formato propuesto por E2 no se asume contrato de E1. Precisar responsable de cada adaptador y entrega adicional necesaria. |

La respuesta debe mantener separadas identificación, autorización, consentimiento y elegibilidad. Tampoco se solicita a E1 decidir el importe, saldo, fuente concreta de fondos, regla de reembolso, cupo del evento o dictamen académico: pertenecen a los dominios y autoridades correspondientes.

## 4. Casos que debemos poder demostrar

| Flujo | Datos y decisiones que deben coordinarse |
| --- | --- |
| Evento con costo | E6 reserva un lugar y fija importe/origen; E1 valida identidad/contexto del pagador; E2 confirma consentimiento, autorización y pago. E6 habilita boleto solo con lugar vigente y evidencia financiera válida. Reservar no demuestra consentimiento de débito. |
| Beca monetaria o bono | E6 registra dictamen y beneficio aprobado; la autoridad/emisor y fuente de fondos se validan por el mecanismo acordado; E2 ejecuta y conserva ledger. La aprobación de E6 no concede permiso de fondos ni acredita entrega. |
| Consulta de caja | Se identifican actor y organización; E6 aporta cargo/contexto vigente; E2 aplica el permiso de consulta y aislamiento. Ese permiso no habilita recarga, retiro o ajuste. |
| Cargo revocado o respuesta perdida | No se habilita una nueva acción con el cargo revocado. El resultado de una solicitud anterior no debe perderse ni duplicarse: la autoridad para conciliar/consultar se define expresamente. Una devolución nueva mantiene sus propios permisos. |

Criterios de aceptación propuestos para la integración acordada:

- Un cliente de servicio válido sin contexto humano requerido no puede actuar como cualquier usuario.
- Un rol/cargo enviado por el navegador o una identidad QR/NFC no concede autoridad financiera.
- Un cargo, asignación o cuenta no vigente impide la acción nueva que dependa de su autoridad; la regla concreta de revocación queda documentada.
- El ámbito de una organización/negocio no autoriza operaciones en otro; los IDs se validan contra su fuente propietaria.
- El consentimiento exigido queda vinculado a la operación prevista y no permite cambiar pagador, importe, destinatario o finalidad.
- Reintentos y respuestas tardías conservan trazabilidad sin duplicar movimientos; conciliación y autorización de una nueva operación se distinguen.
- Una dependencia ausente no se resuelve con permisos simulados; se demuestra la ruta denegada y el tratamiento recuperable.
- Los consumidores usan contratos HTTP oficiales y no consultan colecciones de Identidad; no registran secretos ni pruebas crudas.

## 5. Entregables, responsables y referencias

- Primero: respuesta funcional I1–I8 y acuerdo de responsabilidades E1/E2/E6. E2 debe validar especialmente I3, I4 e I7 antes de considerar conjunta la decisión.
- Después: referencia al contrato ya publicado o propuesta de la capacidad faltante con responsable; ejemplos válidos/denegados y política de vigencia/revocación.
- E6/E2 implementarán sus adaptadores conforme al contrato aprobado. La entrega incluirá pruebas del flujo conjunto y rama/commit reproducible para Testings.

| Coordinación | Estado |
| --- | --- |
| Responsable E6 | Por confirmar. |
| Responsable E1 | Por confirmar. |
| Validación y responsable E2 | Pendientes; este documento no representa firma ni acuerdo del Equipo 2. |
| Autoridad institucional otorgante | Por confirmar según el acuerdo funcional; no se asigna desde esta solicitud. |
| Estimación/fecha de entrega | Se definirán al cerrar alcance y dependencias. La fecha del documento es de elaboración. |

Fuentes revisadas; los enlaces apuntan a commits exactos:

[E1 — Contrato publicado de integración de Identidad](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/86111548a7659ffa3dab4fd6ee441bb70160b3d9/docs/integration/TEAM-1-INTEGRATION.md)

[E2 — Bindings y proveedores pendientes](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/app/Providers/AppServiceProvider.php)

[E2 — Propuesta de delegación para transferencias](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/docs/modulo-2/2.6-api-delegada.md)

[E2 — Propuesta de consulta delegada de comprobantes](https://github.com/Proyecto-Grupal-API/Proyecto_Grupal/blob/39de155c98469c8a1972814a3e9d508f1bd70410/docs/modulo-2/2.9-api-comprobantes.md)

Referencia de E6: RESPUESTA_EQUIPO6_A_EQUIPO2_2026-10-12, acuerdo A6. El avance local 1–5–6 de E6 aún no está publicado; se proporcionará evidencia al entregarlo. Esta solicitud documenta necesidades y no acredita APIs nuevas, firmas, autorizaciones o pruebas ejecutadas.

Para los agentes de IA: tomar el contrato propietario publicado de E1 como referencia, distinguir propuestas de acuerdos y no extrapolar el ámbito business a Finanzas/organizaciones. Los nombres de interfaces de E2 localizan trabajo de adaptación; no son endpoints ni obligaciones aceptadas por E1.
