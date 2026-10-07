# Equipo 6 — Perfil y condición académica

Integre el snapshot con la rama existente; preserve ambas implementaciones. Véase [contrato principal](TEAM-1-INTEGRATION.md).

- Identificador externo: `User._id` bajo `student_id`; `StudentProfile._id` no es un identificador de servicio.
- Lectura disponible por OAuth `students:read`: `GET /api/v1/students/{User._id}/status` y `/status/history`. La respuesta de estado incluye matrícula, campus, programa, semestre, estado y razón reciente. `restrictions`/`benefits_eligible` son null, no reglas de elegibilidad.
- `student.profile.changed.v1` se persiste en el outbox en alta/edición, cambio académico y actualización de preferencias; el transporte local puede publicarlo, pero su entrega a un consumidor externo aún no está desplegada/acordada. `payload` contiene `student_id=User._id`, `operation`, `changed_fields`, `actor_id`. **No** contiene perfil completo, estado anterior/nuevo, campus ni matrícula. Un consumidor que necesite el estado actual debe consultar el endpoint OAuth autorizado.
- Estados académicos: `active`, `inactive`, `suspended`, `restricted`, `leave`, `graduated`; no confundirlos con estados NFC.

## Autorización institucional INT-1B.6 — IMPLEMENTED LOCAL

`RoleAssignment` es autoridad; `User.roles` es histórico, sin fallback. El servicio **interno** `InstitutionalAuthorizationService::allows(User, capability, scope_type, scope_id)` resuelve:

| Rol canónico | Capability | Scope | Identificador estable |
| --- | --- | --- | --- |
| `organization_manager` | `organizations.institutional.manage` | `campus` | String de `Campus._id`, no code/name |
| `career_coordinator` | `academic.program.coordinate` | `academic_program` | String de `AcademicProgram._id`, no code/name |
| `department_head` | `academic.department.manage` | `department` | UNRESOLVED: sin fuente canónica acordada |

Campus y AcademicProgram reutilizan los catálogos existentes. Se exige `is_active=true`, programa vinculado a campus activo y coherencia con `campus_id` de la asignación. Sin soft-delete propio en esos modelos: la ausencia del documento deniega. Cambiar el campus de un programa invalida el vínculo anterior; no transfiere autoridad automáticamente.

La decisión requiere usuario persistido no eliminado, sin activación ni cambio inicial de password pendientes, asignación active/current vigente, scope exacto, rol canónico persistente, Permission institucional activa y RolePermission persistente aprobado por la matriz versionada. No requiere ni crea memberships institucionales. No hay bypass admin global, herencia campus→programa ni programa→campus. Roles múltiples sólo contribuyen dentro del scope exacto; lifecycle compartido con `EffectiveRoleAssignments` (inicio inclusivo, fin exclusivo).

## DEFERRED / EXTERNAL DEPENDENCY

- `department_head` existe estructuralmente, pero **no es asignable**: el modelo rechaza department y el motor siempre deniega ese scope, incluso ante registros raw. Falta identificador/fuente canónica Department; no se crea catálogo paralelo ni se sustituye por campus/programa.
- Autoridad otorgante y delegación institucional final: **DEFERRED**. No se presume admin, student_manager ni business_owner. No se abre UI ni API genérica de asignación institucional; `Role::VALID_ROLES` conserva seis legacy. Fixtures de testing no son política productiva de otorgamiento.
- Relación organización Team 6↔campus/programa: **EXTERNAL_TEAM_DEPENDENCY**. No se inventa esa relación.
- Resolución institucional implementada localmente **no es un contrato OAuth publicado**. Check/read de INT-1B.5 son business-only; no consumirlos como autorización institucional. Un futuro contrato externo institucional requiere fase/acuerdo propio, sin acceso directo a MongoDB.

## TEAM6-OWNED

Team 1 conserva identidad, account/profile, estado académico y roles/scopes/vigencias institucionales. Team 6 conserva organizaciones, memberships, cargos internos, delegaciones, historia y elegibilidad. Presidency, vice-presidency, secretary, treasurer, communications y event Staff **no** se materializan como roles Team 1. `organization_manager(campus)` no convierte al sujeto en administrador interno de todas las organizaciones.

## PUBLISHED frente a CURRENT_LOCAL

Referencia pública auditada: `7a30c3f12722a4e2c6d4caef870f90309a11ae62`. QR OAuth y estado/historial académico están en esa referencia. NFC OAuth e integración business están implementados localmente, no publicados. La resolución institucional de esta fase es interna/local, no externamente consumible. No hay push ni promesa de entrega externa de eventos.

No leer `student_profiles`, `academic_status_history`, `role_assignments`, Campus ni AcademicProgram directamente desde otro equipo. La segmentación académica de la API existente ofrece nombres de campus/programa y semestre, no sus IDs canónicos como campos del contrato OAuth; no convertir esas etiquetas en scope IDs. Si Team 6 requiere los IDs para un contrato institucional futuro, es un gap contractual explícito, no autorización para lectura directa. La segmentación no concede autoridad ni crea un catálogo externo independiente. Registre cualquier dato/decisión faltante como dependencia contractual; Team 6 mantiene su elegibilidad.
