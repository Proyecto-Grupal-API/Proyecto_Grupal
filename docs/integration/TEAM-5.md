# Equipo 5 — QR disponible; NFC pendiente

Integre sobre su código existente. La referencia contractual es [Team 1](TEAM-1-INTEGRATION.md).

## Flujo QR disponible

1. Configure un cliente de servicio autorizado para `identity:qr:validate` y obtenga Bearer por `POST /api/oauth/token` con `client_credentials`.
2. Reciba el QR/código presentado en la operación de Equipo 5. Envíe `POST /api/v1/identity/qr-validate` con JSON `{"code":"{PRESENTED_QR_OR_CODE}","context":"{OPERATION_LABEL}"}`. `context` es opcional y de auditoría.
3. Con `200`, lea `ok=true`, `result=valid` e `identity` (`user_id`, `name`, `student`). Aplique **sus** reglas de acceso/beneficio. Con `422`, trate el QR como no utilizable; 401/403 son problemas de autenticación/scope.

El QR dinámico es single-use; el de identificación es reutilizable mientras esté vigente. No almacene ni consulte `qr_tokens` para validar, ni replique firma, short-code o hashes. No consulte `qr_validations` como bus de integración.

## Dependencia NFC aún no disponible

Existen registro y ciclo ordinario **web** NFC, pero **no existe API interequipos de validación NFC** ni reemplazo real old→new. No leer `nfc_cards`, no usar `identity.credential.changed.v1` como lookup del titular actual y no crear una API paralela de identidad. Si el servicio de Equipo 5 necesita validar UID antes de avanzar, reporte esa dependencia a Team 1 y detenga sólo la parte NFC.
