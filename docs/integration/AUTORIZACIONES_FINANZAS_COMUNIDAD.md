# Autorizaciones de Finanzas y Comunidad

## Caja de asociaciones y Consejo

Finanzas administra cajas, turnos, wallets, límites y registros contables. Cada caja de este flujo usa como `association_id` el ID canónico de la organización de Comunidad. E2 habilita por entorno únicamente las organizaciones que cuentan con una caja y contrapartes aprobadas. Presidencia otorga o revoca las capacidades por la pantalla existente de delegaciones, a integrantes activos de esa misma organización. La vigencia queda vinculada al cargo de Presidencia y se conserva la auditoría.

| Persona | Autoridad |
| --- | --- |
| Presidencia | Designa y revoca delegaciones de caja en su organización habilitada. El cargo por sí solo da autoridad para delegar. |
| Tesorería | Consulta caja, movimientos y comprobantes de su organización mientras conserve cargo y membresía vigentes. |
| Agente de caja | Recibe `caja.operar` para abrir turno, consultar caja, registrar operaciones y cerrar su propio turno. El titular confirma la recarga o retiro. |
| Administrador de caja | Recibe `caja.administrar` para gestionar cajas, consultar la cola y definir políticas de aprobación de la organización. |
| Supervisor de caja | Recibe `caja.revisar` para revisar y decidir solicitudes del ámbito asignado. El operador y el titular quedan excluidos de la aprobación de su propia operación. |
| Responsable de ajuste o recuperación | Recibe `caja.ajustar` o `caja.recuperar` según corresponda. El efecto aplica con la política de supervisión y la evidencia de la operación. |
| Integrante con acceso de consulta | Recibe `caja.consultar` para consultar dentro de su organización, sin facultades operativas. |

E2 comprueba la cuenta activa en Identidad. La web obtiene al operador de la sesión autenticada. Un cliente OAuth por sí mismo queda fuera de esta autoridad. Las operaciones positivas de recarga o retiro requieren una persona operadora distinta del titular. Los ajustes y recuperaciones conservan el control de aprobaciones y referencias de Finanzas.

`FINANCIAL_COMMUNITY_CASH_ASSOCIATIONS` contiene los IDs canónicos separados por comas. Vacío deshabilita la caja comunitaria. Para abrir el piloto, E2 configura límites y políticas de supervisión, incluido el umbral de retiro, y Presidencia asigna los perfiles en el ámbito configurado.

## Transferencias y regalos personales

El titular activo y verificado puede enviar y recibir en su propia wallet de usuario activa en MXN. Se aplican la política SQL de transferencias, límites, consentimiento e idempotencia existentes. El destinatario se resuelve por Identidad y la confirmación vuelve a comprobar la elegibilidad de las dos cuentas. `FINANCIAL_PERSONAL_WALLET_TRANSFERS_ENABLED` habilita la capacidad en el entorno. Su valor de ejemplo es falso.

El responsable de controles financieros de E2 administra la política. La verificación de esa atribución institucional y la prueba de delegación para un cliente externo corresponden a la integración con Identidad.

## Decisiones de E2/E6 para Identidad

| Acción | Autoridad y alcance decididos |
| --- | --- |
| Dictamen y aval BECA | Presidencia del Consejo confirma el dictamen. Tesorería del Consejo avala la solicitud y el importe sobre un presupuesto exacto. La evidencia pertenece a Comunidad. |
| Designar BECA | Presidencia del Consejo concede y revoca designaciones de Tesorería y emisor dentro del Consejo. Finanzas acredita los presupuestos que corresponden al Consejo. E1 verifica la vigencia de `budget.commit` por `InstitutionalBonusBudget.public_id` y limita la designación de emisión al programa del Consejo. |
| Administración presupuestaria BECA | Responsable presupuestario de E2 aprueba el presupuesto nuevo de una fuente acreditada y administra los presupuestos a su cargo. Tesorería del Consejo consulta y compromete solo los UUID asignados. Liberación manual y corrección de devoluciones requieren una atribución presupuestaria propia sobre el mismo UUID. El vencimiento automático usa una identidad de servicio con autoridad de vencimiento. |
| Registrar liquidación BECA | Responsable de liquidaciones designado por E2, con alcance de presupuesto y obligación exactos. E2 resuelve del registro contable el presupuesto, la obligación y el comercio y verifica el comprobante externo. |
| Revisión y ejecución de devoluciones | Responsable financiero designado por E2 concede y revoca revisores y ejecutores por UUID de wallet receptora. Solicitante y revisor son personas distintas. E1 mantiene revisión y ejecución como atribuciones independientes. |
| Lectura de comprobantes | Titular consulta sus propios comprobantes. Auditor de E2 tiene lectura sobre las cuentas o asociaciones asignadas. Tesorería consulta por su caja de Comunidad. Un módulo externo requiere prueba de delegación vinculada al usuario, al cliente y a la consulta. |
| Controles financieros | Responsable de controles de E2 administra límites y ejecuta conciliación. Revisión de alertas y resolución de diferencias requieren capacidades explícitas. Auditor de E2 consulta los registros de su ámbito. |

Finanzas conserva los proveedores que deniegan por defecto donde falta una designación institucional verificable de E1. La atribución del mostrador de Servicios corresponde a E5. Las devoluciones o el cobro propio desde una sesión conservan sus contratos existentes.
