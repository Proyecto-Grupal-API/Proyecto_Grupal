# Equipos 3 y 4 — Identidad y roles contextuales

La integración parte de ramas **ya desarrolladas**. Revise [el contrato principal](TEAM-1-INTEGRATION.md) antes de resolver conflictos en `User.php`, `Role.php` o rutas.

- Identificador externo de estudiante: `User._id`. La identidad QR validada expone `identity.user_id`, `identity.name` y una proyección `identity.student`; no expone roles ni permisos.
- Catálogo actual: `admin`, `maestro`, `estudiante`, `servicio_cafeteria`, `consejo_estudiantil`, `student_manager`. Los roles pueden ser globales o contextuales con `business`, `association`, `service`, `council` y `scope_id`. No existe `campus` context.
- `User::assignRole()` y `hasRole()` son lógica **interna de Team 1**, no una API OAuth. No existe endpoint de servicio para consultar o administrar roles contextuales.
- Si una operación presenta QR, el servicio puede pedir `identity:qr:validate` y usar `POST /api/v1/identity/qr-validate`; un resultado `valid` resuelve identidad, no concede permisos de negocio.

No copiar ni modificar el array de roles embebido en `users`, ni replicar el middleware contextual en otro servicio como fuente de verdad. Si necesitan decidir permisos de asociación/negocio/consejo desde su servicio, describan la consulta necesaria y soliciten a Team 1 un contrato explícito. No inventar endpoint de roles ni lectura directa de MongoDB.
