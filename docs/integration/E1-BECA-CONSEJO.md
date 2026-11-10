# Autorización BECA E1/E2/E6 — validación local completada

- `budget.commit`: rol técnico `institutional_budget_committer`, permiso financiero `budget.commit`, contexto exacto `institutional_budget=InstitutionalBonusBudget.public_id` (UUID canónico). El actor procede del aval validado de E6; nunca del navegador. El adaptador de `InstitutionalBudgetAuthorization` se selecciona con la activación existente `financial_identity_authorization.enabled`; no se activa aquí. No autoriza administración presupuestaria, lectura global ni emisión BECA.
- `bonuses.beca.issue`: nuevo rol `bonus_beca_council_issuer`, contexto exacto `council=<id canónico E6>`. Se consulta `Organizacion` con tipo `consejo` y estado `activa`. No hay equivalencia con `academic_program` ni fallback global. Los contratos globales anteriores permanecen separados.
- E2 acredita Consejo/presupuesto mediante `becas_finanzas.convocatorias`, configuración del servidor que ya vincula convocatoria, organización y presupuesto. No basta un par de identificadores enviado por el cliente. Sin esa configuración, el flujo se deniega.
- La revisión y confirmación consultan de nuevo el dictamen/aval de E6 y distinguen Tesorería del emisor autenticado. La emisión revalida `budget.commit` dentro de la transacción SQL y verifica el contexto del emisor antes de emitir; el contexto viaja hasta `BonusService`. Los nuevos comprobantes conservan el Consejo; no se modifican comprobantes históricos.
- `CouncilBecaDesignationService` sólo prepara designaciones sintéticas de emisor y Tesorería y su revocación. Exige identidad elegible, organización activa, membresía y cargo vigente `presidencia` de E6 en el Consejo exacto. Tesorería requiere además el cargo vigente y el presupuesto configurado. Reutiliza `RoleAssignment` y su ciclo de vida/auditoría. **Todas las entradas y las nuevas asignaciones/transiciones continúan bloqueadas en producción.** No hay endpoint ni seeder nuevos.
- Se conserva `PendingFinancialWebAuthorizer`: la autoridad administrativa financiera genérica sigue denegada por defecto. Esta implementación no la convierte en autoridad productiva ni activa flags. `CouncilBecaWebAuthorization` es una comprobación especializada, resuelta por el contenedor sin sustituir ese binding. El controlador y los servicios de emisión la consultan sólo para BECA contextual de Comunidad; la emisión ordinaria y las demás operaciones conservan su autorización anterior.

## Cierre de autorización web

La comprobación especializada obtiene al humano del guard web del `Request` actual y exige elegibilidad E1, TOTP reciente de 300 segundos, emisor y Consejo exactos, Tesorería y presupuesto exactos, y relación configurada por E2. Verifica de nuevo que las designaciones efectivas procedan de una Presidencia actualmente vigente en ese Consejo. La pérdida de esa autoridad impide nuevas emisiones aunque permanezca un registro de designación. Cada decisión se audita; fallo de dependencia o auditoría deniega. `becas_finanzas.enabled` sigue siendo obligatorio y no se modifica su configuración.

No se aceptan actor, Consejo, presupuesto o Tesorería como campos libres del formulario de emisión. E2 los deriva de la sesión y del dictamen/aval consultado de E6. Se revalida en confirmación, dentro de la transacción institucional y antes de emitir el bono. No cambia la consulta de comprobantes históricos; esta revisión no modifica ni elimina esos documentos.

## Validación persistente local

El 10 de noviembre de 2026 se completaron 18 escenarios y 55 comprobaciones con Laravel real, MongoDB `campus-e1-test-mongo:27018/campus_virtual_testing` y SQL Server `sqlserver:1433/campus_digital_financial_testing`.

Se comprobaron emisión y compromiso, ámbitos y atribuciones independientes, pérdida de autoridad otorgante, vencimiento/revocación, expiración del segundo factor, indisponibilidad de identidad, presupuesto insuficiente/suspendido/expirado, idempotencia y referencias conflictivas, rollback ante fallo intermedio, recuperación histórica y revocación entre revisión y confirmación.

El transporte HTTP entre módulos se sustituyó en el proceso de prueba: se ejecutaron el controlador E6 con el aval persistido y las decisiones E1 reales. No se certifica aquí el despliegue HTTP/OAuth entre servidores. Las pruebas no habilitan concesiones productivas.

Se aplicaron 18 migraciones SQL focales a la base nueva de testing, con usuario exclusivo y sin acceso a la base financiera habitual. No se modificaron las guardas E1 ni los perfiles ordinarios. Las transacciones SQL de los escenarios se revirtieron para la limpieza; en MongoDB sólo se retiraron los documentos creados por esta ejecución y se verificaron las huellas de todos los documentos preexistentes. Ambos servidores quedaron detenidos.

Evidencia privada: `storage/app/private/beca-persistente-b008s56o/result.json` y `closure.json`. La configuración y credenciales de pruebas permanecen fuera del código entregable.

## Incorporación conjunta

La entrega se divide en dos commits consecutivos: autorización E1 y consumo financiero E2. Deben incorporarse juntos: las designaciones E1 consultan la relación Consejo-presupuesto de E2, y la autorización web E2 consume las atribuciones E1. El primer commit no constituye una entrega operativa independiente. Se conservan los modelos y contratos E6 publicados en la base de MERGE_COMPLETO. La validación del transporte HTTP/OAuth entre servidores sigue pendiente.
