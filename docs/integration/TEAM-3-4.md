# Equipos 3 y 4 — Identidad y roles contextuales

La integración parte de ramas **ya desarrolladas**. Revise [el contrato principal](TEAM-1-INTEGRATION.md) antes de resolver conflictos en `User.php`, `Role.php` o rutas.

- Identificador externo de sujeto: `User._id`; si el sujeto es estudiante, ese mismo valor es `student_id` en los contratos estudiantiles. La identidad QR/NFC validada expone una proyección mínima, no roles ni permisos.
- Catálogo **legacy implementado**: `admin`, `maestro`, `estudiante`, `servicio_cafeteria`, `consejo_estudiantil`, `student_manager` (compatibilidad). Los roles legacy pueden ser globales (`scope_type` y `scope_id` nulos) o contextuales con ambos valores y tipo `business`, `association`, `service` o `council`. `hasRole()` contextual exige coincidencia exacta; un rol global no coincide automáticamente. Los scopes institucionales foundation de INT-1B.6 son internos, separados de esta API legacy y de los contratos business; department permanece diferido.
- `User::assignRole()` y `hasRole()` son lógica **interna de Team 1**, no una API OAuth. Los contratos business publicados de consulta y provisioning inicial se detallan abajo; no hay administración genérica OAuth de roles.
- Si una operación presenta QR, el servicio puede pedir `identity:qr:validate` y usar `POST /api/v1/identity/qr-validate`; un resultado `valid` resuelve identidad, no concede permisos de negocio.
- Si presenta NFC, existe `POST /api/v1/identity/nfc-validate` con `identity:nfc:validate`; también identifica, no autoriza ventas o movimientos de inventario.

## Contratos business publicados INT-1B.5

**AVAILABLE_NOW** desde Snapshot 2, referencia pública `f41c78066cb8e6dde2a1605233e832395cb20841`. Owner provisioning requiere configuración posterior de cliente/grant/allowlist Team 3, no incluida en esta remediación. `RoleAssignment` es autoridad; `User.roles` es histórico. Roles business canónicos: `business_owner`, `business_manager`, `cashier`, `inventory_manager`, `buyer`. Los mecanismos legacy no asignan estos roles. Membership y asignación efectivas deben coincidir en negocio. `servicio_cafeteria` no equivale a ningún rol business.

### Equipo 3

Tras aprobar/crear negocio, puede solicitar `POST /api/v1/identity/business-owner/provision`, scope `identity:business-owner:provision`, cliente explícitamente autorizado por grant **y** allowlist `OAUTH2_BUSINESS_OWNER_CLIENTS`. Body: `business_id`, `subject_id` del solicitante original (no aprobador), `operation_id` estable único. Nueva operación 201; retry exacto 200; payload/Owner/estado conflictivo 409. No reactiva membership pending/suspended/revoked ni transfiere propiedad. Sin token 401, sin cliente/scope 403, request/sujeto inválido 422, schema ausente 503. Conservar operation_id tras fallos de red.

No puede asignar/revocar roles arbitrarios por OAuth. Para decisiones sensibles usar `POST /api/v1/identity/authorization/check`, scope independiente `identity:authorization:check`, body `subject_id`, `capability`, `scope_type=business`, `scope_id`; respuesta 200 con `authorized` boolean. Para estado usar `GET /api/v1/identity/assignments`, scope `identity:assignments:read`, query `subject_id`, `scope_type=business`, `scope_id`; membership_status y roles efectivos mínimos. Los scopes no se implican entre sí.

### Equipo 4

Consume check/read con grants explícitos por cliente; no obtiene provisioning por ser consumidor. `inventory_manager` es la asignación canónica compartida, no `business_inventory_manager`. `buyer` existe contextual, sin capabilities E1 por defecto. Permisos finos de inventario, ventas, órdenes/productos son de Equipo 4. No inferir permisos por etiquetas ni consultar colecciones E1. Negocio A no concede grants en B.

### Confianza y minimización

OAuth client_credentials autentica al **servicio**, no al humano. `subject_id` es target, no prueba de sesión humana. Cada consumidor debe vincular confiablemente su actor para usar la decisión; nunca IDs libres del navegador ni `X-User-ID`, `X-Actor-ID`, `X-Employee-ID`. No hay escritura OAuth genérica de roles. Owner→Owner sigue prohibido en administración normal. Respuestas sin emails, nombres, IDs internos de assignments, fingerprints ni secretos. Ver [contrato completo](TEAM-1-INTEGRATION.md) para estados, fechas, atomicidad y errores. Las tres rutas aplican 30 solicitudes/minuto y revalidan cliente activo/grants actuales.

No copiar ni modificar `User.roles`, ni replicar el motor como autoridad paralela. Policies y reglas comerciales pertenecen a Equipos 3/4. No existe `POST /api/v1/identity/role-check` ni scope `identity:roles:check`; usar sólo los contratos publicados y grants autorizados. Sin acceso directo a persistencia E1.
