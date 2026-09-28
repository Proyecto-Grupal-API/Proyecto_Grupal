# Equipo 6 — Perfil y condición académica

Integre el snapshot con la rama existente; preserve ambas implementaciones. Véase [contrato principal](TEAM-1-INTEGRATION.md).

- Identificador externo: `User._id` bajo `student_id`; `StudentProfile._id` no es un identificador de servicio.
- Lectura disponible por OAuth `students:read`: `GET /api/v1/students/{User._id}/status` y `/status/history`. La respuesta de estado incluye matrícula, campus, programa, semestre, estado y razón reciente. `restrictions`/`benefits_eligible` son null, no reglas de elegibilidad.
- `student.profile.changed.v1` se persiste en el outbox en alta/edición, cambio académico y actualización de preferencias; el transporte local puede publicarlo, pero su entrega a un consumidor externo aún no está desplegada/acordada. `payload` contiene `student_id=User._id`, `operation`, `changed_fields`, `actor_id`. **No** contiene perfil completo, estado anterior/nuevo, campus ni matrícula. Un consumidor que necesite el estado actual debe consultar el endpoint OAuth autorizado.
- Estados académicos: `active`, `inactive`, `suspended`, `restricted`, `leave`, `graduated`; no confundirlos con estados NFC.

Los cargos internos con vigencia, delegaciones y reglas de asociaciones/Consejo no se infieren de un `scope_type` de Equipo 1: su ownership y eventual contrato compartido requieren acuerdo. `campus` es un dato académico; ni `campus` ni `department` son scopes RBAC implementados, y no se promete un campo `department` en el contrato académico. No leer `student_profiles` ni `academic_status_history` directamente. Si la API actual no ofrece el dato o decisión que necesita su módulo, registre la dependencia con Team 1 antes de implementar un acceso a almacenamiento interno.
