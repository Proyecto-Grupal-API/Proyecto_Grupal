# Equipos 3 y 4 — Identidad y roles contextuales

La integración parte de ramas **ya desarrolladas**. Revise [el contrato principal](TEAM-1-INTEGRATION.md) antes de resolver conflictos en `User.php`, `Role.php` o rutas.

- Identificador externo de sujeto: `User._id`; si el sujeto es estudiante, ese mismo valor es `student_id` en los contratos estudiantiles. La identidad QR/NFC validada expone una proyección mínima, no roles ni permisos.
- Catálogo **implementado**: `admin`, `maestro`, `estudiante`, `servicio_cafeteria`, `consejo_estudiantil`, `student_manager` (compatibilidad). Los roles internos pueden ser globales (`scope_type` y `scope_id` nulos) o contextuales con ambos valores y tipo `business`, `association`, `service` o `council`. `hasRole()` contextual exige coincidencia exacta; un rol global no coincide automáticamente. No existen contextos RBAC `campus` ni `department`.
- `User::assignRole()` y `hasRole()` son lógica **interna de Team 1**, no una API OAuth. No existe endpoint de servicio para consultar o administrar roles contextuales.
- Si una operación presenta QR, el servicio puede pedir `identity:qr:validate` y usar `POST /api/v1/identity/qr-validate`; un resultado `valid` resuelve identidad, no concede permisos de negocio.
- Si presenta NFC, existe `POST /api/v1/identity/nfc-validate` con `identity:nfc:validate`; también identifica, no autoriza ventas o movimientos de inventario.

**INT-1B = WAITING_FOR_INTERTEAM_CONTRACT.** `business_owner`, `business_manager`, `business_cashier`, `business_inventory_manager`, `business_buyer` y `business_auditor` son nombres candidatos, **no implementados ni acordados**. Falta definir ownership del catálogo, quién asigna y revoca, y cómo se vincula un actor humano autenticado con el `User._id` consultado. OAuth `client_credentials` identifica al servicio, no al humano; no acepte un ID arbitrario del navegador como prueba de identidad. `servicio_cafeteria` no equivale a propietario, gerente, cajero o encargado de inventario.

No copiar ni modificar el array de roles embebido en `users`, ni replicar el middleware contextual en otro servicio como fuente de verdad. Las Policies y reglas de venta/inventario pertenecen a Equipos 3/4. Soliciten un contrato explícito para la comprobación contextual de asignación; **no existe aún** `POST /api/v1/identity/role-check` ni scope OAuth `identity:roles:check`. No inventen endpoint ni lectura directa de MongoDB.
