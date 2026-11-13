# Referencia de API por endpoint

| Versión | Fecha | Responsables | Cambio |
|---|---|---|---|
| 1.0 | 2026-11-13 | Integración MERGE_COMPLETO; con Claude Code | Generada desde `route:list` del laboratorio y las reglas de validación reales de cada controlador/FormRequest |

488 endpoints bajo `/api` (343 versionados en `/api/v1`). Formato máquina: `api-referencia.json`. Para cada endpoint: parámetros de ruta, cuerpo/consulta aceptados (reglas Laravel), respuestas de éxito y errores posibles derivados de su middleware y de su código. Los ejemplos ejecutados están en la colección Postman verificada (`docs/postman/`).

**Convenciones de error**: 401 sin sesión o credencial de servicio; 403 sin permiso; 404 recurso inexistente o ajeno (no se revela existencia); 409 conflicto de estado o reintento con datos distintos; 422 validación con cuerpo `{"message": "...", "errors": {"campo": ["mensaje"]}}` (mensajes en español); 429 límite; 503 dependencia no disponible, sin diagnósticos internos.

## E1 Identidad (17)

### `GET /api/v1/identity/assignments`
- Acción: `Api\Identity\BusinessAuthorizationController@assignments`
- Request:
  - `scope_type`: ['in:business']
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso; 422 validación

### `POST /api/v1/identity/authorization/check`
- Acción: `Api\Identity\BusinessAuthorizationController@check`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso

### `POST /api/v1/identity/business-owner/provision`
- Acción: `Api\Identity\BusinessAuthorizationController@provision`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso

### `POST /api/v1/identity/directory/batch`
- Acción: `Api\Identity\IdentityDirectoryController@batch`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida

### `POST /api/v1/identity/directory/resolve-enrollment`
- Acción: `Api\Identity\IdentityDirectoryController@resolve`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida

### `POST /api/v1/identity/financial-authorization/check`
- Acción: `Api\Identity\FinancialAuthorizationController@check`
- Request (`FinancialAuthorizationRequest`):
  - `subject_id`: ['required', 'string', 'max:100', 'regex:/^[^\x00-\x20\x7f]
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso; 422 validación; 503 dependencia no disponible

### `POST /api/v1/identity/nfc-validate`
- Acción: `Api\Identity\NfcValidationController`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 422 validación

### `POST /api/v1/identity/qr-validate`
- Acción: `Api\Identity\QrValidationController`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 422 validación

### `POST /api/v1/oauth/token`
- Acción: `OAuthTokenController`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: ninguno específico además de 500 genérico sanitizado

### `GET /api/v1/students/{studentId}/consents`
- Acción: `StudentServicesController@consents`
- Parámetros de ruta: `studentId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/v1/students/{studentId}/consents`
- Acción: `StudentServicesController@acceptConsent`
- Parámetros de ruta: `studentId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `DELETE /api/v1/students/{studentId}/consents/{consentId}`
- Acción: `StudentServicesController@revokeConsent`
- Parámetros de ruta: `studentId`, `consentId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/v1/students/{studentId}/preferences`
- Acción: `StudentServicesController@preferences`
- Parámetros de ruta: `studentId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `PATCH /api/v1/students/{studentId}/preferences`
- Acción: `StudentServicesController@updatePreferences`
- Parámetros de ruta: `studentId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/v1/students/{studentId}/status`
- Acción: `StudentServicesController@status`
- Parámetros de ruta: `studentId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `PATCH /api/v1/students/{studentId}/status`
- Acción: `StudentServicesController@changeStatus`
- Parámetros de ruta: `studentId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `GET /api/v1/students/{studentId}/status/history`
- Acción: `StudentServicesController@statusHistory`
- Parámetros de ruta: `studentId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

## E2 Finanzas (99)

### `GET /api/v1/financial/alerts`
- Acción: `Financial\TransactionAlertController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida

### `GET /api/v1/financial/alerts/{alertId}`
- Acción: `Financial\TransactionAlertController@show`
- Parámetros de ruta: `alertId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/alerts/{alertId}/status`
- Acción: `Financial\TransactionAlertController@updateStatus`
- Parámetros de ruta: `alertId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/bonuses/{bonusId}`
- Acción: `Financial\BonusController@show`
- Parámetros de ruta: `bonusId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/cash/associations/{associationId}/administrative-approval-requests`
- Acción: `Financial\CashApprovalController@administrativeQueue`
- Parámetros de ruta: `associationId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/cash/associations/{associationId}/approval-policies`
- Acción: `Financial\CashApprovalController@policies`
- Parámetros de ruta: `associationId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/cash/associations/{associationId}/approval-policies`
- Acción: `Financial\CashApprovalController@configurePolicy`
- Parámetros de ruta: `associationId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/cash/associations/{associationId}/approval-policies/history`
- Acción: `Financial\CashApprovalController@policyHistory`
- Parámetros de ruta: `associationId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/cash/associations/{associationId}/approval-requests`
- Acción: `Financial\CashApprovalController@reviewQueue`
- Parámetros de ruta: `associationId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/cash/associations/{associationId}/receipts/{receiptId}`
- Acción: `Financial\CashController@receipt`
- Parámetros de ruta: `associationId`, `receiptId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/cash/associations/{associationId}/registers`
- Acción: `Financial\CashController@registers`
- Parámetros de ruta: `associationId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/cash/associations/{associationId}/registers`
- Acción: `Financial\CashRegisterAdministrationController@storeRegister`
- Parámetros de ruta: `associationId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `PATCH /api/v1/financial/cash/associations/{associationId}/registers/{registerId}`
- Acción: `Financial\CashRegisterAdministrationController@updateRegister`
- Parámetros de ruta: `associationId`, `registerId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/cash/associations/{associationId}/registers/{registerId}/history`
- Acción: `Financial\CashRegisterAdministrationController@registerHistory`
- Parámetros de ruta: `associationId`, `registerId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/cash/associations/{associationId}/registers/{registerId}/shifts`
- Acción: `Financial\CashController@shifts`
- Parámetros de ruta: `associationId`, `registerId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/cash/associations/{associationId}/registers/{registerId}/shifts`
- Acción: `Financial\CashController@open`
- Parámetros de ruta: `associationId`, `registerId`
- Request:
  - `opening_amount_cents`: ['required', 'integer', 'min:0']
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/cash/associations/{associationId}/shifts/{shiftId}`
- Acción: `Financial\CashController@show`
- Parámetros de ruta: `associationId`, `shiftId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/cash/associations/{associationId}/shifts/{shiftId}/adjustments`
- Acción: `Financial\CashController@adjust`
- Parámetros de ruta: `associationId`, `shiftId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/cash/associations/{associationId}/shifts/{shiftId}/administrative-requests`
- Acción: `Financial\CashAdministrativeRequestController@storeAdministrative`
- Parámetros de ruta: `associationId`, `shiftId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/cash/associations/{associationId}/shifts/{shiftId}/administrative-requests/{administrativeId}`
- Acción: `Financial\CashAdministrativeRequestController@showAdministrative`
- Parámetros de ruta: `associationId`, `shiftId`, `administrativeId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/cash/associations/{associationId}/shifts/{shiftId}/administrative-requests/{administrativeId}/cancel`
- Acción: `Financial\CashAdministrativeRequestController@cancelAdministrative`
- Parámetros de ruta: `associationId`, `shiftId`, `administrativeId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/cash/associations/{associationId}/shifts/{shiftId}/close`
- Acción: `Financial\CashController@close`
- Parámetros de ruta: `associationId`, `shiftId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/cash/associations/{associationId}/shifts/{shiftId}/confirmations`
- Acción: `Financial\CashOperationConfirmationController@store`
- Parámetros de ruta: `associationId`, `shiftId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/cash/associations/{associationId}/shifts/{shiftId}/confirmations/{confirmationId}`
- Acción: `Financial\CashOperationConfirmationController@showConfirmation`
- Parámetros de ruta: `associationId`, `shiftId`, `confirmationId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/cash/associations/{associationId}/shifts/{shiftId}/confirmations/{confirmationId}/cancel`
- Acción: `Financial\CashOperationConfirmationController@cancel`
- Parámetros de ruta: `associationId`, `shiftId`, `confirmationId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/cash/associations/{associationId}/shifts/{shiftId}/movements`
- Acción: `Financial\CashController@movements`
- Parámetros de ruta: `associationId`, `shiftId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/cash/associations/{associationId}/shifts/{shiftId}/movements`
- Acción: `Financial\CashController@move`
- Parámetros de ruta: `associationId`, `shiftId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/cash/associations/{associationId}/shifts/{shiftId}/topups`
- Acción: `Financial\CashController@topUp`
- Parámetros de ruta: `associationId`, `shiftId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/cash/associations/{associationId}/shifts/{shiftId}/withdrawal-refunds/{refundId}/recover`
- Acción: `Financial\CashWithdrawalRecoveryController@recover`
- Parámetros de ruta: `associationId`, `shiftId`, `refundId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/cash/associations/{associationId}/shifts/{shiftId}/withdrawals`
- Acción: `Financial\CashController@withdraw`
- Parámetros de ruta: `associationId`, `shiftId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/cash/associations/{associationId}/withdrawal-refunds`
- Acción: `Financial\CashWithdrawalRecoveryController@index`
- Parámetros de ruta: `associationId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/community/becas/{solicitud}/issuance`
- Acción: `Financial\CommunityBecaController@receipt`
- Parámetros de ruta: `solicitud`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/community/event-reservations/{registroId}/generations/{generation}/payment`
- Acción: `Financial\EventCheckoutController@lookup`
- Parámetros de ruta: `registroId`, `generation`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/community/event-reservations/{registroId}/generations/{generation}/payment`
- Acción: `Financial\EventCheckoutController@prepare`
- Parámetros de ruta: `registroId`, `generation`
- Request:
  - `currency`: required · in:MXN
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `POST /api/v1/financial/community/event-reservations/{registroId}/generations/{generation}/payment/cancel`
- Acción: `Financial\EventCheckoutController@cancel`
- Parámetros de ruta: `registroId`, `generation`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/hold-policies`
- Acción: `Financial\WalletHoldPolicyController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida

### `POST /api/v1/financial/hold-policies`
- Acción: `Financial\WalletHoldPolicyController@store`
- Request:
  - `operation_type`: ['required', 'string', 'max:100', 'regex:/^[A-Z]
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 422 validación

### `GET /api/v1/financial/hold-policies/{policyId}`
- Acción: `Financial\WalletHoldPolicyController@show`
- Parámetros de ruta: `policyId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `PATCH /api/v1/financial/hold-policies/{policyId}`
- Acción: `Financial\WalletHoldPolicyController@update`
- Parámetros de ruta: `policyId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/hold-policies/{policyId}/history`
- Acción: `Financial\WalletHoldPolicyController@history`
- Parámetros de ruta: `policyId`
- Request:
  - `per_page`: ['nullable', 'integer', 'min:1', 'max:200']
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/holds`
- Acción: `Financial\WalletHoldController@store`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/holds/{holdId}`
- Acción: `Financial\WalletHoldController@show`
- Parámetros de ruta: `holdId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/holds/{holdId}/capture`
- Acción: `Financial\WalletHoldController@capture`
- Parámetros de ruta: `holdId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/holds/{holdId}/release`
- Acción: `Financial\WalletHoldController@release`
- Parámetros de ruta: `holdId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/limits`
- Acción: `Financial\FinancialLimitController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida

### `POST /api/v1/financial/limits`
- Acción: `Financial\FinancialLimitController@store`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida

### `POST /api/v1/financial/limits/evaluate`
- Acción: `Financial\FinancialLimitController@evaluate`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/limits/{limitId}`
- Acción: `Financial\FinancialLimitController@show`
- Parámetros de ruta: `limitId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `PATCH /api/v1/financial/limits/{limitId}`
- Acción: `Financial\FinancialLimitController@update`
- Parámetros de ruta: `limitId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/limits/{limitId}/history`
- Acción: `Financial\FinancialLimitController@history`
- Parámetros de ruta: `limitId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/marketplace/payment-requests`
- Acción: `Financial\MarketplacePaymentRequestController@prepare`
- Request:
  - `buyer_id`: required · string · regex:/^[a-fA-F0-9]{24}$/
  - `order_id`: required · string · max:100
  - `business_id`: required · string · max:100
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 422 validación; 409 conflicto de estado o idempotencia; 503 dependencia no disponible

### `GET /api/v1/financial/marketplace/payment-requests/by-order`
- Acción: `Financial\MarketplacePaymentRequestController@lookup`
- Request:
  - `order_id`: required · string · max:100
  - `business_id`: required · string · max:100
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 422 validación

### `POST /api/v1/financial/marketplace/payment-requests/by-order/cancel`
- Acción: `Financial\MarketplacePaymentRequestController@cancel`
- Request:
  - `order_id`: required · string · max:100
  - `business_id`: required · string · max:100
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 422 validación; 503 dependencia no disponible

### `POST /api/v1/financial/purchase-refunds`
- Acción: `Financial\PurchaseRefundController@store`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/purchase-refunds/{refundId}`
- Acción: `Financial\PurchaseRefundController@show`
- Parámetros de ruta: `refundId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/purchase-refunds/{refundId}/approve`
- Acción: `Financial\PurchaseRefundController@approve`
- Parámetros de ruta: `refundId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/purchase-refunds/{refundId}/complete`
- Acción: `Financial\PurchaseRefundController@complete`
- Parámetros de ruta: `refundId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/purchase-refunds/{refundId}/reject`
- Acción: `Financial\PurchaseRefundController@reject`
- Parámetros de ruta: `refundId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/receipts`
- Acción: `Financial\PersonalReceiptApiController@index` · nombre `financial.receipts.api.index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida

### `GET /api/v1/financial/receipts/{source}/{operationId}`
- Acción: `Financial\PersonalReceiptApiController@show` · nombre `financial.receipts.api.show`
- Parámetros de ruta: `source`, `operationId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/reconciliations`
- Acción: `Financial\ReconciliationController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida

### `POST /api/v1/financial/reconciliations`
- Acción: `Financial\ReconciliationController@store`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida

### `GET /api/v1/financial/reconciliations/{reconciliationId}`
- Acción: `Financial\ReconciliationController@show`
- Parámetros de ruta: `reconciliationId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/reconciliations/{reconciliationId}/differences`
- Acción: `Financial\ReconciliationController@differences`
- Parámetros de ruta: `reconciliationId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/reconciliations/{reconciliationId}/differences/{differenceId}/resolve`
- Acción: `Financial\ReconciliationController@resolveDifference`
- Parámetros de ruta: `reconciliationId`, `differenceId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/refunds`
- Acción: `Financial\RefundController@store`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `GET /api/v1/financial/refunds/{refundId}`
- Acción: `Financial\RefundController@show`
- Parámetros de ruta: `refundId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/refunds/{refundId}/approve`
- Acción: `Financial\RefundController@approve`
- Parámetros de ruta: `refundId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `POST /api/v1/financial/refunds/{refundId}/complete`
- Acción: `Financial\RefundController@complete`
- Parámetros de ruta: `refundId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `POST /api/v1/financial/refunds/{refundId}/recover`
- Acción: `Financial\RefundController@recoverWithdrawal`
- Parámetros de ruta: `refundId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `POST /api/v1/financial/refunds/{refundId}/reject`
- Acción: `Financial\RefundController@reject`
- Parámetros de ruta: `refundId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `GET /api/v1/financial/reversals/{reversalId}`
- Acción: `Financial\ReversalController@show`
- Parámetros de ruta: `reversalId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/student-services/library-fines/{fine}`
- Acción: `Financial\LibraryFineCheckoutController@lookup`
- Parámetros de ruta: `fine`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/student-services/library-fines/{fine}`
- Acción: `Financial\LibraryFineCheckoutController@prepare`
- Parámetros de ruta: `fine`
- Request:
  - `student_id`: required · string · regex:/\A[a-f0-9]{24}\z/
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 422 validación; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `POST /api/v1/financial/student-services/library-fines/{fine}/cancel`
- Acción: `Financial\LibraryFineCheckoutController@cancel`
- Parámetros de ruta: `fine`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/student-services/payments/{concept}/{source}`
- Acción: `Financial\StudentServiceCheckoutController@lookup`
- Parámetros de ruta: `concept`, `source`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/student-services/payments/{concept}/{source}`
- Acción: `Financial\StudentServiceCheckoutController@prepare`
- Parámetros de ruta: `concept`, `source`
- Request:
  - `student_id`: required · string · regex:/\A[a-f0-9]{24}\z/
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/student-services/payments/{concept}/{source}/cancel`
- Acción: `Financial\StudentServiceCheckoutController@cancel`
- Parámetros de ruta: `concept`, `source`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/student-services/rentals/{rental}/deposit`
- Acción: `Financial\RentalDepositPilotController@lookup`
- Parámetros de ruta: `rental`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/student-services/rentals/{rental}/deposit`
- Acción: `Financial\RentalDepositPilotController@hold`
- Parámetros de ruta: `rental`
- Request:
  - `student_id`: ['required', 'string', 'regex:/^[a-f0-9]
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/student-services/rentals/{rental}/deposit/{hold}/settle`
- Acción: `Financial\RentalDepositPilotController@settle`
- Parámetros de ruta: `rental`, `hold`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/student-transfers/confirmations`
- Acción: `Financial\StudentTransferApiController@pendingConfirmations`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida

### `POST /api/v1/financial/student-transfers/confirmations`
- Acción: `Financial\StudentTransferApiController@prepare`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida

### `GET /api/v1/financial/student-transfers/confirmations/{confirmationId}`
- Acción: `Financial\StudentTransferApiController@showConfirmation`
- Parámetros de ruta: `confirmationId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/student-transfers/confirmations/{confirmationId}/cancel`
- Acción: `Financial\StudentTransferApiController@cancel`
- Parámetros de ruta: `confirmationId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/student-transfers/confirmations/{confirmationId}/confirm`
- Acción: `Financial\StudentTransferApiController@confirm`
- Parámetros de ruta: `confirmationId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/student-transfers/policy`
- Acción: `Financial\StudentTransferApiController@currentPolicy`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida

### `PATCH /api/v1/financial/student-transfers/policy`
- Acción: `Financial\StudentTransferApiController@updatePolicy`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida

### `GET /api/v1/financial/student-transfers/policy/history`
- Acción: `Financial\StudentTransferApiController@policyHistory`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida

### `GET /api/v1/financial/student-transfers/records`
- Acción: `Financial\StudentTransferApiController@listing`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida

### `GET /api/v1/financial/student-transfers/{transferId}`
- Acción: `Financial\StudentTransferApiController@show`
- Parámetros de ruta: `transferId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/topups`
- Acción: `Financial\TopUpController@store`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia; 503 dependencia no disponible

### `GET /api/v1/financial/topups/{topUpId}`
- Acción: `Financial\TopUpController@show`
- Parámetros de ruta: `topUpId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/topups/{topUpId}/complete`
- Acción: `Financial\TopUpController@complete`
- Parámetros de ruta: `topUpId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 422 validación; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `POST /api/v1/financial/transactions/{transactionId}/reverse`
- Acción: `Financial\ReversalController@store`
- Parámetros de ruta: `transactionId`
- Request:
  - `reason`: ['required', 'string', 'max:1000', 'regex:/\S/u']
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/wallets/{walletId}`
- Acción: `Financial\WalletController@show`
- Parámetros de ruta: `walletId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `GET /api/v1/financial/wallets/{walletId}/ledger`
- Acción: `Financial\WalletController@ledger`
- Parámetros de ruta: `walletId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

### `POST /api/v1/financial/withdrawals`
- Acción: `Financial\WithdrawalController@store`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia; 503 dependencia no disponible

### `GET /api/v1/financial/withdrawals/{withdrawalId}`
- Acción: `Financial\WithdrawalController@show`
- Parámetros de ruta: `withdrawalId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 404 recurso inexistente o ajeno

## E3 Marketplace (4)

### `GET /api/tienda/catalogo`
- Acción: `Marketplace\MarketplaceController@catalog` · nombre `api.marketplace.catalog`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `GET /api/tienda/negocios`
- Acción: `Marketplace\MarketplaceController@businessesApi` · nombre `api.marketplace.businesses`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `GET /api/v1/marketplace/businesses`
- Acción: `Marketplace\MarketplaceController@businessesApi`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso

### `GET /api/v1/marketplace/catalog`
- Acción: `Marketplace\MarketplaceController@catalog`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso

## E4 Inventario (59)

### `POST /api/equipo4/businesses/{businessId}/integration/availability`
- Acción: `Api\InventoryModule\Team3IntegrationController@availability`
- Parámetros de ruta: `businessId`
- Request:
  - `items`: required · array · min:1 · max:100
  - `items.*.product_id`: required · string
  - `items.*.variant_id`: nullable · string
  - `items.*.location_id`: nullable · string
  - `items.*.quantity`: required · integer · min:1 · max:100000
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/equipo4/businesses/{businessId}/integration/reservation-status`
- Acción: `Api\InventoryModule\MarketplaceBridgeController@operation`
- Parámetros de ruta: `businessId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/equipo4/businesses/{businessId}/integration/reservations`
- Acción: `Api\InventoryModule\Team3IntegrationController@reserve`
- Parámetros de ruta: `businessId`
- Request:
  - `order_id`: required · string · max:100
  - `items`: required · array · min:1 · max:100
  - `items.*.product_id`: required · string
  - `items.*.variant_id`: nullable · string
  - `items.*.location_id`: nullable · string
  - `items.*.quantity`: required · integer · min:1 · max:100000
  - `idempotency_key`: required · string · min:8 · max:128
  - `source`: nullable · string · in:CHECKOUT,REWARD,CANJE,ORDER
  - `external_reference`: nullable · string · max:200
  - `expires_at`: nullable · date · after:now
  - `actor_id`: nullable · string · max:100
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `GET /api/equipo4/businesses/{businessId}/integration/reservations/{id}`
- Acción: `Api\InventoryModule\Team3IntegrationController@show`
- Parámetros de ruta: `businessId`, `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/equipo4/businesses/{businessId}/integration/reservations/{id}/confirm`
- Acción: `Api\InventoryModule\Team3IntegrationController@confirm`
- Parámetros de ruta: `businessId`, `id`
- Request:
  - `order_id`: required · string · max:100
  - `payment_intent_id`: required · string · max:100
  - `idempotency_key`: required · string · min:8 · max:128
  - `actor_id`: nullable · string · max:100
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `POST /api/equipo4/businesses/{businessId}/integration/reservations/{id}/release`
- Acción: `Api\InventoryModule\Team3IntegrationController@release`
- Parámetros de ruta: `businessId`, `id`
- Request:
  - `order_id`: required · string · max:100
  - `reason`: nullable · string · max:200
  - `idempotency_key`: required · string · min:8 · max:128
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `GET /api/equipo4/businesses/{businessId}/inventory`
- Acción: `InventoryModule\Team4Controller@inventory`
- Parámetros de ruta: `businessId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 404 recurso inexistente o ajeno

### `GET /api/equipo4/businesses/{businessId}/locations`
- Acción: `InventoryModule\Team4Controller@locations`
- Parámetros de ruta: `businessId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 404 recurso inexistente o ajeno

### `GET /api/equipo4/businesses/{businessId}/products`
- Acción: `InventoryModule\Team4Controller@products`
- Parámetros de ruta: `businessId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 404 recurso inexistente o ajeno

### `GET /api/equipo4/businesses/{businessId}/warehouses`
- Acción: `InventoryModule\Team4Controller@warehouses`
- Parámetros de ruta: `businessId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/equipo4/integration/adjustments`
- Acción: `Api\InventoryModule\InventoryAdjustmentController@receive`
- Request:
  - `product_id`: required · string
  - `variant_id`: nullable · string
  - `location_id`: required · string
  - `new_quantity`: nullable · integer · min:0
  - `delta`: nullable · integer
  - `reason`: required · string · max:200
  - `idempotency_key`: required · string · min:8 · max:128
  - `actor_id`: nullable · string · max:100
- Respuesta: 200, 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 422 validación; 409 conflicto de estado o idempotencia

### `POST /api/equipo4/integration/availability`
- Acción: `Api\InventoryModule\Team3IntegrationController@availability`
- Request:
  - `items`: required · array · min:1 · max:100
  - `items.*.product_id`: required · string
  - `items.*.variant_id`: nullable · string
  - `items.*.location_id`: nullable · string
  - `items.*.quantity`: required · integer · min:1 · max:100000
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 422 validación

### `POST /api/equipo4/integration/businesses`
- Acción: `Api\InventoryModule\MarketplaceBridgeController@register`
- Request:
  - `business_id`: ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 422 validación; 409 conflicto de estado o idempotencia

### `POST /api/equipo4/integration/reservations`
- Acción: `Api\InventoryModule\Team3IntegrationController@reserve`
- Request:
  - `order_id`: required · string · max:100
  - `items`: required · array · min:1 · max:100
  - `items.*.product_id`: required · string
  - `items.*.variant_id`: nullable · string
  - `items.*.location_id`: nullable · string
  - `items.*.quantity`: required · integer · min:1 · max:100000
  - `idempotency_key`: required · string · min:8 · max:128
  - `source`: nullable · string · in:CHECKOUT,REWARD,CANJE,ORDER
  - `external_reference`: nullable · string · max:200
  - `expires_at`: nullable · date · after:now
  - `actor_id`: nullable · string · max:100
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 422 validación; 409 conflicto de estado o idempotencia

### `GET /api/equipo4/integration/reservations/{id}`
- Acción: `Api\InventoryModule\Team3IntegrationController@show`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 404 recurso inexistente o ajeno

### `POST /api/equipo4/integration/reservations/{id}/confirm`
- Acción: `Api\InventoryModule\Team3IntegrationController@confirm`
- Parámetros de ruta: `id`
- Request:
  - `order_id`: required · string · max:100
  - `payment_intent_id`: required · string · max:100
  - `idempotency_key`: required · string · min:8 · max:128
  - `actor_id`: nullable · string · max:100
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 422 validación; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `POST /api/equipo4/integration/reservations/{id}/release`
- Acción: `Api\InventoryModule\Team3IntegrationController@release`
- Parámetros de ruta: `id`
- Request:
  - `order_id`: required · string · max:100
  - `reason`: nullable · string · max:200
  - `idempotency_key`: required · string · min:8 · max:128
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 422 validación; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `POST /api/equipo4/integration/returns`
- Acción: `Api\InventoryModule\InventoryReturnController@receive`
- Request:
  - `order_id`: required · string · max:100
  - `refund_reference`: nullable · string · max:100
  - `reason`: nullable · string · max:200
  - `idempotency_key`: required · string · min:8 · max:128
  - `items`: required · array · min:1 · max:100
  - `items.*.product_id`: required · string
  - `items.*.variant_id`: nullable · string
  - `items.*.location_id`: required · string
  - `items.*.quantity`: required · integer · min:1 · max:100000
- Respuesta: 200, 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 422 validación; 409 conflicto de estado o idempotencia

### `POST /api/equipo4/integration/transfers`
- Acción: `Api\InventoryModule\InventoryTransferController@receive`
- Request:
  - `from_location_id`: required · string
  - `to_location_id`: required · string · different:from_location_id
  - `reason`: nullable · string · max:200
  - `idempotency_key`: required · string · min:8 · max:128
  - `items`: required · array · min:1 · max:100
  - `items.*.product_id`: required · string
  - `items.*.variant_id`: nullable · string
  - `items.*.quantity`: required · integer · min:1 · max:100000
- Respuesta: 200, 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 422 validación; 409 conflicto de estado o idempotencia

### `GET /api/equipo4/inventory`
- Acción: `InventoryModule\Team4Controller@inventory`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado)

### `GET /api/equipo4/locations`
- Acción: `InventoryModule\Team4Controller@locations`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado)

### `GET /api/equipo4/movements`
- Acción: `InventoryModule\Team4Controller@movements`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado)

### `GET /api/equipo4/products`
- Acción: `InventoryModule\Team4Controller@products`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado)

### `GET /api/equipo4/purchase-orders`
- Acción: `InventoryModule\Team4Controller@purchaseOrders`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado)

### `GET /api/equipo4/suppliers`
- Acción: `InventoryModule\Team4Controller@suppliers`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado)

### `GET /api/equipo4/warehouses`
- Acción: `InventoryModule\Team4Controller@warehouses`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado)

### `POST /api/equipo4/webhooks/payment-status`
- Acción: `Api\InventoryModule\PaymentWebhookController@receive`
- Request:
  - `event_id`: required · string · max:100
  - `event_version`: required · integer · min:1 · max:2147483647
  - `payment_intent_id`: required · string · max:100
- Respuesta: 202 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 422 validación; 409 conflicto de estado o idempotencia; 503 dependencia no disponible

### `GET|POST /api/v1/inventory/availability`
- Acción: `Api\InventoryModule\Team7ApiController@availability`
- Request:
  - `business_id`: nullable · string · max:100
  - `product_reference`: required · string
  - `variant_sku`: nullable · string
  - `location_id`: nullable · string
  - `quantity`: nullable · integer · min:1 · max:100000
  - `items`: required · array · min:1 · max:100
  - `items.*.product_reference`: required · string
  - `items.*.variant_sku`: nullable · string
  - `items.*.location_id`: nullable · string
  - `items.*.quantity`: required · integer · min:1 · max:1000
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 422 validación; 404 recurso inexistente o ajeno

### `POST /api/v1/inventory/equipo4/businesses/{businessId}/integration/availability`
- Acción: `Api\InventoryModule\Team3IntegrationController@availability`
- Parámetros de ruta: `businessId`
- Request:
  - `items`: required · array · min:1 · max:100
  - `items.*.product_id`: required · string
  - `items.*.variant_id`: nullable · string
  - `items.*.location_id`: nullable · string
  - `items.*.quantity`: required · integer · min:1 · max:100000
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/v1/inventory/equipo4/businesses/{businessId}/integration/reservation-status`
- Acción: `Api\InventoryModule\MarketplaceBridgeController@operation`
- Parámetros de ruta: `businessId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/v1/inventory/equipo4/businesses/{businessId}/integration/reservations`
- Acción: `Api\InventoryModule\Team3IntegrationController@reserve`
- Parámetros de ruta: `businessId`
- Request:
  - `order_id`: required · string · max:100
  - `items`: required · array · min:1 · max:100
  - `items.*.product_id`: required · string
  - `items.*.variant_id`: nullable · string
  - `items.*.location_id`: nullable · string
  - `items.*.quantity`: required · integer · min:1 · max:100000
  - `idempotency_key`: required · string · min:8 · max:128
  - `source`: nullable · string · in:CHECKOUT,REWARD,CANJE,ORDER
  - `external_reference`: nullable · string · max:200
  - `expires_at`: nullable · date · after:now
  - `actor_id`: nullable · string · max:100
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `GET /api/v1/inventory/equipo4/businesses/{businessId}/integration/reservations/{id}`
- Acción: `Api\InventoryModule\Team3IntegrationController@show`
- Parámetros de ruta: `businessId`, `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/v1/inventory/equipo4/businesses/{businessId}/integration/reservations/{id}/confirm`
- Acción: `Api\InventoryModule\Team3IntegrationController@confirm`
- Parámetros de ruta: `businessId`, `id`
- Request:
  - `order_id`: required · string · max:100
  - `payment_intent_id`: required · string · max:100
  - `idempotency_key`: required · string · min:8 · max:128
  - `actor_id`: nullable · string · max:100
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `POST /api/v1/inventory/equipo4/businesses/{businessId}/integration/reservations/{id}/release`
- Acción: `Api\InventoryModule\Team3IntegrationController@release`
- Parámetros de ruta: `businessId`, `id`
- Request:
  - `order_id`: required · string · max:100
  - `reason`: nullable · string · max:200
  - `idempotency_key`: required · string · min:8 · max:128
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `GET /api/v1/inventory/equipo4/businesses/{businessId}/inventory`
- Acción: `InventoryModule\Team4Controller@inventory`
- Parámetros de ruta: `businessId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 404 recurso inexistente o ajeno

### `GET /api/v1/inventory/equipo4/businesses/{businessId}/locations`
- Acción: `InventoryModule\Team4Controller@locations`
- Parámetros de ruta: `businessId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 404 recurso inexistente o ajeno

### `GET /api/v1/inventory/equipo4/businesses/{businessId}/products`
- Acción: `InventoryModule\Team4Controller@products`
- Parámetros de ruta: `businessId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 404 recurso inexistente o ajeno

### `GET /api/v1/inventory/equipo4/businesses/{businessId}/warehouses`
- Acción: `InventoryModule\Team4Controller@warehouses`
- Parámetros de ruta: `businessId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/v1/inventory/equipo4/integration/adjustments`
- Acción: `Api\InventoryModule\InventoryAdjustmentController@receive`
- Request:
  - `product_id`: required · string
  - `variant_id`: nullable · string
  - `location_id`: required · string
  - `new_quantity`: nullable · integer · min:0
  - `delta`: nullable · integer
  - `reason`: required · string · max:200
  - `idempotency_key`: required · string · min:8 · max:128
  - `actor_id`: nullable · string · max:100
- Respuesta: 200, 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 422 validación; 409 conflicto de estado o idempotencia

### `POST /api/v1/inventory/equipo4/integration/availability`
- Acción: `Api\InventoryModule\Team3IntegrationController@availability`
- Request:
  - `items`: required · array · min:1 · max:100
  - `items.*.product_id`: required · string
  - `items.*.variant_id`: nullable · string
  - `items.*.location_id`: nullable · string
  - `items.*.quantity`: required · integer · min:1 · max:100000
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 422 validación

### `POST /api/v1/inventory/equipo4/integration/businesses`
- Acción: `Api\InventoryModule\MarketplaceBridgeController@register`
- Request:
  - `business_id`: ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 422 validación; 409 conflicto de estado o idempotencia

### `POST /api/v1/inventory/equipo4/integration/reservations`
- Acción: `Api\InventoryModule\Team3IntegrationController@reserve`
- Request:
  - `order_id`: required · string · max:100
  - `items`: required · array · min:1 · max:100
  - `items.*.product_id`: required · string
  - `items.*.variant_id`: nullable · string
  - `items.*.location_id`: nullable · string
  - `items.*.quantity`: required · integer · min:1 · max:100000
  - `idempotency_key`: required · string · min:8 · max:128
  - `source`: nullable · string · in:CHECKOUT,REWARD,CANJE,ORDER
  - `external_reference`: nullable · string · max:200
  - `expires_at`: nullable · date · after:now
  - `actor_id`: nullable · string · max:100
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 422 validación; 409 conflicto de estado o idempotencia

### `GET /api/v1/inventory/equipo4/integration/reservations/{id}`
- Acción: `Api\InventoryModule\Team3IntegrationController@show`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 404 recurso inexistente o ajeno

### `POST /api/v1/inventory/equipo4/integration/reservations/{id}/confirm`
- Acción: `Api\InventoryModule\Team3IntegrationController@confirm`
- Parámetros de ruta: `id`
- Request:
  - `order_id`: required · string · max:100
  - `payment_intent_id`: required · string · max:100
  - `idempotency_key`: required · string · min:8 · max:128
  - `actor_id`: nullable · string · max:100
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 422 validación; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `POST /api/v1/inventory/equipo4/integration/reservations/{id}/release`
- Acción: `Api\InventoryModule\Team3IntegrationController@release`
- Parámetros de ruta: `id`
- Request:
  - `order_id`: required · string · max:100
  - `reason`: nullable · string · max:200
  - `idempotency_key`: required · string · min:8 · max:128
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 422 validación; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `POST /api/v1/inventory/equipo4/integration/returns`
- Acción: `Api\InventoryModule\InventoryReturnController@receive`
- Request:
  - `order_id`: required · string · max:100
  - `refund_reference`: nullable · string · max:100
  - `reason`: nullable · string · max:200
  - `idempotency_key`: required · string · min:8 · max:128
  - `items`: required · array · min:1 · max:100
  - `items.*.product_id`: required · string
  - `items.*.variant_id`: nullable · string
  - `items.*.location_id`: required · string
  - `items.*.quantity`: required · integer · min:1 · max:100000
- Respuesta: 200, 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 422 validación; 409 conflicto de estado o idempotencia

### `POST /api/v1/inventory/equipo4/integration/transfers`
- Acción: `Api\InventoryModule\InventoryTransferController@receive`
- Request:
  - `from_location_id`: required · string
  - `to_location_id`: required · string · different:from_location_id
  - `reason`: nullable · string · max:200
  - `idempotency_key`: required · string · min:8 · max:128
  - `items`: required · array · min:1 · max:100
  - `items.*.product_id`: required · string
  - `items.*.variant_id`: nullable · string
  - `items.*.quantity`: required · integer · min:1 · max:100000
- Respuesta: 200, 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 422 validación; 409 conflicto de estado o idempotencia

### `GET /api/v1/inventory/equipo4/inventory`
- Acción: `InventoryModule\Team4Controller@inventory`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado)

### `GET /api/v1/inventory/equipo4/locations`
- Acción: `InventoryModule\Team4Controller@locations`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado)

### `GET /api/v1/inventory/equipo4/movements`
- Acción: `InventoryModule\Team4Controller@movements`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado)

### `GET /api/v1/inventory/equipo4/products`
- Acción: `InventoryModule\Team4Controller@products`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado)

### `GET /api/v1/inventory/equipo4/purchase-orders`
- Acción: `InventoryModule\Team4Controller@purchaseOrders`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado)

### `GET /api/v1/inventory/equipo4/suppliers`
- Acción: `InventoryModule\Team4Controller@suppliers`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado)

### `GET /api/v1/inventory/equipo4/warehouses`
- Acción: `InventoryModule\Team4Controller@warehouses`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado)

### `POST /api/v1/inventory/equipo4/webhooks/payment-status`
- Acción: `Api\InventoryModule\PaymentWebhookController@receive`
- Request:
  - `event_id`: required · string · max:100
  - `event_version`: required · integer · min:1 · max:2147483647
  - `payment_intent_id`: required · string · max:100
- Respuesta: 202 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 422 validación; 409 conflicto de estado o idempotencia; 503 dependencia no disponible

### `POST /api/v1/inventory/reservations`
- Acción: `Api\InventoryModule\Team7ApiController@reserve`
- Request:
  - `origin_reference`: required · string · max:100
  - `origin_type`: required · string · max:50
  - `idempotency_key`: required · string · min:8 · max:128
  - `expires_at`: nullable · date · after:now
  - `business_id`: nullable · string · max:100
  - `items`: nullable · array · min:1 · max:100
  - `items.*.product_reference`: required_with:items · string
  - `items.*.variant_sku`: nullable · string
  - `items.*.location_id`: nullable · string
  - `items.*.quantity`: required_with:items · integer · min:1 · max:1000
  - `product_reference`: required_without:items · string
  - `variant_sku`: nullable · string
  - `location_id`: nullable · string
  - `quantity`: nullable · integer · min:1 · max:1000
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 422 validación; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `GET /api/v1/inventory/reservations/{id}`
- Acción: `Api\InventoryModule\Team7ApiController@show`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 404 recurso inexistente o ajeno

### `POST /api/v1/inventory/reservations/{id}/confirm`
- Acción: `Api\InventoryModule\Team7ApiController@confirm`
- Parámetros de ruta: `id`
- Request:
  - `delivered_at`: nullable · date
  - `delivered_by`: nullable · string · max:100
  - `idempotency_key`: required · string · min:8 · max:128
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 422 validación; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `POST /api/v1/inventory/reservations/{id}/release`
- Acción: `Api\InventoryModule\Team7ApiController@release`
- Parámetros de ruta: `id`
- Request:
  - `reason`: nullable · string · max:200
  - `idempotency_key`: required · string · min:8 · max:128
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 422 validación; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

## E5 Servicios (6)

### `GET /api/v1/servicios/asignaciones`
- Acción: `StudentServices\Benefits\BenefitApiController@index` · nombre `api.student-services.benefits.assignments.index`
- Request (`ListBenefitAssignmentsRequest`):
  - `clave_idempotencia`: ['nullable', 'string', 'max:150']
  - `solicitud_id`: ['nullable', 'string', 'max:64']
  - `convocatoria_id`: ['nullable', 'string', 'max:64']
  - `beneficiario_id`: ['nullable', 'string', 'max:64']
  - `organizacion_id`: ['nullable', 'string', 'max:64']
  - `beneficio_id`: ['nullable', 'string', 'max:40']
  - `cursor`: ['nullable', 'string', 'max:64']
  - `limite`: ['nullable', 'integer', 'min:1', 'max:100']
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso; 422 validación

### `POST /api/v1/servicios/asignaciones`
- Acción: `StudentServices\Benefits\BenefitApiController@store` · nombre `api.student-services.benefits.assignments.store`
- Request (`StoreBenefitAssignmentRequest`):
  - `beneficiario_id`: ['required', 'string', 'max:64']
  - `organizacion_id`: ['required', 'string', 'max:64']
  - `convocatoria_id`: ['required', 'string', 'max:64']
  - `solicitud_id`: ['required', 'string', 'max:64']
  - `folio`: ['nullable', 'string', 'max:64']
  - `beneficio_id`: ['required_without:tipo', 'nullable', 'string', 'max:40']
  - `tipo`: ['required_without:beneficio_id', 'nullable', 'string', 'max:40']
  - `cantidad`: ['required', 'integer', 'min:1']
  - `periodo`: ['nullable', 'array']
  - `periodo.inicio`: ['required_without:vigencia_inicio', 'nullable', 'date']
  - `periodo.fin`: ['required_without:vigencia_fin_exclusiva', 'nullable', 'date']
  - `vigencia_inicio`: ['required_without:periodo.inicio', 'nullable', 'date']
  - `vigencia_fin_exclusiva`: ['required_without:periodo.fin', 'nullable', 'date']
  - `edificio`: ['nullable', 'string', 'max:60']
  - `clave_idempotencia`: ['nullable', 'string', 'max:150']
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso; 422 validación

### `GET /api/v1/servicios/asignaciones/{assignmentId}`
- Acción: `StudentServices\Benefits\BenefitApiController@show` · nombre `api.student-services.benefits.assignments.show`
- Parámetros de ruta: `assignmentId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/v1/servicios/asignaciones/{assignmentId}/cancelacion`
- Acción: `StudentServices\Benefits\BenefitApiController@cancel` · nombre `api.student-services.benefits.assignments.cancel`
- Parámetros de ruta: `assignmentId`
- Request (`CancelBenefitAssignmentRequest`):
  - `motivo`: ['required', 'string', 'max:500']
  - `referencia_origen`: ['nullable', 'string', 'max:150']
  - `clave_idempotencia`: ['nullable', 'string', 'max:150']
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/v1/servicios/beneficios`
- Acción: `StudentServices\Benefits\BenefitApiController@catalog` · nombre `api.student-services.benefits.catalog`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso

### `GET /api/v1/servicios/disponibilidad`
- Acción: `StudentServices\Benefits\BenefitApiController@availability` · nombre `api.student-services.benefits.availability`
- Request (`BenefitAvailabilityRequest`):
  - `beneficio_id`: ['required', 'string', 'max:40']
  - `inicio`: ['nullable', 'date']
  - `fin`: ['nullable', 'date', 'after:inicio']
  - `cantidad`: ['nullable', 'integer', 'min:1']
  - `zona`: ['nullable', 'string', 'max:60']
  - `edificio`: ['nullable', 'string', 'max:60']
  - `campus`: ['nullable', 'string', 'max:60']
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso; 422 validación

## E6 Comunidad (120)

### `GET /api/v1/comunidad/becas/{solicitud}/bono`
- Acción: `Api\Community\BecaAwardController`
- Parámetros de ruta: `solicitud`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `GET /api/v1/comunidad/notificaciones`
- Acción: `Api\Community\AvisoController@index`
- Request:
  - `clave_idempotencia`: ['required', 'string', 'max:128']
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso; 422 validación

### `POST /api/v1/comunidad/notificaciones`
- Acción: `Api\Community\AvisoController@store`
- Request:
  - `destinatario_id`: ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9_-]
- Respuesta: 201 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso; 422 validación

### `POST /api/v1/comunidad/notificaciones/{id}/retiro`
- Acción: `Api\Community\AvisoController@retiro`
- Parámetros de ruta: `id`
- Request:
  - `motivo`: ['required', 'string', 'max:500']
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/organizaciones/comercio`
- Acción: `Api\Community\OrganizationCommerceController`
- Request:
  - `subject_id`: ['required', 'string', 'max:100', 'regex:/^[^\x00-\x20\x7f]
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso; 422 validación

### `GET /api/v1/comunidad/portal/bandeja`
- Acción: `BandejaController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `GET /api/v1/comunidad/portal/bandeja/categorias`
- Acción: `BandejaController@categorias`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `PUT /api/v1/comunidad/portal/bandeja/categorias/servicios_estudiante`
- Acción: `BandejaController@categoria`
- Request:
  - `silenciada`: ['required', 'boolean']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación

### `GET /api/v1/comunidad/portal/bandeja/preferencias`
- Acción: `BandejaController@preferencias`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `PUT /api/v1/comunidad/portal/bandeja/preferencias/{id}`
- Acción: `BandejaController@preferencia`
- Parámetros de ruta: `id`
- Request:
  - `silenciada`: ['required', 'boolean']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/bandeja/{id}`
- Acción: `BandejaController@show`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `PUT /api/v1/comunidad/portal/bandeja/{id}`
- Acción: `BandejaController@update`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/becas`
- Acción: `BecaController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `POST /api/v1/comunidad/portal/becas`
- Acción: `BecaController@store`
- Request (`GuardarConvocatoriaRequest`):
  - `titulo`: ['required', 'string', 'max:150']
  - `descripcion`: ['required', 'string', 'max:5000']
  - `requisitos`: ['required', 'string', 'max:5000']
  - `tipo_beneficio_id`: ['required', 'string', 'regex:/^[a-f0-9]
  - `monto`: ['required', 'regex:/^\d{1,6}(\.\d{1,2})?$/']
  - `cantidad`: ['required', 'integer', 'min:1', 'max:10000']
  - `total_espacios`: ['required', 'integer', 'min:1', 'max:10000']
  - `fecha_inicio`: ['required', 'date_format:Y-m-d']
  - `fecha_fin`: ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio']
  - `vigencia_inicio`: ['required', 'date_format:Y-m-d', 'after:fecha_fin']
  - `vigencia_fin`: ['required', 'date_format:Y-m-d', 'after_or_equal:vigencia_inicio']
  - `requisitos_documentos`: ['present', 'array', 'max:5']
  - `requisitos_documentos.*`: ['required', 'string', 'max:150', 'distinct']
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 422 validación

### `GET /api/v1/comunidad/portal/becas/mis-solicitudes`
- Acción: `BecaController@misSolicitudes`
- Request:
  - `page`: ['nullable', 'integer', 'min:1']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación

### `GET /api/v1/comunidad/portal/becas/solicitudes/{id}`
- Acción: `BecaController@showSolicitud`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `PUT /api/v1/comunidad/portal/becas/solicitudes/{id}`
- Acción: `BecaController@guardarSolicitud`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/becas/solicitudes/{id}/aval-tesoreria`
- Acción: `BecaController@avalarPresupuesto`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `POST /api/v1/comunidad/portal/becas/solicitudes/{id}/confirmacion-presidencia`
- Acción: `BecaController@confirmarPresidencia`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `GET /api/v1/comunidad/portal/becas/solicitudes/{id}/contrato`
- Acción: `BecaController@contrato`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/becas/solicitudes/{id}/dictamen`
- Acción: `BecaController@dictaminar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/becas/solicitudes/{id}/documentos`
- Acción: `BecaController@subirDocumento`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `DELETE /api/v1/comunidad/portal/becas/solicitudes/{id}/documentos/{documento}`
- Acción: `BecaController@eliminarDocumento`
- Parámetros de ruta: `id`, `documento`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/becas/solicitudes/{id}/documentos/{documento}`
- Acción: `BecaController@descargarDocumento`
- Parámetros de ruta: `id`, `documento`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · descarga
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `PUT /api/v1/comunidad/portal/becas/solicitudes/{id}/recomendacion`
- Acción: `BecaController@recomendar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/becas/solicitudes/{id}/retirar`
- Acción: `BecaController@retirar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/becas/solicitudes/{id}/revocacion-beneficio`
- Acción: `BecaController@revocarBeneficio`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `GET /api/v1/comunidad/portal/becas/tesoreria`
- Acción: `BecaController@tesoreria`
- Request:
  - `page`: ['nullable', 'integer', 'min:1']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación

### `PUT /api/v1/comunidad/portal/becas/{id}`
- Acción: `BecaController@update`
- Parámetros de ruta: `id`
- Request (`GuardarConvocatoriaRequest`):
  - `titulo`: ['required', 'string', 'max:150']
  - `descripcion`: ['required', 'string', 'max:5000']
  - `requisitos`: ['required', 'string', 'max:5000']
  - `tipo_beneficio_id`: ['required', 'string', 'regex:/^[a-f0-9]
  - `monto`: ['required', 'regex:/^\d{1,6}(\.\d{1,2})?$/']
  - `cantidad`: ['required', 'integer', 'min:1', 'max:10000']
  - `total_espacios`: ['required', 'integer', 'min:1', 'max:10000']
  - `fecha_inicio`: ['required', 'date_format:Y-m-d']
  - `fecha_fin`: ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio']
  - `vigencia_inicio`: ['required', 'date_format:Y-m-d', 'after:fecha_fin']
  - `vigencia_fin`: ['required', 'date_format:Y-m-d', 'after_or_equal:vigencia_inicio']
  - `requisitos_documentos`: ['present', 'array', 'max:5']
  - `requisitos_documentos.*`: ['required', 'string', 'max:150', 'distinct']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/becas/{id}/comite`
- Acción: `BecaController@comite`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `PUT /api/v1/comunidad/portal/becas/{id}/comite`
- Acción: `BecaController@guardarComite`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/becas/{id}/estado`
- Acción: `BecaController@cambiarEstado`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/becas/{id}/solicitud`
- Acción: `BecaController@crearSolicitud`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/becas/{id}/solicitudes`
- Acción: `BecaController@solicitudes`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/comunicacion`
- Acción: `ComunicacionController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `POST /api/v1/comunidad/portal/comunicacion`
- Acción: `ComunicacionController@store`
- Request (`GuardarCampanaRequest`):
  - `nombre`: ['required', 'string', 'max:150']
  - `asunto`: ['required', 'string', 'max:150']
  - `cuerpo`: ['required', 'string', 'min:10', 'max:6000']
  - `audiencia`: ['required', Rule::in(['miembros', 'evento_confirmados', 'evento_espera', 'beca_solicitudes', 'beca_aprobadas']
  - `referencia_id`: ['required_unless:audiencia,miembros', 'nullable', 'string', 'regex:/^[a-f0-9]
  - `accion`: ['required', Rule::in(['ninguna', 'eventos', 'becas', 'mis_boletos', 'boleto_evento', 'solicitud_beca']
  - `texto_accion`: ['required_unless:accion,ninguna', 'nullable', 'string', 'max:60']
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 422 validación

### `PUT /api/v1/comunidad/portal/comunicacion/{id}`
- Acción: `ComunicacionController@update`
- Parámetros de ruta: `id`
- Request (`GuardarCampanaRequest`):
  - `nombre`: ['required', 'string', 'max:150']
  - `asunto`: ['required', 'string', 'max:150']
  - `cuerpo`: ['required', 'string', 'min:10', 'max:6000']
  - `audiencia`: ['required', Rule::in(['miembros', 'evento_confirmados', 'evento_espera', 'beca_solicitudes', 'beca_aprobadas']
  - `referencia_id`: ['required_unless:audiencia,miembros', 'nullable', 'string', 'regex:/^[a-f0-9]
  - `accion`: ['required', Rule::in(['ninguna', 'eventos', 'becas', 'mis_boletos', 'boleto_evento', 'solicitud_beca']
  - `texto_accion`: ['required_unless:accion,ninguna', 'nullable', 'string', 'max:60']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/comunicacion/{id}/cancelar`
- Acción: `ComunicacionController@cancelar`
- Parámetros de ruta: `id`
- Request:
  - `motivo`: ['required', 'string', 'min:10', 'max:500']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/comunicacion/{id}/enviar`
- Acción: `ComunicacionController@enviar`
- Parámetros de ruta: `id`
- Request:
  - `firma`: ['required', 'string', 'regex:/^[a-f0-9]
- Respuesta: 202 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `POST /api/v1/comunidad/portal/comunicacion/{id}/preview`
- Acción: `ComunicacionController@preview`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/comunicacion/{id}/reintentar`
- Acción: `ComunicacionController@reintentar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 202 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/consultas/encuestas/eventos`
- Acción: `ConsultaController@eventos`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `GET /api/v1/comunidad/portal/consultas/{tipo}`
- Acción: `ConsultaController@index`
- Parámetros de ruta: `tipo`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/consultas/{tipo}`
- Acción: `ConsultaController@store`
- Parámetros de ruta: `tipo`
- Request (`GuardarConsultaRequest`):
  - `audiencia`: [$eleccion ? 'prohibited' : Rule::in(['miembros', 'evento_inscritos', 'evento_asistentes']
  - `evento_id`: [$eleccion ? 'prohibited' : 'required_if:audiencia,evento_inscritos,evento_asistentes',
                'prohibited_unless:audiencia,evento_inscritos,evento_asistentes', 'nullable', 'regex:/^[a-f0-9]
  - `revision`: ['sometimes', 'integer', 'min:1']
  - `titulo`: ['required', 'string', 'max:150']
  - `descripcion`: ['required', 'string', 'min:10', 'max:3000']
  - `fecha_inicio`: ['required', 'date_format:Y-m-d\TH:i']
  - `fecha_fin`: ['required', 'date_format:Y-m-d\TH:i', 'after:fecha_inicio']
  - `preguntas`: ['required', 'array', 'list', 'min:1', 'max:'.($eleccion ? 1 : 10)]
  - `preguntas.*`: ['required', 'array:titulo,opciones']
  - `preguntas.*.titulo`: ['required', 'string', 'max:250']
  - `preguntas.*.opciones`: ['required', 'array', 'list', 'min:2', 'max:'.($eleccion ? 20 : 10)]
  - `preguntas.*.opciones.*`: ['required', 'string', 'max:150']
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/consultas/{tipo}/{id}`
- Acción: `ConsultaController@show`
- Parámetros de ruta: `tipo`, `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `PUT /api/v1/comunidad/portal/consultas/{tipo}/{id}`
- Acción: `ConsultaController@update`
- Parámetros de ruta: `tipo`, `id`
- Request (`GuardarConsultaRequest`):
  - `audiencia`: [$eleccion ? 'prohibited' : Rule::in(['miembros', 'evento_inscritos', 'evento_asistentes']
  - `evento_id`: [$eleccion ? 'prohibited' : 'required_if:audiencia,evento_inscritos,evento_asistentes',
                'prohibited_unless:audiencia,evento_inscritos,evento_asistentes', 'nullable', 'regex:/^[a-f0-9]
  - `revision`: ['sometimes', 'integer', 'min:1']
  - `titulo`: ['required', 'string', 'max:150']
  - `descripcion`: ['required', 'string', 'min:10', 'max:3000']
  - `fecha_inicio`: ['required', 'date_format:Y-m-d\TH:i']
  - `fecha_fin`: ['required', 'date_format:Y-m-d\TH:i', 'after:fecha_inicio']
  - `preguntas`: ['required', 'array', 'list', 'min:1', 'max:'.($eleccion ? 1 : 10)]
  - `preguntas.*`: ['required', 'array:titulo,opciones']
  - `preguntas.*.titulo`: ['required', 'string', 'max:250']
  - `preguntas.*.opciones`: ['required', 'array', 'list', 'min:2', 'max:'.($eleccion ? 20 : 10)]
  - `preguntas.*.opciones.*`: ['required', 'string', 'max:150']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `POST /api/v1/comunidad/portal/consultas/{tipo}/{id}/cancelar`
- Acción: `ConsultaController@cancelar`
- Parámetros de ruta: `tipo`, `id`
- Request:
  - `motivo`: ['required', 'string', 'min:10', 'max:500']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/consultas/{tipo}/{id}/cerrar`
- Acción: `ConsultaController@cerrar`
- Parámetros de ruta: `tipo`, `id`
- Request:
  - `motivo`: ['required', 'string', 'min:10', 'max:500']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/consultas/{tipo}/{id}/preview`
- Acción: `ConsultaController@preview`
- Parámetros de ruta: `tipo`, `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/consultas/{tipo}/{id}/publicar`
- Acción: `ConsultaController@publicar`
- Parámetros de ruta: `tipo`, `id`
- Request:
  - `firma`: ['required', 'string', 'regex:/^[a-f0-9]
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `POST /api/v1/comunidad/portal/consultas/{tipo}/{id}/responder`
- Acción: `ConsultaController@responder`
- Parámetros de ruta: `tipo`, `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `GET /api/v1/comunidad/portal/dashboard`
- Acción: `DashboardController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `GET /api/v1/comunidad/portal/estudiante/boletos`
- Acción: `EventoController@boletos`
- Request:
  - `page`: ['nullable', 'integer', 'min:1']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación

### `GET /api/v1/comunidad/portal/estudiante/mi-boleto`
- Acción: `EventoController@miBoleto`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/eventos`
- Acción: `EventoController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `POST /api/v1/comunidad/portal/eventos`
- Acción: `EventoController@store`
- Request (`GuardarEventoRequest`):
  - `titulo`: ['required', 'string', 'max:150']
  - `descripcion`: ['required', 'string', 'max:5000']
  - `ubicacion`: ['required', 'string', 'max:255']
  - `fecha_hora_inicio`: ['required', 'date_format:Y-m-d\TH:i']
  - `fecha_hora_fin`: ['required', 'date_format:Y-m-d\TH:i']
  - `fecha_inicio_registro`: ['required', 'date_format:Y-m-d\TH:i']
  - `fecha_fin_registro`: ['required', 'date_format:Y-m-d\TH:i']
  - `costo`: ['required', 'numeric', 'min:0', 'max:100000', 'decimal:0,2']
  - `capacidad`: ['required', 'integer', 'min:1', 'max:10000']
  - `lista_espera`: ['required', 'boolean']
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 422 validación

### `POST /api/v1/comunidad/portal/eventos/checkin`
- Acción: `EventoController@checkin`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno; 503 dependencia no disponible

### `PUT /api/v1/comunidad/portal/eventos/{id}`
- Acción: `EventoController@update`
- Parámetros de ruta: `id`
- Request (`GuardarEventoRequest`):
  - `titulo`: ['required', 'string', 'max:150']
  - `descripcion`: ['required', 'string', 'max:5000']
  - `ubicacion`: ['required', 'string', 'max:255']
  - `fecha_hora_inicio`: ['required', 'date_format:Y-m-d\TH:i']
  - `fecha_hora_fin`: ['required', 'date_format:Y-m-d\TH:i']
  - `fecha_inicio_registro`: ['required', 'date_format:Y-m-d\TH:i']
  - `fecha_fin_registro`: ['required', 'date_format:Y-m-d\TH:i']
  - `costo`: ['required', 'numeric', 'min:0', 'max:100000', 'decimal:0,2']
  - `capacidad`: ['required', 'integer', 'min:1', 'max:10000']
  - `lista_espera`: ['required', 'boolean']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/eventos/{id}/boleto`
- Acción: `EventoController@miBoleto`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/eventos/{id}/cancelar`
- Acción: `EventoController@cancelarEvento`
- Parámetros de ruta: `id`
- Request:
  - `motivo`: ['required', 'string', 'min:10', 'max:500']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `DELETE /api/v1/comunidad/portal/eventos/{id}/inscripcion`
- Acción: `EventoController@cancelarInscripcion`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/eventos/{id}/inscripcion`
- Acción: `EventoController@inscribir`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/eventos/{id}/inscritos`
- Acción: `EventoController@inscritos`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/eventos/{id}/pago`
- Acción: `PagoEventoController@iniciar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/eventos/{id}/pago/consultar`
- Acción: `PagoEventoController@consultar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/eventos/{id}/publicar`
- Acción: `EventoController@publicar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/eventos/{id}/staff`
- Acción: `StaffEventoController@asignados`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/eventos/{id}/staff`
- Acción: `StaffEventoController@asignar`
- Parámetros de ruta: `id`
- Request:
  - `usuario_id`: ['required', 'string', 'regex:/^[a-f0-9]
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `DELETE /api/v1/comunidad/portal/eventos/{id}/staff/{staff}`
- Acción: `StaffEventoController@retirar`
- Parámetros de ruta: `id`, `staff`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/gestion-organizaciones`
- Acción: `GestionOrganizacionesController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `POST /api/v1/comunidad/portal/gestion-organizaciones`
- Acción: `GestionOrganizacionesController@store`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 sin sesión

### `POST /api/v1/comunidad/portal/gestion-organizaciones/titular`
- Acción: `GestionOrganizacionesController@titular`
- Request:
  - `matricula`: ['required', 'string', 'max:40']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación

### `GET /api/v1/comunidad/portal/gestion-organizaciones/{id}`
- Acción: `GestionOrganizacionesController@show`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/gestion-organizaciones/{id}/completar`
- Acción: `GestionOrganizacionesController@completar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/gestion-organizaciones/{id}/estado`
- Acción: `GestionOrganizacionesController@estado`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/identidad/mi-condicion`
- Acción: `IdentidadComunidadController`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno; 503 dependencia no disponible

### `GET /api/v1/comunidad/portal/notificaciones`
- Acción: `NotificacionController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `PUT /api/v1/comunidad/portal/notificaciones/leer`
- Acción: `NotificacionController@marcarLeidas`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `DELETE /api/v1/comunidad/portal/notificaciones/leidas`
- Acción: `NotificacionController@eliminarLeidas`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `GET /api/v1/comunidad/portal/organizaciones`
- Acción: `OrganizacionController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `GET /api/v1/comunidad/portal/organizaciones/comisiones`
- Acción: `ComisionConsejoController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `POST /api/v1/comunidad/portal/organizaciones/comisiones`
- Acción: `ComisionConsejoController@store`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 sin sesión

### `GET /api/v1/comunidad/portal/organizaciones/comisiones/{id}`
- Acción: `ComisionConsejoController@show`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `PUT /api/v1/comunidad/portal/organizaciones/comisiones/{id}`
- Acción: `ComisionConsejoController@update`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/organizaciones/comisiones/{id}/cerrar`
- Acción: `ComisionConsejoController@cerrar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/organizaciones/comisiones/{id}/integrantes`
- Acción: `ComisionConsejoController@asignar`
- Parámetros de ruta: `id`
- Request:
  - `usuario_id`: ['required', 'string', 'regex:/^[a-f0-9]
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/organizaciones/comisiones/{id}/integrantes/{asignacion}/retirar`
- Acción: `ComisionConsejoController@retirar`
- Parámetros de ruta: `id`, `asignacion`
- Request:
  - `motivo`: ['required', 'string', 'min:10', 'max:1000']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/organizaciones/delegaciones`
- Acción: `DelegacionOrganizacionController@index`
- Request:
  - `page`: ['nullable', 'integer', 'min:1']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación

### `POST /api/v1/comunidad/portal/organizaciones/delegaciones`
- Acción: `DelegacionOrganizacionController@store`
- Request:
  - `destinatario_id`: ['required', 'string', 'regex:/^[a-f0-9]
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 422 validación

### `POST /api/v1/comunidad/portal/organizaciones/delegaciones/{id}/revocar`
- Acción: `DelegacionOrganizacionController@revocar`
- Parámetros de ruta: `id`
- Request:
  - `motivo`: ['required', 'string', 'min:10', 'max:1000']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/organizaciones/documentos`
- Acción: `DocumentoOrganizacionController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `POST /api/v1/comunidad/portal/organizaciones/documentos`
- Acción: `DocumentoOrganizacionController@store`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 sin sesión

### `PUT /api/v1/comunidad/portal/organizaciones/documentos/{id}`
- Acción: `DocumentoOrganizacionController@update`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/organizaciones/documentos/{id}/descargar`
- Acción: `DocumentoOrganizacionController@descargar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · descarga
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/organizaciones/documentos/{id}/retirar`
- Acción: `DocumentoOrganizacionController@retirar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/organizaciones/documentos/{id}/versiones`
- Acción: `DocumentoOrganizacionController@versiones`
- Parámetros de ruta: `id`
- Request:
  - `page`: ['nullable', 'integer', 'min:1']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/organizaciones/documentos/{id}/versiones`
- Acción: `DocumentoOrganizacionController@nuevaVersion`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/organizaciones/documentos/{id}/versiones/{version}/descargar`
- Acción: `DocumentoOrganizacionController@descargar`
- Parámetros de ruta: `id`, `version`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · descarga
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/organizaciones/miembros`
- Acción: `OrganizacionController@addMiembro`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 422 validación

### `DELETE /api/v1/comunidad/portal/organizaciones/miembros/{id}`
- Acción: `OrganizacionController@removeMiembro`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `PUT /api/v1/comunidad/portal/organizaciones/perfil`
- Acción: `OrganizacionController@updatePerfil`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación

### `GET /api/v1/comunidad/portal/organizaciones/periodos`
- Acción: `PeriodoOrganizacionController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `POST /api/v1/comunidad/portal/organizaciones/periodos`
- Acción: `PeriodoOrganizacionController@store`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 sin sesión

### `GET /api/v1/comunidad/portal/organizaciones/periodos/{id}`
- Acción: `PeriodoOrganizacionController@show`
- Parámetros de ruta: `id`
- Request:
  - `page`: ['nullable', 'integer', 'min:1']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `PUT /api/v1/comunidad/portal/organizaciones/periodos/{id}`
- Acción: `PeriodoOrganizacionController@update`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/organizaciones/periodos/{id}/cancelar`
- Acción: `PeriodoOrganizacionController@cancelar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/organizaciones/periodos/{id}/historial`
- Acción: `PeriodoOrganizacionController@historial`
- Parámetros de ruta: `id`
- Request:
  - `page`: ['nullable', 'integer', 'min:1']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/organizaciones/roles`
- Acción: `OrganizacionController@assignRol`
- Request:
  - `usuario_id`: ['required', 'string', 'regex:/^[a-f0-9]
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `DELETE /api/v1/comunidad/portal/organizaciones/roles/{id}`
- Acción: `OrganizacionController@removeRol`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `PUT /api/v1/comunidad/portal/organizaciones/roles/{id}`
- Acción: `OrganizacionController@assignRol`
- Parámetros de ruta: `id`
- Request:
  - `usuario_id`: ['required', 'string', 'regex:/^[a-f0-9]
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/organizaciones/seleccionar`
- Acción: `OrganizacionController@seleccionar`
- Request:
  - `organizacion_id`: ['required', 'string', 'regex:/^[a-f0-9]
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/staff/eventos`
- Acción: `StaffEventoController@index`
- Request:
  - `page`: ['nullable', 'integer', 'min:1']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación

### `GET /api/v1/comunidad/portal/staff/eventos/{id}`
- Acción: `StaffEventoController@show`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/transparencia`
- Acción: `TransparenciaController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/transparencia/reportes`
- Acción: `ReporteController@store`
- Request (`CrearReporteRequest`):
  - `clave_solicitud`: ['required', 'uuid']
  - `titulo`: ['required', 'string', 'max:150']
  - `descripcion`: ['nullable', 'string', 'max:1500']
  - `fecha_inicio`: ['required', 'date_format:Y-m-d']
  - `fecha_fin`: ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio']
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 422 validación; 409 conflicto de estado o idempotencia

### `GET /api/v1/comunidad/portal/transparencia/reportes/{id}`
- Acción: `ReporteController@show`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/transparencia/reportes/{id}/csv`
- Acción: `ReporteController@csv`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · descarga
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/v1/comunidad/portal/transparencia/reportes/{id}/imprimir`
- Acción: `ReporteController@imprimir`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/transparencia/reportes/{id}/publicar`
- Acción: `ReporteController@publicar`
- Parámetros de ruta: `id`
- Request:
  - `confirmar`: ['required', 'accepted']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/v1/comunidad/portal/transparencia/reportes/{id}/retirar`
- Acción: `ReporteController@retirar`
- Parámetros de ruta: `id`
- Request:
  - `motivo`: ['required', 'string', 'min:10', 'max:500']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

## E6 Comunidad (portal heredado) (116)

### `GET /api/bandeja`
- Acción: `BandejaController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `GET /api/bandeja/categorias`
- Acción: `BandejaController@categorias`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `PUT /api/bandeja/categorias/servicios_estudiante`
- Acción: `BandejaController@categoria`
- Request:
  - `silenciada`: ['required', 'boolean']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación

### `GET /api/bandeja/preferencias`
- Acción: `BandejaController@preferencias`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `PUT /api/bandeja/preferencias/{id}`
- Acción: `BandejaController@preferencia`
- Parámetros de ruta: `id`
- Request:
  - `silenciada`: ['required', 'boolean']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/bandeja/{id}`
- Acción: `BandejaController@show`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `PUT /api/bandeja/{id}`
- Acción: `BandejaController@update`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/becas`
- Acción: `BecaController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `POST /api/becas`
- Acción: `BecaController@store`
- Request (`GuardarConvocatoriaRequest`):
  - `titulo`: ['required', 'string', 'max:150']
  - `descripcion`: ['required', 'string', 'max:5000']
  - `requisitos`: ['required', 'string', 'max:5000']
  - `tipo_beneficio_id`: ['required', 'string', 'regex:/^[a-f0-9]
  - `monto`: ['required', 'regex:/^\d{1,6}(\.\d{1,2})?$/']
  - `cantidad`: ['required', 'integer', 'min:1', 'max:10000']
  - `total_espacios`: ['required', 'integer', 'min:1', 'max:10000']
  - `fecha_inicio`: ['required', 'date_format:Y-m-d']
  - `fecha_fin`: ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio']
  - `vigencia_inicio`: ['required', 'date_format:Y-m-d', 'after:fecha_fin']
  - `vigencia_fin`: ['required', 'date_format:Y-m-d', 'after_or_equal:vigencia_inicio']
  - `requisitos_documentos`: ['present', 'array', 'max:5']
  - `requisitos_documentos.*`: ['required', 'string', 'max:150', 'distinct']
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 422 validación

### `GET /api/becas/mis-solicitudes`
- Acción: `BecaController@misSolicitudes`
- Request:
  - `page`: ['nullable', 'integer', 'min:1']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación

### `GET /api/becas/solicitudes/{id}`
- Acción: `BecaController@showSolicitud`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `PUT /api/becas/solicitudes/{id}`
- Acción: `BecaController@guardarSolicitud`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/becas/solicitudes/{id}/aval-tesoreria`
- Acción: `BecaController@avalarPresupuesto`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `POST /api/becas/solicitudes/{id}/confirmacion-presidencia`
- Acción: `BecaController@confirmarPresidencia`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `GET /api/becas/solicitudes/{id}/contrato`
- Acción: `BecaController@contrato`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/becas/solicitudes/{id}/dictamen`
- Acción: `BecaController@dictaminar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/becas/solicitudes/{id}/documentos`
- Acción: `BecaController@subirDocumento`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `DELETE /api/becas/solicitudes/{id}/documentos/{documento}`
- Acción: `BecaController@eliminarDocumento`
- Parámetros de ruta: `id`, `documento`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/becas/solicitudes/{id}/documentos/{documento}`
- Acción: `BecaController@descargarDocumento`
- Parámetros de ruta: `id`, `documento`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · descarga
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `PUT /api/becas/solicitudes/{id}/recomendacion`
- Acción: `BecaController@recomendar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/becas/solicitudes/{id}/retirar`
- Acción: `BecaController@retirar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/becas/solicitudes/{id}/revocacion-beneficio`
- Acción: `BecaController@revocarBeneficio`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `GET /api/becas/tesoreria`
- Acción: `BecaController@tesoreria`
- Request:
  - `page`: ['nullable', 'integer', 'min:1']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación

### `PUT /api/becas/{id}`
- Acción: `BecaController@update`
- Parámetros de ruta: `id`
- Request (`GuardarConvocatoriaRequest`):
  - `titulo`: ['required', 'string', 'max:150']
  - `descripcion`: ['required', 'string', 'max:5000']
  - `requisitos`: ['required', 'string', 'max:5000']
  - `tipo_beneficio_id`: ['required', 'string', 'regex:/^[a-f0-9]
  - `monto`: ['required', 'regex:/^\d{1,6}(\.\d{1,2})?$/']
  - `cantidad`: ['required', 'integer', 'min:1', 'max:10000']
  - `total_espacios`: ['required', 'integer', 'min:1', 'max:10000']
  - `fecha_inicio`: ['required', 'date_format:Y-m-d']
  - `fecha_fin`: ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio']
  - `vigencia_inicio`: ['required', 'date_format:Y-m-d', 'after:fecha_fin']
  - `vigencia_fin`: ['required', 'date_format:Y-m-d', 'after_or_equal:vigencia_inicio']
  - `requisitos_documentos`: ['present', 'array', 'max:5']
  - `requisitos_documentos.*`: ['required', 'string', 'max:150', 'distinct']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/becas/{id}/comite`
- Acción: `BecaController@comite`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `PUT /api/becas/{id}/comite`
- Acción: `BecaController@guardarComite`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/becas/{id}/estado`
- Acción: `BecaController@cambiarEstado`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/becas/{id}/solicitud`
- Acción: `BecaController@crearSolicitud`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/becas/{id}/solicitudes`
- Acción: `BecaController@solicitudes`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/comunicacion`
- Acción: `ComunicacionController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `POST /api/comunicacion`
- Acción: `ComunicacionController@store`
- Request (`GuardarCampanaRequest`):
  - `nombre`: ['required', 'string', 'max:150']
  - `asunto`: ['required', 'string', 'max:150']
  - `cuerpo`: ['required', 'string', 'min:10', 'max:6000']
  - `audiencia`: ['required', Rule::in(['miembros', 'evento_confirmados', 'evento_espera', 'beca_solicitudes', 'beca_aprobadas']
  - `referencia_id`: ['required_unless:audiencia,miembros', 'nullable', 'string', 'regex:/^[a-f0-9]
  - `accion`: ['required', Rule::in(['ninguna', 'eventos', 'becas', 'mis_boletos', 'boleto_evento', 'solicitud_beca']
  - `texto_accion`: ['required_unless:accion,ninguna', 'nullable', 'string', 'max:60']
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 422 validación

### `PUT /api/comunicacion/{id}`
- Acción: `ComunicacionController@update`
- Parámetros de ruta: `id`
- Request (`GuardarCampanaRequest`):
  - `nombre`: ['required', 'string', 'max:150']
  - `asunto`: ['required', 'string', 'max:150']
  - `cuerpo`: ['required', 'string', 'min:10', 'max:6000']
  - `audiencia`: ['required', Rule::in(['miembros', 'evento_confirmados', 'evento_espera', 'beca_solicitudes', 'beca_aprobadas']
  - `referencia_id`: ['required_unless:audiencia,miembros', 'nullable', 'string', 'regex:/^[a-f0-9]
  - `accion`: ['required', Rule::in(['ninguna', 'eventos', 'becas', 'mis_boletos', 'boleto_evento', 'solicitud_beca']
  - `texto_accion`: ['required_unless:accion,ninguna', 'nullable', 'string', 'max:60']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/comunicacion/{id}/cancelar`
- Acción: `ComunicacionController@cancelar`
- Parámetros de ruta: `id`
- Request:
  - `motivo`: ['required', 'string', 'min:10', 'max:500']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/comunicacion/{id}/enviar`
- Acción: `ComunicacionController@enviar`
- Parámetros de ruta: `id`
- Request:
  - `firma`: ['required', 'string', 'regex:/^[a-f0-9]
- Respuesta: 202 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `POST /api/comunicacion/{id}/preview`
- Acción: `ComunicacionController@preview`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/comunicacion/{id}/reintentar`
- Acción: `ComunicacionController@reintentar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 202 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/consultas/encuestas/eventos`
- Acción: `ConsultaController@eventos`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `GET /api/consultas/{tipo}`
- Acción: `ConsultaController@index`
- Parámetros de ruta: `tipo`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/consultas/{tipo}`
- Acción: `ConsultaController@store`
- Parámetros de ruta: `tipo`
- Request (`GuardarConsultaRequest`):
  - `audiencia`: [$eleccion ? 'prohibited' : Rule::in(['miembros', 'evento_inscritos', 'evento_asistentes']
  - `evento_id`: [$eleccion ? 'prohibited' : 'required_if:audiencia,evento_inscritos,evento_asistentes',
                'prohibited_unless:audiencia,evento_inscritos,evento_asistentes', 'nullable', 'regex:/^[a-f0-9]
  - `revision`: ['sometimes', 'integer', 'min:1']
  - `titulo`: ['required', 'string', 'max:150']
  - `descripcion`: ['required', 'string', 'min:10', 'max:3000']
  - `fecha_inicio`: ['required', 'date_format:Y-m-d\TH:i']
  - `fecha_fin`: ['required', 'date_format:Y-m-d\TH:i', 'after:fecha_inicio']
  - `preguntas`: ['required', 'array', 'list', 'min:1', 'max:'.($eleccion ? 1 : 10)]
  - `preguntas.*`: ['required', 'array:titulo,opciones']
  - `preguntas.*.titulo`: ['required', 'string', 'max:250']
  - `preguntas.*.opciones`: ['required', 'array', 'list', 'min:2', 'max:'.($eleccion ? 20 : 10)]
  - `preguntas.*.opciones.*`: ['required', 'string', 'max:150']
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/consultas/{tipo}/{id}`
- Acción: `ConsultaController@show`
- Parámetros de ruta: `tipo`, `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `PUT /api/consultas/{tipo}/{id}`
- Acción: `ConsultaController@update`
- Parámetros de ruta: `tipo`, `id`
- Request (`GuardarConsultaRequest`):
  - `audiencia`: [$eleccion ? 'prohibited' : Rule::in(['miembros', 'evento_inscritos', 'evento_asistentes']
  - `evento_id`: [$eleccion ? 'prohibited' : 'required_if:audiencia,evento_inscritos,evento_asistentes',
                'prohibited_unless:audiencia,evento_inscritos,evento_asistentes', 'nullable', 'regex:/^[a-f0-9]
  - `revision`: ['sometimes', 'integer', 'min:1']
  - `titulo`: ['required', 'string', 'max:150']
  - `descripcion`: ['required', 'string', 'min:10', 'max:3000']
  - `fecha_inicio`: ['required', 'date_format:Y-m-d\TH:i']
  - `fecha_fin`: ['required', 'date_format:Y-m-d\TH:i', 'after:fecha_inicio']
  - `preguntas`: ['required', 'array', 'list', 'min:1', 'max:'.($eleccion ? 1 : 10)]
  - `preguntas.*`: ['required', 'array:titulo,opciones']
  - `preguntas.*.titulo`: ['required', 'string', 'max:250']
  - `preguntas.*.opciones`: ['required', 'array', 'list', 'min:2', 'max:'.($eleccion ? 20 : 10)]
  - `preguntas.*.opciones.*`: ['required', 'string', 'max:150']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `POST /api/consultas/{tipo}/{id}/cancelar`
- Acción: `ConsultaController@cancelar`
- Parámetros de ruta: `tipo`, `id`
- Request:
  - `motivo`: ['required', 'string', 'min:10', 'max:500']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/consultas/{tipo}/{id}/cerrar`
- Acción: `ConsultaController@cerrar`
- Parámetros de ruta: `tipo`, `id`
- Request:
  - `motivo`: ['required', 'string', 'min:10', 'max:500']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/consultas/{tipo}/{id}/preview`
- Acción: `ConsultaController@preview`
- Parámetros de ruta: `tipo`, `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/consultas/{tipo}/{id}/publicar`
- Acción: `ConsultaController@publicar`
- Parámetros de ruta: `tipo`, `id`
- Request:
  - `firma`: ['required', 'string', 'regex:/^[a-f0-9]
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `POST /api/consultas/{tipo}/{id}/responder`
- Acción: `ConsultaController@responder`
- Parámetros de ruta: `tipo`, `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno; 409 conflicto de estado o idempotencia

### `GET /api/dashboard`
- Acción: `DashboardController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `GET /api/estudiante/boletos`
- Acción: `EventoController@boletos`
- Request:
  - `page`: ['nullable', 'integer', 'min:1']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación

### `GET /api/estudiante/mi-boleto`
- Acción: `EventoController@miBoleto`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/eventos`
- Acción: `EventoController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `POST /api/eventos`
- Acción: `EventoController@store`
- Request (`GuardarEventoRequest`):
  - `titulo`: ['required', 'string', 'max:150']
  - `descripcion`: ['required', 'string', 'max:5000']
  - `ubicacion`: ['required', 'string', 'max:255']
  - `fecha_hora_inicio`: ['required', 'date_format:Y-m-d\TH:i']
  - `fecha_hora_fin`: ['required', 'date_format:Y-m-d\TH:i']
  - `fecha_inicio_registro`: ['required', 'date_format:Y-m-d\TH:i']
  - `fecha_fin_registro`: ['required', 'date_format:Y-m-d\TH:i']
  - `costo`: ['required', 'numeric', 'min:0', 'max:100000', 'decimal:0,2']
  - `capacidad`: ['required', 'integer', 'min:1', 'max:10000']
  - `lista_espera`: ['required', 'boolean']
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 422 validación

### `POST /api/eventos/checkin`
- Acción: `EventoController@checkin`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno; 503 dependencia no disponible

### `PUT /api/eventos/{id}`
- Acción: `EventoController@update`
- Parámetros de ruta: `id`
- Request (`GuardarEventoRequest`):
  - `titulo`: ['required', 'string', 'max:150']
  - `descripcion`: ['required', 'string', 'max:5000']
  - `ubicacion`: ['required', 'string', 'max:255']
  - `fecha_hora_inicio`: ['required', 'date_format:Y-m-d\TH:i']
  - `fecha_hora_fin`: ['required', 'date_format:Y-m-d\TH:i']
  - `fecha_inicio_registro`: ['required', 'date_format:Y-m-d\TH:i']
  - `fecha_fin_registro`: ['required', 'date_format:Y-m-d\TH:i']
  - `costo`: ['required', 'numeric', 'min:0', 'max:100000', 'decimal:0,2']
  - `capacidad`: ['required', 'integer', 'min:1', 'max:10000']
  - `lista_espera`: ['required', 'boolean']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/eventos/{id}/boleto`
- Acción: `EventoController@miBoleto`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/eventos/{id}/cancelar`
- Acción: `EventoController@cancelarEvento`
- Parámetros de ruta: `id`
- Request:
  - `motivo`: ['required', 'string', 'min:10', 'max:500']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `DELETE /api/eventos/{id}/inscripcion`
- Acción: `EventoController@cancelarInscripcion`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/eventos/{id}/inscripcion`
- Acción: `EventoController@inscribir`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/eventos/{id}/inscritos`
- Acción: `EventoController@inscritos`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/eventos/{id}/pago`
- Acción: `PagoEventoController@iniciar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/eventos/{id}/pago/consultar`
- Acción: `PagoEventoController@consultar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/eventos/{id}/publicar`
- Acción: `EventoController@publicar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/eventos/{id}/staff`
- Acción: `StaffEventoController@asignados`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/eventos/{id}/staff`
- Acción: `StaffEventoController@asignar`
- Parámetros de ruta: `id`
- Request:
  - `usuario_id`: ['required', 'string', 'regex:/^[a-f0-9]
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `DELETE /api/eventos/{id}/staff/{staff}`
- Acción: `StaffEventoController@retirar`
- Parámetros de ruta: `id`, `staff`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/gestion-organizaciones`
- Acción: `GestionOrganizacionesController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `POST /api/gestion-organizaciones`
- Acción: `GestionOrganizacionesController@store`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 sin sesión

### `POST /api/gestion-organizaciones/titular`
- Acción: `GestionOrganizacionesController@titular`
- Request:
  - `matricula`: ['required', 'string', 'max:40']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación

### `GET /api/gestion-organizaciones/{id}`
- Acción: `GestionOrganizacionesController@show`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/gestion-organizaciones/{id}/completar`
- Acción: `GestionOrganizacionesController@completar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/gestion-organizaciones/{id}/estado`
- Acción: `GestionOrganizacionesController@estado`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/identidad/mi-condicion`
- Acción: `IdentidadComunidadController`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno; 503 dependencia no disponible

### `GET /api/notificaciones`
- Acción: `NotificacionController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `PUT /api/notificaciones/leer`
- Acción: `NotificacionController@marcarLeidas`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `DELETE /api/notificaciones/leidas`
- Acción: `NotificacionController@eliminarLeidas`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `POST /api/oauth/token`
- Acción: `OAuthTokenController`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: ninguno específico además de 500 genérico sanitizado

### `GET /api/organizaciones`
- Acción: `OrganizacionController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `GET /api/organizaciones/comisiones`
- Acción: `ComisionConsejoController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `POST /api/organizaciones/comisiones`
- Acción: `ComisionConsejoController@store`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 sin sesión

### `GET /api/organizaciones/comisiones/{id}`
- Acción: `ComisionConsejoController@show`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `PUT /api/organizaciones/comisiones/{id}`
- Acción: `ComisionConsejoController@update`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/organizaciones/comisiones/{id}/cerrar`
- Acción: `ComisionConsejoController@cerrar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/organizaciones/comisiones/{id}/integrantes`
- Acción: `ComisionConsejoController@asignar`
- Parámetros de ruta: `id`
- Request:
  - `usuario_id`: ['required', 'string', 'regex:/^[a-f0-9]
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/organizaciones/comisiones/{id}/integrantes/{asignacion}/retirar`
- Acción: `ComisionConsejoController@retirar`
- Parámetros de ruta: `id`, `asignacion`
- Request:
  - `motivo`: ['required', 'string', 'min:10', 'max:1000']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/organizaciones/delegaciones`
- Acción: `DelegacionOrganizacionController@index`
- Request:
  - `page`: ['nullable', 'integer', 'min:1']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación

### `POST /api/organizaciones/delegaciones`
- Acción: `DelegacionOrganizacionController@store`
- Request:
  - `destinatario_id`: ['required', 'string', 'regex:/^[a-f0-9]
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 422 validación

### `POST /api/organizaciones/delegaciones/{id}/revocar`
- Acción: `DelegacionOrganizacionController@revocar`
- Parámetros de ruta: `id`
- Request:
  - `motivo`: ['required', 'string', 'min:10', 'max:1000']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/organizaciones/documentos`
- Acción: `DocumentoOrganizacionController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `POST /api/organizaciones/documentos`
- Acción: `DocumentoOrganizacionController@store`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 sin sesión

### `PUT /api/organizaciones/documentos/{id}`
- Acción: `DocumentoOrganizacionController@update`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/organizaciones/documentos/{id}/descargar`
- Acción: `DocumentoOrganizacionController@descargar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · descarga
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/organizaciones/documentos/{id}/retirar`
- Acción: `DocumentoOrganizacionController@retirar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/organizaciones/documentos/{id}/versiones`
- Acción: `DocumentoOrganizacionController@versiones`
- Parámetros de ruta: `id`
- Request:
  - `page`: ['nullable', 'integer', 'min:1']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/organizaciones/documentos/{id}/versiones`
- Acción: `DocumentoOrganizacionController@nuevaVersion`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/organizaciones/documentos/{id}/versiones/{version}/descargar`
- Acción: `DocumentoOrganizacionController@descargar`
- Parámetros de ruta: `id`, `version`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · descarga
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/organizaciones/miembros`
- Acción: `OrganizacionController@addMiembro`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 422 validación

### `DELETE /api/organizaciones/miembros/{id}`
- Acción: `OrganizacionController@removeMiembro`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `PUT /api/organizaciones/perfil`
- Acción: `OrganizacionController@updatePerfil`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación

### `GET /api/organizaciones/periodos`
- Acción: `PeriodoOrganizacionController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `POST /api/organizaciones/periodos`
- Acción: `PeriodoOrganizacionController@store`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 sin sesión

### `GET /api/organizaciones/periodos/{id}`
- Acción: `PeriodoOrganizacionController@show`
- Parámetros de ruta: `id`
- Request:
  - `page`: ['nullable', 'integer', 'min:1']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `PUT /api/organizaciones/periodos/{id}`
- Acción: `PeriodoOrganizacionController@update`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/organizaciones/periodos/{id}/cancelar`
- Acción: `PeriodoOrganizacionController@cancelar`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/organizaciones/periodos/{id}/historial`
- Acción: `PeriodoOrganizacionController@historial`
- Parámetros de ruta: `id`
- Request:
  - `page`: ['nullable', 'integer', 'min:1']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/organizaciones/roles`
- Acción: `OrganizacionController@assignRol`
- Request:
  - `usuario_id`: ['required', 'string', 'regex:/^[a-f0-9]
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `DELETE /api/organizaciones/roles/{id}`
- Acción: `OrganizacionController@removeRol`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `PUT /api/organizaciones/roles/{id}`
- Acción: `OrganizacionController@assignRol`
- Parámetros de ruta: `id`
- Request:
  - `usuario_id`: ['required', 'string', 'regex:/^[a-f0-9]
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/organizaciones/seleccionar`
- Acción: `OrganizacionController@seleccionar`
- Request:
  - `organizacion_id`: ['required', 'string', 'regex:/^[a-f0-9]
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/staff/eventos`
- Acción: `StaffEventoController@index`
- Request:
  - `page`: ['nullable', 'integer', 'min:1']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación

### `GET /api/staff/eventos/{id}`
- Acción: `StaffEventoController@show`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `GET /api/transparencia`
- Acción: `TransparenciaController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/transparencia/reportes`
- Acción: `ReporteController@store`
- Request (`CrearReporteRequest`):
  - `clave_solicitud`: ['required', 'uuid']
  - `titulo`: ['required', 'string', 'max:150']
  - `descripcion`: ['nullable', 'string', 'max:1500']
  - `fecha_inicio`: ['required', 'date_format:Y-m-d']
  - `fecha_fin`: ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio']
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 422 validación; 409 conflicto de estado o idempotencia

### `GET /api/transparencia/reportes/{id}`
- Acción: `ReporteController@show`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/transparencia/reportes/{id}/csv`
- Acción: `ReporteController@csv`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · descarga
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `GET /api/transparencia/reportes/{id}/imprimir`
- Acción: `ReporteController@imprimir`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/transparencia/reportes/{id}/publicar`
- Acción: `ReporteController@publicar`
- Parámetros de ruta: `id`
- Request:
  - `confirmar`: ['required', 'accepted']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/transparencia/reportes/{id}/retirar`
- Acción: `ReporteController@retirar`
- Parámetros de ruta: `id`
- Request:
  - `motivo`: ['required', 'string', 'min:10', 'max:500']
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 422 validación; 404 recurso inexistente o ajeno

## E7 Plataforma (ingesta) (4)

### `POST /api/v1/integrations/eq7-events`
- Acción: `Api\InventoryModule\Team7WebhookController@receive`
- Request:
  - `event_id`: required · string · max:100
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 422 validación; 409 conflicto de estado o idempotencia; 503 dependencia no disponible

### `POST /api/v1/integrations/ingest-event`
- Acción: `PlatformModule\PlatformIngestController@storeInventoryEvent`
- Request:
  - `event_id`: required · string · max:100
  - `event_type`: required · in:inventory.movement_created,inventory.stock_updated,inventory.adjustment_made,inventory.cost_updated,inventory.return_processed,inventory.reservati
  - `source_domain`: required · in:inventory
  - `occurred_at`: required · date
  - `payload`: required · array
  - `payload.business_id`: required · string · max:100
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 422 validación

### `POST /api/v1/plataforma/auditoria`
- Acción: `PlatformModule\PlatformIngestController@storeAudit`
- Request:
  - `event_id`: required · uuid
  - `actor_id`: required · string · max:100
  - `actor_role`: required · string · max:50
  - `action`: required · string · max:100
  - `entity_type`: required · string · max:100
  - `entity_id`: required · string · max:100
  - `before`: nullable · array
  - `after`: nullable · array
  - `reason`: nullable · string · max:500
  - `correlation_id`: required · string · max:100
  - `ip_address`: nullable · ip
  - `device`: nullable · string · max:255
  - `occurred_at`: required · date
- Respuesta: 202 · JSON
- Errores: 401 sin sesión; 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 422 validación

### `POST /api/v1/plataforma/eventos`
- Acción: `PlatformModule\PlatformIngestController@storeEvent`
- Request:
  - `event_id`: required · uuid
  - `event_name`: ['required', 'string', 'max:100', 'regex:/^[a-z]
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 401 credencial o firma de servicio inválida; 503 integración sin secreto configurado (falla cerrado); 403 sin permiso; 422 validación

## E7 Recompensas (62)

### `GET /api/v1/recompensas/analytics/community`
- Acción: `PlatformModule\CommunityAnalyticsController`
- Request:
  - `organization_id`: ['sometimes', 'required', 'string', 'regex:/\A[a-fA-F0-9]
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación

### `GET /api/v1/recompensas/analytics/overview`
- Acción: `PlatformModule\AnalyticsController@overview`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `GET /api/v1/recompensas/analytics/professor`
- Acción: `PlatformModule\AnalyticsController@professorAnalytics`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `GET /api/v1/recompensas/audit/correlations/{id}`
- Acción: `PlatformModule\AuditController@correlation`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `GET /api/v1/recompensas/audit/logs`
- Acción: `PlatformModule\AuditController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `GET /api/v1/recompensas/audit/logs/{id}`
- Acción: `PlatformModule\AuditController@show`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `GET /api/v1/recompensas/businesses`
- Acción: `PlatformModule\BusinessController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `POST /api/v1/recompensas/businesses`
- Acción: `PlatformModule\BusinessController@store`
- Request:
  - `business_id`: required · string · max:100 · unique:platform.reward_program_memberships,business_id
  - `business_name`: required · string · max:255
  - `category`: nullable · string · max:100
  - `contact_email`: nullable · email
  - `compensation_model`: nullable · string · in:fixed,commission,hybrid
  - `status`: nullable · string · in:active,inactive,pending
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación

### `GET /api/v1/recompensas/businesses/{id}`
- Acción: `PlatformModule\BusinessController@show`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `PUT /api/v1/recompensas/businesses/{id}`
- Acción: `PlatformModule\BusinessController@update`
- Parámetros de ruta: `id`
- Request:
  - `business_name`: sometimes · required · string · max:255
  - `category`: nullable · string · max:100
  - `contact_email`: nullable · email
  - `compensation_model`: nullable · string · in:fixed,commission,hybrid
  - `status`: nullable · string · in:active,inactive,suspended
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/v1/recompensas/businesses/{id}/toggle-status`
- Acción: `PlatformModule\BusinessController@toggleStatus`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `GET /api/v1/recompensas/campaigns`
- Acción: `PlatformModule\CampaignController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `POST /api/v1/recompensas/campaigns`
- Acción: `PlatformModule\CampaignController@store`
- Request:
  - `name`: required · string · max:255
  - `description`: nullable · string · max:1000
  - `type`: nullable · string · in:multiplier,bonus,academic,special
  - `sponsor_type`: nullable · string · in:platform,business,academic
  - `sponsor_id`: nullable · string
  - `multiplier`: required · numeric · min:1.0 · max:10.0
  - `starts_at`: required · date
  - `ends_at`: nullable · date · after_or_equal:starts_at
  - `status`: nullable · string · in:active,draft,inactive
  - `scope`: nullable · string · in:global,business,department
  - `scope_ids`: nullable · array
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación

### `POST /api/v1/recompensas/campaigns/propose`
- Acción: `PlatformModule\CampaignController@propose`
- Request:
  - `name`: required · string · max:255
  - `description`: required · string · max:1000
  - `multiplier`: nullable · numeric · min:1.0 · max:5.0
  - `starts_at`: required · date
  - `ends_at`: nullable · date · after_or_equal:starts_at
  - `scope_ids`: nullable · array
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación

### `GET /api/v1/recompensas/campaigns/{id}`
- Acción: `PlatformModule\CampaignController@show`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `PUT /api/v1/recompensas/campaigns/{id}`
- Acción: `PlatformModule\CampaignController@update`
- Parámetros de ruta: `id`
- Request:
  - `name`: sometimes · required · string · max:255
  - `description`: nullable · string · max:1000
  - `type`: nullable · string · in:multiplier,bonus,academic,special
  - `multiplier`: nullable · numeric · min:1.0 · max:10.0
  - `starts_at`: nullable · date
  - `ends_at`: nullable · date
  - `status`: nullable · string · in:active,draft,inactive
  - `scope`: nullable · string · in:global,business,department
  - `scope_ids`: nullable · array
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/v1/recompensas/campaigns/{id}/toggle-status`
- Acción: `PlatformModule\CampaignController@toggleStatus`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `GET /api/v1/recompensas/catalogs`
- Acción: `PlatformModule\SettingsController@catalogs`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `POST /api/v1/recompensas/catalogs`
- Acción: `PlatformModule\SettingsController@storeCatalog`
- Request:
  - `name`: required · string · max:255
  - `code`: required · string · max:50 · unique:platform.catalogs,code
  - `items`: required · array
  - `description`: nullable · string
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación

### `GET /api/v1/recompensas/dashboard/stats`
- Acción: `PlatformModule\DashboardController@stats`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `GET /api/v1/recompensas/governance/fraud-flags`
- Acción: `PlatformModule\GovernanceController@getFraudFlags`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `GET /api/v1/recompensas/governance/fraud-flags/{id}`
- Acción: `PlatformModule\GovernanceController@showFraudFlag`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/v1/recompensas/governance/fraud-flags/{id}/review`
- Acción: `PlatformModule\GovernanceController@reviewFraudFlag`
- Parámetros de ruta: `id`
- Request:
  - `status`: required · string · in:open,under_review,resolved,dismissed
  - `notes`: required · string · max:1000
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/v1/recompensas/governance/manual-adjustment`
- Acción: `PlatformModule\GovernanceController@manualAdjustment`
- Request:
  - `student_id`: required · string
  - `amount`: required · integer · not_in:0
  - `reason`: required · string · max:500
  - `idempotency_key`: nullable · string
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación

### `GET /api/v1/recompensas/governance/policies`
- Acción: `PlatformModule\GovernanceController@getPolicies`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `POST /api/v1/recompensas/governance/policies`
- Acción: `PlatformModule\GovernanceController@storePolicy`
- Request:
  - `name`: required · string · max:255
  - `type`: required · string · max:100
  - `expiration_days`: nullable · integer · min:1
  - `expiration_batch_strategy`: nullable · string
  - `daily_cap_points`: nullable · integer · min:1
  - `max_redemptions_per_day`: nullable · integer · min:1
  - `reversal_grace_period_days`: nullable · integer · min:0
  - `status`: nullable · string · in:active,inactive
  - `config`: nullable · array
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación

### `PUT /api/v1/recompensas/governance/policies/{id}`
- Acción: `PlatformModule\GovernanceController@updatePolicy`
- Parámetros de ruta: `id`
- Request:
  - `name`: sometimes · required · string · max:255
  - `expiration_days`: nullable · integer · min:1
  - `expiration_batch_strategy`: nullable · string
  - `daily_cap_points`: nullable · integer · min:1
  - `max_redemptions_per_day`: nullable · integer · min:1
  - `reversal_grace_period_days`: nullable · integer · min:0
  - `status`: nullable · string · in:active,inactive
  - `config`: nullable · array
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/v1/recompensas/integrations/ingest-event`
- Acción: `PlatformModule\IntegrationController@ingestEvent`
- Request:
  - `event_type`: required · string · in:order.completed,service.purchased,sale.confirmed
  - `source_domain`: required · string
  - `student_id`: required · string
  - `business_id`: required · string
  - `sale_amount`: required · numeric · min:0
  - `order_reference`: required · string
  - `idempotency_key`: required · string
  - `campaign_id`: nullable · string
  - `metadata`: nullable · array
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación

### `GET /api/v1/recompensas/integrations/logs`
- Acción: `PlatformModule\IntegrationController@logs`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `GET /api/v1/recompensas/integrations/webhooks`
- Acción: `PlatformModule\IntegrationController@webhooks`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `POST /api/v1/recompensas/integrations/webhooks/{id}/retry`
- Acción: `PlatformModule\IntegrationController@retryWebhook`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno; 503 dependencia no disponible

### `GET /api/v1/recompensas/points/balance/{studentId}`
- Acción: `PlatformModule\PointsController@balance`
- Parámetros de ruta: `studentId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/v1/recompensas/points/earn`
- Acción: `PlatformModule\PointsController@earn`
- Request:
  - `student_id`: required · string
  - `business_id`: required · string
  - `sale_amount`: required · numeric · min:0
  - `source_domain`: required · string
  - `source_reference`: required · string
  - `campaign_id`: nullable · string
  - `idempotency_key`: nullable · string
  - `metadata`: nullable · array
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación

### `GET /api/v1/recompensas/points/history/{studentId}`
- Acción: `PlatformModule\PointsController@history`
- Parámetros de ruta: `studentId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `GET /api/v1/recompensas/points/ledger`
- Acción: `PlatformModule\PointsController@ledger`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `GET /api/v1/recompensas/points/my-balance`
- Acción: `PlatformModule\PointsController@myBalance`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `GET /api/v1/recompensas/points/my-history`
- Acción: `PlatformModule\PointsController@myHistory`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `POST /api/v1/recompensas/points/reconcile/{studentId}`
- Acción: `PlatformModule\PointsController@reconcile`
- Parámetros de ruta: `studentId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/v1/recompensas/points/redeem`
- Acción: `PlatformModule\PointsController@redeem`
- Request:
  - `student_id`: required · string
  - `redemption_id`: required · string
  - `points_required`: required · integer · min:1
  - `idempotency_key`: nullable · string
  - `metadata`: nullable · array
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación

### `POST /api/v1/recompensas/points/reverse`
- Acción: `PlatformModule\PointsController@reverse`
- Request:
  - `related_ledger_id`: required · string
  - `reason`: required · string · max:500
  - `idempotency_key`: nullable · string
  - `metadata`: nullable · array
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/v1/recompensas/redemptions`
- Acción: `PlatformModule\RedemptionController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `POST /api/v1/recompensas/redemptions`
- Acción: `PlatformModule\RedemptionController@store`
- Request:
  - `reward_id`: required · string
  - `reward_item_id`: nullable · string
  - `idempotency_key`: nullable · string · max:100
  - `student_id`: nullable · string
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación; 409 conflicto de estado o idempotencia

### `POST /api/v1/recompensas/redemptions/validate-code`
- Acción: `PlatformModule\RedemptionController@validateCode`
- Request:
  - `code`: required · string
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/v1/recompensas/redemptions/{id}`
- Acción: `PlatformModule\RedemptionController@show`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `POST /api/v1/recompensas/redemptions/{id}/cancel`
- Acción: `PlatformModule\RedemptionController@cancel`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `POST /api/v1/recompensas/redemptions/{id}/deliver`
- Acción: `PlatformModule\RedemptionController@deliver`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `GET /api/v1/recompensas/redemptions/{id}/events`
- Acción: `PlatformModule\RedemptionController@events`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `GET /api/v1/recompensas/rewards`
- Acción: `PlatformModule\RewardController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión

### `POST /api/v1/recompensas/rewards`
- Acción: `PlatformModule\RewardController@store`
- Request:
  - `business_id`: nullable · string
  - `name`: required · string · max:255
  - `description`: nullable · string · max:1000
  - `points_required`: required_without:points_cost · nullable · integer · min:1
  - `points_cost`: required_without:points_required · nullable · integer · min:1
  - `stock`: nullable · integer · min:0
  - `is_physical`: nullable · boolean
  - `status`: nullable · string · in:active,inactive,draft
  - `valid_from`: nullable · date
  - `valid_to`: nullable · date · after_or_equal:valid_from
  - `limit_per_user`: nullable · integer · min:1
  - `images`: nullable · array
  - `linked_product_reference`: nullable · string
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación

### `DELETE /api/v1/recompensas/rewards/{id}`
- Acción: `PlatformModule\RewardController@destroy`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `GET /api/v1/recompensas/rewards/{id}`
- Acción: `PlatformModule\RewardController@show`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 404 recurso inexistente o ajeno

### `PUT /api/v1/recompensas/rewards/{id}`
- Acción: `PlatformModule\RewardController@update`
- Parámetros de ruta: `id`
- Request:
  - `business_id`: nullable · string
  - `name`: sometimes · required · string · max:255
  - `description`: nullable · string · max:1000
  - `points_required`: sometimes · nullable · integer · min:1
  - `points_cost`: sometimes · nullable · integer · min:1
  - `stock`: nullable · integer · min:0
  - `is_physical`: nullable · boolean
  - `status`: nullable · string · in:active,inactive,draft
  - `valid_from`: nullable · date
  - `valid_to`: nullable · date
  - `limit_per_user`: nullable · integer · min:1
  - `images`: nullable · array
  - `linked_product_reference`: nullable · string
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno

### `GET /api/v1/recompensas/rules`
- Acción: `PlatformModule\EarningRuleController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `POST /api/v1/recompensas/rules`
- Acción: `PlatformModule\EarningRuleController@store`
- Request:
  - `business_id`: required · string
  - `name`: required · string · max:255
  - `type`: nullable · string · in:amount_spent,fixed,event
  - `points_per_unit`: nullable · integer · min:0
  - `unit_amount`: nullable · numeric · min:0
  - `fixed_points`: nullable · integer · min:0
  - `multiplier`: nullable · numeric · min:0.1
  - `max_points_per_operation`: nullable · integer · min:1
  - `daily_cap`: nullable · integer · min:1
  - `active_from`: nullable · date
  - `active_to`: nullable · date · after_or_equal:active_from
  - `status`: nullable · string · in:active,inactive,draft
  - `excluded_products`: nullable · array
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación

### `GET /api/v1/recompensas/rules/{id}`
- Acción: `PlatformModule\EarningRuleController@show`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `PUT /api/v1/recompensas/rules/{id}`
- Acción: `PlatformModule\EarningRuleController@update`
- Parámetros de ruta: `id`
- Request:
  - `name`: sometimes · required · string · max:255
  - `type`: nullable · string · in:amount_spent,fixed,event
  - `points_per_unit`: nullable · integer · min:0
  - `unit_amount`: nullable · numeric · min:0
  - `fixed_points`: nullable · integer · min:0
  - `multiplier`: nullable · numeric · min:0.1
  - `max_points_per_operation`: nullable · integer · min:1
  - `daily_cap`: nullable · integer · min:1
  - `active_from`: nullable · date
  - `active_to`: nullable · date
  - `status`: nullable · string · in:active,inactive,draft
  - `excluded_products`: nullable · array
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno

### `POST /api/v1/recompensas/rules/{id}/toggle-status`
- Acción: `PlatformModule\EarningRuleController@toggleStatus`
- Parámetros de ruta: `id`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 404 recurso inexistente o ajeno

### `GET /api/v1/recompensas/settings`
- Acción: `PlatformModule\SettingsController@index`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `GET /api/v1/recompensas/settings/versions`
- Acción: `PlatformModule\SettingsController@versions`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso

### `POST /api/v1/recompensas/settings/versions`
- Acción: `PlatformModule\SettingsController@createVersion`
- Request:
  - `config_key`: required · string · max:100
  - `value`: required
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación

### `POST /api/v1/recompensas/settings/versions/{id}/transition`
- Acción: `PlatformModule\SettingsController@transitionVersion`
- Parámetros de ruta: `id`
- Request:
  - `target_status`: required · string · in:pending_approval,approved,rejected,active
  - `rejected_reason`: nullable · string · max:500
- Respuesta: 201 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno

### `PUT /api/v1/recompensas/settings/{key}`
- Acción: `PlatformModule\SettingsController@update`
- Parámetros de ruta: `key`
- Request:
  - `value`: required
  - `description`: nullable · string · max:500
- Respuesta: 200 · JSON
- Errores: 401 sin sesión; 403 sin permiso; 422 validación; 404 recurso inexistente o ajeno

## Sistema / otros (v1) (1)

### `GET /api/v1/organizations/{organizationId}/summary`
- Acción: `Api\Community\OrganizationSummaryController` · nombre `api.community.organizations.summary`
- Parámetros de ruta: `organizationId`
- Request: sin cuerpo validado (consulta o acción sin parámetros adicionales).
- Respuesta: 200 · JSON
- Errores: 401 credencial o firma de servicio inválida; 403 sin permiso; 404 recurso inexistente o ajeno

