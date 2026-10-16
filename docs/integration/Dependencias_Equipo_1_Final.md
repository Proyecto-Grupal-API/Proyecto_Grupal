# Dependencias de Comunidad tras la entrega final de Identidad

Fecha de revisión: 7 octubre 2026. Equipo 1: `f41c78066cb8e6dde2a1605233e832395cb20841`.

La entrega del Equipo 1 es la referencia correcta. Este registro identifica lo que Comunidad todavía no puede resolver con sus APIs actuales; no modifica sus contratos ni exige inventar endpoints.

| Necesidad de Comunidad | Contrato vigente / límite | Estado |
| --- | --- | --- |
| Estado académico del usuario autenticado | OAuth `students:read`, `/api/v1/students/{User._id}/status`; elegibilidad/restricciones nulas | Integrado por HTTP. La elegibilidad de becas permanece en Comunidad. |
| Buscar estudiante por matrícula para alta de miembro/presidencia | No hay directorio por matrícula publicado. Matrícula canónica en StudentProfile; `users.matricula` es compatibilidad heredada de Comunidad | Pendiente. No dar por válidas nuevas altas de Identidad en el directorio provisional. |
| Mostrar nombre/matrícula y verificar existencia de participantes | Status acepta IDs ya conocidos con perfil; no sustituye un directorio general ni la condición de cuenta | Pendiente de acordar proyección/alcance. No convertir 404 de perfil en una decisión de cuenta inexistente. |
| Permiso para registro institucional de organizaciones | Motor institucional interno; check/read OAuth disponibles son sólo business | Pendiente de contrato institucional y ámbito de la organización. La concesión local de la demo no representa ese motor. |
| Identificador de campus/programa por API | Status ofrece etiquetas, no IDs canónicos | Pendiente. No consultar catálogos directamente desde Comunidad ni reconstruir IDs a partir del nombre. |
| Jefe de departamento | Fuente Department no acordada; scope no asignable y decisión negativa | Bloqueado funcionalmente por la fuente canónica. No crear un catálogo paralelo. |
| Preferencias/consentimientos para campañas | Rutas de sesión/Sanctum; no contrato OAuth de servicio | Pendiente. `students:read` no autoriza esas rutas. |
| Cambios de perfil/credenciales para conciliación | Outbox y transporte de Identidad; sink externo no acordado | Pendiente de transporte compartido. Comunidad no lee el outbox de Identidad. |

Los cargos internos (presidencia, vicepresidencia, secretaría, tesorería, comunicación, responsable de becas y Staff de evento), sus asignaciones y la membresía pertenecen a Comunidad. No duplicarlos en Identidad ni usar un rol global para reemplazarlos.

## Código heredado que debe migrarse cuando exista el contrato

- `OrganizacionController`: resolución de integrantes, altas por matrícula y verificación de titular del cargo.
- `GestionOrganizacionesController` y `RegistroOrganizaciones`: titulares, actores de historial y registro institucional.
- `EventoController`, `StaffEventoController`, `BecaController`: proyección de participantes y verificaciones de cuenta.
- `AudienciaCampana`, `ConsultasComunidad`, `EntregaCampanas`, `InscripcionesEvento` y `EventoPolicy`: resolución de usuarios/existencia.
- `AutoridadOrganizaciones`: autorización institucional provisional, pendiente de API y relación organización↔ámbito.
- Comandos locales de responsables y seeders: herramientas de demostración, no interfaces de integración productiva.

La adaptación no habilita lecturas nuevas del almacenamiento de Identidad. Las lecturas heredadas mencionadas arriba son deuda explícita, no un workaround aprobado ni evidencia de cumplimiento de la regla del profesor. La migración debe preservar referencias `User._id` y distinguir cuenta, estado académico, membership y vigencia de cargo.
