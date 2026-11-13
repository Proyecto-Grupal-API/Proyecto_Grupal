# E6: designaciones, presupuesto y selección académica

Propuesta de implementación sobre MERGE_COMPLETO `1b648b7da21c29a4e8e6b26d9b5ccfc3358c789d`. Sin publicación ni activación. Las políticas nuevas que siguen son decisiones técnicas propuestas para esta entrega; no acreditan aprobación institucional.

## E1-01: contrato interno ordinario

`AdministrativeDesignationService::prepareCatalog(Request)` registra únicamente definiciones cerradas de roles/capacidades, sin asignar usuarios. `grant(Request, array)` recibe user_id del beneficiario, role_key, scope_type, scope_id, starts_at, ends_at y reason. `revoke(Request, assignmentId, reason)` revoca mediante el ciclo de vida existente. El otorgante se toma del Request web actual, con sesión E1 y TOTP de menos de 300 segundos. No hay endpoint nuevo ni actor_id de navegador.

Los registros usan **role_assignments**, OfficialRoleAssignment y EffectiveRoleAssignments. No hay otro RBAC. El escritor RoleAssignment y los servicios piloto anteriores siguen sujetos a assertPilotGrantEnvironment, cuyo cuerpo no cambió. No activar APP_ENV=testing en la aplicación integrada. La nueva vía no permite roles globales financieros antiguos ni edición/renovación de generaciones: primero revocar, luego conceder una generación nueva.

Política propuesta: ninguna autodesignación; motivo obligatorio; inicio/fin obligatorios, fin futuro y horizonte máximo de un año para nuevas asignaciones. Un inicio futuro no habilita uso antes de la fecha. La revocación conserva assigned_by, grant_reason, origen, vigencia y transiciones con revoked_by/reason/fecha. SecurityEvent registra solicitudes con ID de asignación, antes de la escritura; la fila de ciclo de vida prueba si la concesión se materializó. Un evento de solicitud no se presenta como confirmación.

| Atribución | Otorgante propuesto/acreditado | Ámbito |
|---|---|---|
| bonus_beca_council_issuer | Presidencia vigente acreditada por E6 | Council exacto |
| institutional_budget_committer | Presidencia vigente + Tesorería vigente del beneficiario; relación E2 del presupuesto | institutional_budget UUID exacto |
| financial_refund_reviewer / executor | financial_refund_designator, con identity.refunds.designate | Misma wallet UUID; capacidades independientes |
| operador_servicios | service_damage_designator, con identity.services.designate | Global sólo para la capacidad de daños 5.7 |
| institutional_budget_approver | budget_administration_designator, con identity.budgets.designate | Council exacto |
| institutional_budget_adjuster / suspender / resumer | Mismo delegador del Consejo acreditado por la relación E2 | Presupuesto UUID exacto |

Los tres roles de delegación sólo se activan con un plan privado de tuplas exactas: actor_id de un responsable identificado, until hasta 24 horas y grants con user_id, role_key, scope_type, scope_id y ends_at normalizado ISO. No hay contraseña maestra ni concesión en boot. El responsable debe estar autenticado, ser elegible y completar TOTP; no se deduce de admin. La expiración de esa ventana impide nuevas activaciones, pero el otorgante inicial puede revocar sus delegaciones existentes con sesión/TOTP. Revocar una delegación impide nuevas concesiones; no es revocación automática en cascada de todas las atribuciones que emitió. Éstas conservan vigencia propia y deben revocarse expresamente cuando corresponda.

Configuración: IDENTITY_DESIGNATIONS_ENABLED=false por defecto; IDENTITY_DESIGNATIONS_BOOTSTRAP_JSON vacío. La preparación de catálogo es explícita y no reactiva permisos existentes desactivados. Los recursos de wallet deben acreditarse por E2 antes de incluir delegaciones en el plan; E1 no duplica Wallet. Los índices únicos current_identity_unique e identity_generation_unique de la migración publicada deben existir con las claves y opciones esperadas: la vía deniega concesiones si no están. No se ejecutó dicha migración.

## E1-02: contrato financiero compatible

Se conserva `InstitutionalBudgetAuthorization::allows(actor, action, budgetId)`. E1 sigue atendiendo budget.commit con institutional_budget exacto y sin sesión humana artificial: el actor de Tesorería procede del aval validado por E2.

Nuevo contrato especializado `InstitutionalBudgetApprovalAuthorization::allowsApproval(actorId,councilId,institutionReference)`. El adaptador E1 implementa ambos contratos. `InstitutionalBudgetService::approve` transmite council_id e institution_reference, y los revalida dentro de la operación y en reintentos. council_id entra en los datos normalizados y el hash idempotente. No se cambia la generación de public_id ni asientos, saldos, locks o reglas de importes. Proveedores antiguos del contrato general conservan su ruta previa; la implementación E1 siempre utiliza el contrato especializado y falla si falta contexto.

El actor debe coincidir con la sesión web humana, ser elegible y tener TOTP reciente. La autoridad de aprobación usa institutional_budget_approver sobre Council, nunca un UUID futuro. E2 configura una relación unívoca `FINANCIAL_BUDGET_INSTITUTIONS_JSON` (Council ID canónico → institution_reference existente y validada). Una referencia compartida por varios Consejos se deniega. No se infiere Council a partir de programas académicos.

budget.adjust, budget.suspend y budget.resume tienen roles independientes y ámbito institutional_budget. No implican budget.commit ni bonuses.beca.issue. Los otorgantes de esos roles deben tener delegación vigente del Consejo relacionado con ese presupuesto mediante la configuración publicada becas_finanzas.convocatorias. La correspondencia posterior de un presupuesto nuevo con la convocatoria/Consejo debe acreditarla E2; no se escribe configuración automáticamente al aprobar.

No se habilitó financial_identity_authorization ni se cambió FinancialWebAuthorizer general. No se validaron SQL, idempotencia persistente, rollback financiero o emisión real en esta entrega.

## E1-03: directorio y audiencia v1

Se conservan los endpoints D1–D4 existentes:

- POST /api/v1/identity/directory/resolve-enrollment: matrícula exacta normalizada, purpose y context.
- POST /api/v1/identity/directory/batch: máximo 100 IDs conocidos, proyección identity/account_condition, matrícula opcional, propósito y contexto.

Scopes publicados según proyección: identity:directory:resolve, identity:directory:read, identity:accounts:condition:read, identity:directory:enrollment:read. OAuth sólo acredita el cliente; autorización persistida del cliente se revalida por DirectoryAccess/AuthorizeIdentityService. Comunidad ya implementaba resolverMatricula e identidadesOrganizacion: se conservaron. Una falta de perfil no implica inexistencia de la cuenta.

Los consumidores pueden ahora parametrizarse mediante IDENTITY_DIRECTORY_CLIENTS_JSON, manteniendo vacío el valor por defecto. La política añade context_ids opcional por finalidad para restringir organizaciones exactas; no convierte IDs conocidos en un listado global. Deben configurarse explicitamente los contextos de E6 permitidos y las proyecciones mínimas. No se activó ningún cliente OAuth.

Selección nueva: `AcademicAudienceService::select(Request,client,filters)` y consumidor `IdentidadComunidadApi::seleccionarAudienciaAcademica(Request,filters)`. Contrato interno de la misma aplicación, sin nuevo endpoint HTTP ni puente OAuth→humano. Requiere sesión web E1 y academic.program.coordinate vigente sobre el programa exacto mediante InstitutionalAuthorizationService. Presidencia no hereda esa capacidad.

Filtros: academic_program_id obligatorio; semester entero opcional 1–20 (límite técnico conservador, no catálogo nuevo); limit 1–50; cursor cifrado opcional. No admite campus arbitrario ni búsqueda global: el campus se deriva del programa activo y ambos se revalidan. Sólo se consultan StudentProfile académicamente activos del programa/campus, y sólo se entregan cuentas operativas. Proyección cerrada: IDs, nombre y condición; sin correo, teléfono, secretos, matrícula ni datos financieros. Referencias devueltas: campus_id y academic_program_id. Los modelos fuente son Campus, AcademicProgram y StudentProfile.

Semestre es un atributo actual del perfil, no una entidad institucional independiente. group_name es texto libre, no una relación canónica de grupo: no se implementó selección por grupo ni un catálogo duplicado. No se promete snapshot ni padrón inmutable: cada página es live y revalida permisos. El cursor liga actor, cliente, programa, filtro, tamaño y último perfil, expira a los cinco minutos y rechaza manipulación/cambio de contexto.

Configuración privada: IDENTITY_ACADEMIC_CLIENTS_JSON con active=true, academic_program_ids exactos y daily_reference_limit; COMMUNITY_ACADEMIC_DIRECTORY_CLIENT selecciona el consumidor interno configurado. Se reutiliza DirectoryQuota y su bucket batch: máximo 30 solicitudes/minuto, 3000 referencias/minuto y cuota diaria configurada; se carga el tamaño máximo de página antes de consultar. Cada respuesta exige auditoría persistida. No hay inicialización implícita de cuota ni fallback a cache.

Prerequisitos operativos no activados: acreditación de integridad mediante IDENTITY_ENROLLMENT_INTEGRITY_VERIFIED; cuota preparada/acreditada con IDENTITY_DIRECTORY_QUOTA_VERIFIED, IDENTITY_DIRECTORY_QUOTA_DEPLOYMENT e IDENTITY_DIRECTORY_QUOTA_GENERATION. Esas variables registran una acreditación previa: no sustituyen verificar índices/unicidad ni preparar el documento de cuota de forma autorizada.

Errores: 403 acceso/ámbito denegado; 422 filtros o cursor inválido; 429 cuota; 503 integridad, auditoría, cuota o dependencia indisponibles. D1/D4 conservan sus resultados not_found, not_usable, invalid_id, unavailable, deleted y has_student_profile conforme al contrato anterior. La selección no aprueba elegibilidad BECA.

## Validación y límites

Pruebas unitarias/contrato sin persistencia y bootstrap Laravel aislado. No hubo conexión a SQL/Mongo, migraciones, fixtures ni activación de concesiones. Las sustituciones de dependencias de pruebas no acreditan transporte HTTP, escrituras ni integración bilateral real. Antes de activar, revisar esta política y probar concesión/revocación/auditoría/consulta efectiva y selección paginada contra almacenamiento descartable autorizado, preservando los documentos históricos.


## Correcciones focalizadas de la candidata auditada

Las referencias Council heredadas mantienen el contrato publicado de los roles legacy. Las nuevas designaciones administrativas y el emisor contextual requieren un Consejo canónico activo de E6; esa compatibilidad no habilita referencias legacy en la vía administrativa nueva.

El plan de activación exige una fecha ISO8601 absoluta con zona horaria. Para comparar `ends_at`, plan y modelo usan UTC con precisión de milisegundos de BSON, truncando la fracción restante, sin redondear ni prolongar el vencimiento. Usuario, rol y ámbito deben seguir coincidiendo exactamente.

Un fallo del verificador presupuestario deniega la operación y aun así intenta auditar actor, operación, ámbito y resultado denegado. Si falla también la auditoría, la decisión sigue siendo negativa; no se registran detalles de excepciones ni secretos.

La selección académica traduce errores de conexión MongoDB, incluidos los de sesión y autoridad, a `DirectoryFailure(503, directory_unavailable)`. Conserva denegaciones 403, validaciones/cursor 422 y errores de contrato existentes. Los errores de programación o no clasificados no se convierten genéricamente en indisponibilidad. Esto no acredita un transporte HTTP/OAuth ni el recorrido financiero SQL.
