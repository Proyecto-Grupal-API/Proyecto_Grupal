# Equipo 7 — Eventos de identidad

Esta integración se hace sobre trabajo existente de Equipo 7. Consuma el transporte de eventos publicados acordado con Team 1; **no** consulte `event_outbox` directamente. Véase [contrato principal](TEAM-1-INTEGRATION.md).

Sobre publicado: `event_id`, `event_name`, `aggregate_id`, `occurred_at`, `payload`. Versiones existentes:

| event_name | payload actual | Semántica |
| --- | --- | --- |
| `student.profile.changed.v1` | `student_id`, `operation`, `changed_fields`, `actor_id` | `student_id=User._id`; aviso de cambio, no snapshot |
| `student.consent.changed.v1` | `student_id`, `consent_id`, `status`, `version`, `actor_id` | `student_id=User._id`; no incluye documento de consentimiento |
| `identity.credential.changed.v1` | `credential_id`, `credential_type`, `operation`, `status`, `actor_id` | NFC actual; `credential_id=NfcCard._id`, no ID del estudiante ni UID |

Deduplicar por `event_id` y tolerar campos adicionales futuros. No inferir orden global ni titular actual de una tarjeta desde un evento aislado. `CredentialEvent`, `QrValidation` y `SecurityEvent` son registros de historial/auditoría, no publicaciones interequipos. Si Equipo 7 requiere datos de identidad en una operación, puede evaluar la API QR OAuth (`identity:qr:validate`) cuando exista un QR presentado; para otras consultas, plantear un contrato nuevo en vez de leer colecciones de Team 1.
