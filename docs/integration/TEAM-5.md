# Equipo 5 — Validación de identidad QR y NFC

Integre sobre su código existente. La referencia contractual es [Team 1](TEAM-1-INTEGRATION.md).

Estado auditado INT-1B.6 contra referencia pública `7a30c3f12722a4e2c6d4caef870f90309a11ae62`: QR **PUBLISHED_AVAILABLE**; NFC **IMPLEMENTED_NOT_YET_PUBLISHED**. La descripción NFC siguiente es del código local, no anuncia disponibilidad externa. Esta fase no publica ni cambia estos contratos.

## Flujo QR disponible

1. Configure un cliente de servicio autorizado para `identity:qr:validate` y obtenga Bearer por `POST /api/oauth/token` con `client_credentials`.
2. Reciba el QR/código presentado en la operación de Equipo 5. Envíe `POST /api/v1/identity/qr-validate` con JSON `{"code":"{PRESENTED_QR_OR_CODE}","context":"{OPERATION_LABEL}"}`. `context` es opcional y de auditoría.
3. Con `200`, lea `ok=true`, `result=valid` e `identity` (`user_id`, `name`, `student`). Aplique **sus** reglas de acceso/beneficio. Con `422`, trate el QR como no utilizable; 401/403 son problemas de autenticación/scope.

El QR dinámico es single-use; el de identificación es reutilizable mientras esté vigente. No almacene ni consulte `qr_tokens` para validar, ni replique firma, short-code o hashes. No consulte `qr_validations` como bus de integración.

## Validación NFC implementada localmente, no publicada

Si una operación presenta una tarjeta NFC, obtenga un Bearer `client_credentials` con el scope **distinto** `identity:nfc:validate` y envíe `POST /api/v1/identity/nfc-validate` con JSON `{"credential_uid":"{PRESENTED_UID}"}`. Un 200 devuelve `ok=true`, `result=valid`, `credential.status=active`, `student_id=User._id` e `identity` mínima. Un 422 devuelve `ok=false` e identidad nula: `not_found`, `revoked`, `blocked`, `suspended` o `replaced`; para los tres estados explícitos se informa `credential.status`, pero nunca el titular. Sin token: 401; sin scope NFC: 403. Consulte el esquema completo en [Team 1](TEAM-1-INTEGRATION.md).

La nueva tarjeta reemplazante hereda el estado operativo y la anterior queda terminal como `replaced`. La validación NFC no consume ni cambia la tarjeta. **Identificación no autoriza** automáticamente préstamo, reserva, acceso físico ni beneficio; aplique las reglas de Equipo 5. No lea `nfc_cards` ni use `identity.credential.changed.v1` como lookup de titularidad o elegibilidad. La consulta de permisos contextuales es un contrato futuro distinto.
