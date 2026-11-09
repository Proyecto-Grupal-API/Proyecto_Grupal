# Infraestructura interna financial y rewards

Batch 03A añadió infraestructura local; Batch 03B define la matriz financiera y su adaptador S2S local. No designación institucional de otorgantes, integración operativa E2/E7, clientes productivos, migrations ni datos development. No publicación parcial; X9 permanece CLOSED internamente y el productor E7 no cambia.

## Definición, mapping y asignación

`Permission::CATALOG` conserva los ocho permisos canónicos business/institutional usados por el seeder foundation. `TECHNICAL_CATALOG` define once identificadores adicionales; `definitions()` une ambos para validar una definición técnica. Los dominios son business, institutional, financial y rewards. Estas definiciones no se persisten por el seeder existente; no ejecutarlo en development para activar este lote.

La definición de capacidad, un mapping rol-capacidad y una asignación efectiva a User._id son tres cosas distintas. Batch 03B define siete roles/mappings financieros separados en `FINANCIAL_ROLE_SCOPES` y `FINANCIAL_MAPPINGS`; no los persiste ni concede. `REWARDS_ROLE_SCOPES` y `REWARDS_MAPPINGS` siguen vacíos. No mappings E7 para admin/owner/manager/cashier/rewards_admin/auditor. `VALID_ROLES` legacy y foundation16/mappings13 siguen intactos. RoleAssignment valida el catálogo combinado de23 claves, con ámbitos exactos, sin ampliar el seeder. FinancialRoleGrantService permanece bloqueado en producción y su contrato de autoridad carece de binding.

## Contratos internos

`BonusAuthorization::allows(?string $subjectId, string $bonusType, string $operation, ?string $scopeType=null, ?string $scopeId=null): bool` se resuelve a `FinancialAuthorizationService` mediante el binding de interfaz de Laravel. Tipos exactos y claves técnicas:

| Tipo | Definición técnica | Ámbitos compatibles |
|---|---|---|
| BECA | bonuses.beca.issue | Global null/null, academic_program |
| CAMPANIA | bonuses.campania.issue | Global null/null; no campaign contextual |
| BENEFICIO | bonuses.beneficio.issue | Global null/null |
| CATEGORIA | bonuses.categoria.issue | Global null/null |
| NEGOCIO | bonuses.negocio.issue | Global null/null, business |

Sólo operation=issue. No emisión, asignación de bonos ni operaciones financieras. Cada ámbito es exacto, no OR global/contextual implícito. Programa/campus canónicos activos y binding original de campus son obligatorios. Business requiere membership efectiva en el negocio exacto. E1 no mantiene el catálogo comercial: usa el business_id opaco del contrato actual, no nombres ni IDs de usuario como sustitutos.

`RewardsAuthorization::allows(?string $subjectId, string $capability, ?string $scopeType=null, ?string $scopeId=null): bool` se resuelve a `RewardsAuthorizationService`. Reconoce rewards.program.manage, rewards.catalog.manage, rewards.config.approve, rewards.redemption.deliver, audit.view y analytics.view. Catálogo/analítica admiten global o business exactos; entrega sólo business; programa/aprobación/auditoría sólo global. Compatibilidad de capacidad/ámbito no concede ningún rol.

audit.view y analytics.view pertenecen exclusivamente al dominio Recompensas E7, no otorgan lectura transversal de datos de otros módulos. Un true decide un derecho efectivo, no autoriza por sí solo un recurso: E7 debe verificar recurso, partición del negocio, elegibilidad, saldo, canje, minimización y separación creador/aprobador. No se implementan esas reglas aquí.

## Evaluación y seguridad

### Política financiera de aplicación local

La entrada HTTP local usa `FinancialAuthorizationDecision` → `FinancialAuthorizationPolicy`, por atributo Bind. `BonusAuthorization` y `FinancialAuthorizationService` permanecen exact-scope. La política consulta global únicamente para solicitudes sin contexto; en NEGOCIO/business exige primero `FinancialContextValidator` con resultado tipado Valid/Invalid/Unavailable y después compone global específico O contextual exacto. No hay binding productivo del validador: una solicitud contextual produce indisponibilidad hasta conectar un contrato confiable. Invalid deniega sin fallback. BECA y CAMPANIA contextuales permanecen bloqueadas, aunque el motor conserve su capacidad técnica academic_program. Los errores técnicos conocidos se encapsulan con causa interna; errores inesperados se propagan y el transporte financiero los sanitiza. Ningún cambio al writer, autoridad institucional, OAuth general o adaptadores E2 del proyecto permanente de integración.

Ambos motores consultan User._id canónico persistido, respetan cuentas eliminadas/activación/password inicial, requieren permisos activos del dominio correcto y combinan matriz cerrada versionada, rol persistente, mapping persistente y RoleAssignment active/current con inicio inclusivo/fin exclusivo. No se crean datos al consultar. No fallback a User.roles ni privilegios por nombres de roles. Un JWT client_credentials no prueba actor humano; el caller deberá vincular su sesión al sujeto antes de usar una decisión. No se aceptan roles/grants ni políticas del request.

Los errores de infraestructura no se capturan como false: se propagan al caller; false representa denegación o entrada no compatible, no disponibilidad certificada. No cache de derechos ni autorización durable: debe revalidarse antes de la operación sensible.

Los motores usan catálogos de dominio propios, no los roles comerciales inferidos por scope. `BusinessAuthorizationService::businessRoles()` ahora utiliza `Role::BUSINESS_ROLES` cerrado: owner, manager, cashier, inventory_manager y buyer. Los endpoints publicados check/read/provision conservan semántica/scopes/respuestas; no exponen roles de otros dominios ni asignaciones globales. La whitelist de administración comercial no se modifica.

## Pruebas y límites

Los tests de infraestructura inyectan políticas sintéticas mediante subclasses exclusivamente en tests, con guard testing/mongodb/campus_virtual_testing. Sus roles fixture y mappings raw no son políticas aprobadas ni nombres canónicos nuevos. Las instancias productivas resueltas por interfaz no aceptan esos roles sintéticos. Batch 03B prueba además las siete definiciones financieras mediante el modelo/lifecycle existente y una designación sintética guardada exclusivamente en testing; no constituye una autoridad institucional real. La matriz rewards permanece vacía. Estas pruebas no demuestran integración real.

Una fase posterior deberá acreditar otorgantes, autorizar materialización de catálogos financieros y desbloqueo del writer, configurar cliente/grant financiero y probar el consumidor E2. Rewards mantiene sus decisiones pendientes. No asignar roles manualmente ni activar grants en development. El contrato HTTP financiero está en [TEAM-2](TEAM-2.md); sólo implementación local no publicada. Eventos/históricos/outbox, QR/NFC, status, consentimientos e institucional no se alteran.
