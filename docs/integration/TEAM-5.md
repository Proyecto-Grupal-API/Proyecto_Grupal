# Equipo 5 — Validación de identidad QR y NFC

Integre sobre su código existente. La referencia contractual es [Team 1](TEAM-1-INTEGRATION.md).

Snapshot 2 publicado: `f41c78066cb8e6dde2a1605233e832395cb20841`. QR y NFC están **AVAILABLE_NOW**; QR ya estaba publicado en Snapshot 1. El consumo requiere cliente OAuth autorizado. La remediación local de consentimientos no cambia estos contratos.

## Flujo QR disponible

1. Configure un cliente de servicio autorizado para `identity:qr:validate` y obtenga Bearer por `POST /api/oauth/token` con `client_credentials`.
2. Reciba el QR/código presentado en la operación de Equipo 5. Envíe `POST /api/v1/identity/qr-validate` con JSON `{"code":"{PRESENTED_QR_OR_CODE}","context":"{OPERATION_LABEL}"}`. `context` es opcional y de auditoría.
3. Con `200`, lea `ok=true`, `result=valid` e `identity` (`user_id`, `name`, `student`). Aplique **sus** reglas de acceso/beneficio. Con `422`, trate el QR como no utilizable; 401/403 son problemas de autenticación/scope.

El QR dinámico es single-use; el de identificación es reutilizable mientras esté vigente. No almacene ni consulte `qr_tokens` para validar, ni replique firma, short-code o hashes. No consulte `qr_validations` como bus de integración.

## Validación NFC publicada — AVAILABLE_NOW

Si una operación presenta una tarjeta NFC, obtenga un Bearer `client_credentials` con el scope **distinto** `identity:nfc:validate` y envíe `POST /api/v1/identity/nfc-validate` con JSON `{"credential_uid":"{PRESENTED_UID}"}`. Un 200 devuelve `ok=true`, `result=valid`, `credential.status=active`, `student_id=User._id` e `identity` mínima. Un 422 devuelve `ok=false` e identidad nula: `not_found`, `revoked`, `blocked`, `suspended` o `replaced`; para los tres estados explícitos se informa `credential.status`, pero nunca el titular. Sin token: 401; sin scope NFC: 403. Consulte el esquema completo en [Team 1](TEAM-1-INTEGRATION.md).

La nueva tarjeta reemplazante hereda el estado operativo y la anterior queda terminal como `replaced`. La validación NFC no consume ni cambia la tarjeta. **Identificación no autoriza** automáticamente préstamo, reserva, acceso físico ni beneficio; aplique las reglas de Equipo 5. No lea `nfc_cards` ni use `identity.credential.changed.v1` como lookup de titularidad o elegibilidad. La consulta de permisos contextuales es un contrato futuro distinto.
