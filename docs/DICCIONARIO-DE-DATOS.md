# Diccionario de datos — Campus Digital (generado desde el código)

| Versión | Fecha | Responsable | Cambio |
|---|---|---|---|
| 1.0 | 2026-11-12 | Equipos 1–7; generado con `scripts/generar_diccionario.py` | Corte MERGE_COMPLETO ffb4abd + parche de cierre |

Motores: **MongoDB** (identidad, comunidad, inventario, marketplace, servicios, plataforma) y **SQL Server** (finanzas E2, `database/migrations/financial`). La rúbrica menciona PostgreSQL; ver `EQUIVALENCIA-POSTGRESQL.md`. Campos derivados de `$fillable`/`$casts` y de las columnas declaradas en migraciones; las colecciones Mongo no tienen esquema rígido.

## Resumen: 74 tablas o colecciones con migración · 192 modelos Eloquent

## E1 Identidad / núcleo (26 modelos)

| Modelo | Colección/tabla | Conexión | Campos (tipo si está declarado) | Fuente |
|---|---|---|---|---|
| AcademicProgram | `academic_programs` | mongodb | campus_id, code, name, is_active:boolean | `app/Models/AcademicProgram.php` |
| AcademicStatusHistory | `academic_status_history` | mongodb | student_profile_id, from_status:StudentStatus::class, to_status:StudentStatus::class, reason, changed_by, changed_at:datetime | `app/Models/AcademicStatusHistory.php` |
| AuthorizationLifecycleRecord | `authorization_lifecycle_records (convención)` | mongodb | — | `app/Models/AuthorizationLifecycleRecord.php` |
| BusinessMembership | `business_memberships` | mongodb (por defecto) | user_id, business_id, status, starts_at, ends_at, joined_at, assigned_by, reason | `app/Models/BusinessMembership.php` |
| BusinessOwnerClaim | `business_owner_claims` | mongodb | business_id, subject_id | `app/Models/BusinessOwnerClaim.php` |
| BusinessOwnerProvision | `business_owner_provisions` | mongodb | operation_id, business_id, subject_id, client_id, result | `app/Models/BusinessOwnerProvision.php` |
| Campus | `campuses` | mongodb | code, name, is_active:boolean | `app/Models/Campus.php` |
| CommunicationPreference | `communication_preferences` | mongodb | user_id, email:boolean, push:boolean, sms:boolean, updated_by | `app/Models/CommunicationPreference.php` |
| Consent | `consents` | mongodb | user_id, student_profile_id, type:ConsentType::class, version, status, accepted_at:datetime, revoked_at:datetime, actor_id, revokes_consent_id | `app/Models/Consent.php` |
| CredentialEvent | `credential_events` | mongodb | nfc_card_id, performed_by, event_type, reason, previous_status, new_status | `app/Models/CredentialEvent.php` |
| Device | `devices` | mongodb | user_id, fingerprint, device_name, device_type, platform, browser, is_trusted:boolean, first_seen_at:datetime, last_seen_at:datetime, last_ip_address, revoked_at:datetime | `app/Models/Device.php` |
| Documento | `documentos (convención)` | mongodb | — | `app/Models/Documento.php` |
| EventOutbox | `event_outboxes` | mongodb | event_id, event_name, aggregate_id, payload:AsBsonDocument::class, occurred_at:datetime, published_at:datetime, attempts, last_error, claim_token, claim_expires_at:datetime, next_attempt_at:datetime | `app/Models/EventOutbox.php` |
| NfcCard | `nfc_cards` | mongodb | user_id, uid, registered_by, status, registered_at:datetime, blocked_at:datetime, replaced_at:datetime, replacement_of_card_id, replaced_by_card_id | `app/Models/NfcCard.php` |
| OfficialRoleAssignment | `role_assignments` | mongodb (por defecto) | — | `app/Models/OfficialRoleAssignment.php` |
| Permission | `permissions` | mongodb | key, display_name, description, domain, active:boolean | `app/Models/Permission.php` |
| QrToken | `qr_tokens` | mongodb | user_id, code, code_hash, code_encrypted, short_code, short_code_hash, short_code_claimed, type, purpose, expires_at:datetime, consumed_at:datetime, revoked_at:datetime | `app/Models/QrToken.php` |
| QrValidation | `qr_validations` | mongodb | qr_token_id, user_id, validated_by_user_id, validator_label, result, context, ip_address | `app/Models/QrValidation.php` |
| Role | `roles` | mongodb | name, display_name, description | `app/Models/Role.php` |
| RoleAssignment | `role_assignments` | mongodb (por defecto) | user_id, role_key, scope_type, scope_id, campus_id, status, starts_at, ends_at, assigned_at, assigned_by, reason, origin, source_key, source_fingerprint | `app/Models/RoleAssignment.php` |
| RolePermission | `role_permissions` | mongodb | role_key, permission_key | `app/Models/RolePermission.php` |
| SecurityEvent | `security_events` | mongodb | user_id, device_id, session_id, type, severity, ip_address, user_agent, metadata:array, occurred_at:datetime | `app/Models/SecurityEvent.php` |
| ServiceClient | `oauth_service_clients` | mongodb | name, client_id, secret_hash, scopes:AsBsonArray::class, active:boolean | `app/Models/ServiceClient.php` |
| StudentProfile | `student_profiles` | mongodb | user_id, enrollment_number, campus_id, academic_program_id, current_semester:integer, group_name, photo_path, academic_status:StudentStatus::class, personal_email, phone, preferred_contact_channel:PreferredContactChannel::class, locale | `app/Models/StudentProfile.php` |
| User | `users` | mongodb | name, matricula, email, password:hashed, account_activation_pending:boolean, roles, two_factor_secret, two_factor_recovery_codes, two_factor_confirmed_at | `app/Models/User.php` |
| UserSession | `user_sessions` | mongodb | user_id, device_id, ip_address, user_agent, location_label, started_at:datetime, last_activity_at:datetime, revoked_at:datetime, revoked_reason | `app/Models/UserSession.php` |

## E2 Finanzas (52 modelos)

| Modelo | Colección/tabla | Conexión | Campos (tipo si está declarado) | Fuente |
|---|---|---|---|---|
| Bonus | `bonuses` | sqlsrv | public_id, beneficiary_type, beneficiary_id, issuer_type, issuer_id, issuer_scope_type, issuer_scope_id, type:BonusType::class, original_amount_cents:integer, remaining_amount_cents:integer, currency, status:BonusStatus::class, valid_from:datetime, expires_at:datetime, combinable:boolean, allows_partial_use:boolean, external_reference | `app/Domains/Financial/Models/Bonus.php` |
| BonusAdministrativeOperation | `bonus_administrative_operations` | sqlsrv | public_id, bonus_id, action, actor_id, idempotency_key, request_hash, reason, request_data:array, before_data:array, after_data:array, created_at:datetime | `app/Domains/Financial/Models/BonusAdministrativeOperation.php` |
| BonusBudgetCommitment | `bonus_budget_commitments` | sqlsrv | — | `app/Domains/Financial/Models/BonusBudgetCommitment.php` |
| BonusLedgerEntry | `bonus_ledger_entries` | sqlsrv | public_id, bonus_id, movement_type:BonusMovementType::class, amount_cents:integer, remaining_after_cents:integer, reference_type, reference_id, actor_id, reason | `app/Domains/Financial/Models/BonusLedgerEntry.php` |
| BonusRestriction | `bonus_restrictions` | sqlsrv | public_id, bonus_id, restriction_type:BonusRestrictionType::class, target_id | `app/Domains/Financial/Models/BonusRestriction.php` |
| CashAdministrativeRequest | `cash_administrative_requests` | sqlsrv | public_id, cash_shift_id:integer, operation, amount_cents:integer, currency, refund_request_id, operator_id, student_id, reason, request_key, request_hash, supervisor_required:boolean, approval_policy_snapshot:array, status, supervisor_id, supervisor_reason, supervisor_reviewed_at:datetime, expires_at:datetime, cancelled_at:datetime, consumed_at:datetime, settlement_key, cash_movement_id | `app/Domains/Financial/Models/CashAdministrativeRequest.php` |
| CashApprovalPolicy | `cash_approval_policies` | sqlsrv | association_id, operation, currency, enabled:boolean, threshold_cents:integer, version:integer | `app/Domains/Financial/Models/CashApprovalPolicy.php` |
| CashApprovalPolicyChange | `cash_approval_policy_changes` | sqlsrv | cash_approval_policy_id, idempotency_key, request_hash, actor_id, reason, before_snapshot:array, after_snapshot:array, created_at:datetime | `app/Domains/Financial/Models/CashApprovalPolicyChange.php` |
| CashMovement | `cash_movements` | sqlsrv | public_id, cash_shift_id:integer, type:CashMovementType::class, amount_cents:integer, reference_type, reference_id, reason, actor_id, idempotency_key, request_hash, wallet_id | `app/Domains/Financial/Models/CashMovement.php` |
| CashOperationConfirmation | `cash_operation_confirmations` | sqlsrv | public_id, cash_shift_id:integer, wallet_id, operator_id, student_id, operation, amount_cents:integer, currency, reason, status, request_key, request_hash, expires_at:datetime, confirmed_at:datetime, confirmed_by, confirmation_ip, rejected_at:datetime, cancelled_at:datetime, settlement_key, cash_movement_id, consumed_at:datetime, supervisor_required:boolean, approval_policy_snapshot:array, supervisor_status, supervisor_id, supervisor_reason, supervisor_reviewed_at:datetime | `app/Domains/Financial/Models/CashOperationConfirmation.php` |
| CashReceipt | `cash_receipts` | sqlsrv | public_id, cash_movement_id:integer, folio, snapshot:array, issued_at:datetime | `app/Domains/Financial/Models/CashReceipt.php` |
| CashRegister | `cash_registers` | sqlsrv | public_id, name, currency, status:CashRegisterStatus::class, association_id, created_by, version:integer | `app/Domains/Financial/Models/CashRegister.php` |
| CashRegisterChange | `cash_register_changes` | sqlsrv | public_id, cash_register_id:integer, version:integer, action, before_state:array, after_state:array, actor_id, reason, idempotency_key, request_hash | `app/Domains/Financial/Models/CashRegisterChange.php` |
| CashShift | `cash_shifts` | sqlsrv | public_id, cash_register_id:integer, agent_id, opening_amount_cents:integer, counted_amount_cents:integer, difference_cents:integer, status:CashShiftStatus::class, opened_at:datetime, closed_at:datetime, opening_key, closing_key, closing_reason, closed_by, cash_in_cents:integer, cash_out_cents:integer, adjustment_cents:integer, expected_amount_cents:integer | `app/Domains/Financial/Models/CashShift.php` |
| EventPaymentRequest | `event_payment_requests (convención)` | sqlsrv | — | `app/Domains/Financial/Models/EventPaymentRequest.php` |
| FinancialCommunityNotice | `financial_community_notices (convención)` | sqlsrv | — | `app/Domains/Financial/Notifications/FinancialCommunityNotice.php` |
| FinancialCommunityNoticeScan | `financial_community_notice_scans (convención)` | sqlsrv | — | `app/Domains/Financial/Notifications/FinancialCommunityNoticeScan.php` |
| FinancialLimit | `financial_limits` | sqlsrv | public_id, name, subject_type:LimitSubjectType::class, subject_id, operation:MovementType::class, period:LimitPeriod::class, metric:LimitMetric::class, max_amount_cents:integer, max_count:integer, action:LimitAction::class, currency, active:boolean, valid_from:datetime, valid_until:datetime, created_by, updated_by, reason | `app/Domains/Financial/Models/FinancialLimit.php` |
| FinancialLimitChange | `financial_limit_changes` | sqlsrv | public_id, limit_id, change_type:LimitChangeType::class, actor_id, reason, correlation_id, before:array, after:array | `app/Domains/Financial/Models/FinancialLimitChange.php` |
| FinancialRefundRequest | `financial_refund_requests` | sqlsrv | public_id, request_transaction_id, original_transaction_id, wallet_id, amount_cents:integer, status:RefundRequestStatus::class, requested_by, reviewed_by, reason, review_reason, reviewed_at:datetime, completed_at:datetime, financial_transaction_id | `app/Domains/Financial/Models/FinancialRefundRequest.php` |
| FinancialTransaction | `financial_transactions` | sqlsrv | public_id, idempotency_key, status:TransactionStatus::class, reference_type, reference_id, original_transaction_id, metadata:array | `app/Domains/Financial/Models/FinancialTransaction.php` |
| FinancialWithdrawalRecovery | `financial_withdrawal_recoveries` | sqlsrv | public_id, refund_request_id, withdrawal_id, amount_cents:integer, currency, recovery_reference, confirmed_by, confirmed_at:datetime, notes, cash_movement_id, cash_shift_id | `app/Domains/Financial/Models/FinancialWithdrawalRecovery.php` |
| InstitutionalBonusBudget | `institutional_bonus_budgets` | sqlsrv | — | `app/Domains/Financial/Models/InstitutionalBonusBudget.php` |
| InstitutionalBonusObligation | `institutional_bonus_obligations` | sqlsrv | — | `app/Domains/Financial/Models/InstitutionalBonusObligation.php` |
| InstitutionalBonusRefund | `institutional_bonus_refunds` | sqlsrv | — | `app/Domains/Financial/Models/InstitutionalBonusRefund.php` |
| InstitutionalBonusSettlement | `institutional_bonus_settlements` | sqlsrv | — | `app/Domains/Financial/Models/InstitutionalBonusSettlement.php` |
| InstitutionalBudgetOperation | `institutional_budget_operations` | sqlsrv | — | `app/Domains/Financial/Models/InstitutionalBudgetOperation.php` |
| LedgerEntry | `ledger_entries` | sqlsrv | public_id, transaction_id, wallet_id, movement_type:MovementType::class, amount_cents:integer, available_delta_cents:integer, held_delta_cents:integer, balance_after_cents:integer, available_balance_after_cents:integer, held_balance_after_cents:integer | `app/Domains/Financial/Models/LedgerEntry.php` |
| LegacyCashReceiptReview | `legacy_cash_receipt_reviews` | sqlsrv | — | `app/Domains/Financial/Models/LegacyCashReceiptReview.php` |
| LibraryFinePaymentRequest | `library_fine_payment_requests (convención)` | sqlsrv | — | `app/Domains/Financial/Models/LibraryFinePaymentRequest.php` |
| MarketplacePaymentRequest | `marketplace_payment_requests` | sqlsrv | — | `app/Domains/Financial/Models/MarketplacePaymentRequest.php` |
| PurchasePayment | `purchase_payments` | sqlsrv | public_id, idempotency_key, wallet_id, bonus_id, requested_bonus_ids:array, business_id, category_id, currency, total_amount_cents:integer, bonus_amount_cents:integer, wallet_amount_cents:integer, status | `app/Domains/Financial/Models/PurchasePayment.php` |
| PurchasePaymentBonus | `purchase_payment_bonuses` | sqlsrv | purchase_payment_id, bonus_id, amount_cents:integer | `app/Domains/Financial/Models/PurchasePaymentBonus.php` |
| PurchaseRefundBonus | `purchase_refund_bonuses` | sqlsrv | refund_request_id, bonus_id, amount_cents:integer | `app/Domains/Financial/Models/PurchaseRefundBonus.php` |
| PurchaseRefundRequest | `purchase_refund_requests` | sqlsrv | public_id, purchase_payment_id, request_transaction_id, wallet_id, currency, total_amount_cents:integer, wallet_amount_cents:integer, bonus_amount_cents:integer, status:RefundRequestStatus::class, requested_by, reviewed_by, reason, review_reason, reviewed_at:datetime, completed_at:datetime, financial_transaction_id | `app/Domains/Financial/Models/PurchaseRefundRequest.php` |
| ReceiptUserDelegation | `receipt_user_delegations` | sqlsrv | — | `app/Domains/Financial/Models/ReceiptUserDelegation.php` |
| Reconciliation | `reconciliations` | sqlsrv | public_id, business_date:date, timezone, scope:ReconciliationScope::class, scope_wallet_ids:array, status:ReconciliationStatus::class, started_at:datetime, finished_at:datetime, executed_by, checks_executed:integer, differences_count:integer, summary:array, error_message, trigger_source:ReconciliationTrigger::class, attempt:integer, idempotency_key, cash_status:CashReconciliationStatus::class, new_differences_count:integer, recurring_differences_count:integer | `app/Domains/Financial/Models/Reconciliation.php` |
| ReconciliationDifference | `reconciliation_differences` | sqlsrv | public_id, reconciliation_id, check_code:ReconciliationCheck::class, entity_type, entity_id, wallet_id, expected_cents:integer, actual_cents:integer, difference_cents:integer, details:array, status:DifferenceStatus::class, resolved_by, resolved_at:datetime, resolution_note, fingerprint, business_date:date, last_seen_reconciliation_id, last_seen_at:datetime, occurrences:integer | `app/Domains/Financial/Models/ReconciliationDifference.php` |
| ReconciliationDifferenceObservation | `reconciliation_difference_observations` | sqlsrv | reconciliation_id, difference_id, is_new:boolean | `app/Domains/Financial/Models/ReconciliationDifferenceObservation.php` |
| StudentServicePaymentRequest | `student_service_payment_requests` | sqlsrv | — | `app/Domains/Financial/Models/StudentServicePaymentRequest.php` |
| StudentTransfer | `student_transfers` | sqlsrv | — | `app/Domains/Financial/Models/StudentTransfer.php` |
| StudentTransferConfirmation | `student_transfer_confirmations` | sqlsrv | — | `app/Domains/Financial/Models/StudentTransferConfirmation.php` |
| StudentTransferPolicy | `student_transfer_policies` | sqlsrv | — | `app/Domains/Financial/Models/StudentTransferPolicy.php` |
| StudentTransferPolicyChange | `student_transfer_policy_changes` | sqlsrv | — | `app/Domains/Financial/Models/StudentTransferPolicyChange.php` |
| TopUp | `topups` | sqlsrv | public_id, folio, wallet_id, amount_cents:integer, currency, method:TopUpMethod::class, status:TopUpStatus::class, agent_id, cash_shift_id, external_reference | `app/Domains/Financial/Models/TopUp.php` |
| TransactionAlert | `transaction_alerts` | sqlsrv | public_id, dedupe_key, alert_type:AlertType::class, status:AlertStatus::class, limit_id, wallet_id, transaction_id, ledger_entry_id, operation:MovementType::class, reference_type, reference_id, amount_cents:integer, limit_period:LimitPeriod::class, limit_metric:LimitMetric::class, limit_action:LimitAction::class, observed_value:integer, threshold_value:integer, details:array, detected_at:datetime, status_changed_by, status_changed_at:datetime, resolution_note, correlation_id, outcome:AlertOutcome::class, period_start:datetime, period_end:datetime | `app/Domains/Financial/Models/TransactionAlert.php` |
| TransactionAlertStatusChange | `transaction_alert_status_changes` | sqlsrv | public_id, alert_id, from_status:AlertStatus::class, to_status:AlertStatus::class, actor_id, note | `app/Domains/Financial/Models/TransactionAlertStatusChange.php` |
| Wallet | `wallets` | sqlsrv | public_id, owner_type, owner_id, type:WalletType::class, currency, status:WalletStatus::class, available_balance_cents:integer, held_balance_cents:integer | `app/Domains/Financial/Models/Wallet.php` |
| WalletHold | `wallet_holds` | sqlsrv | public_id, wallet_id, amount_cents:integer, currency, operation_type, reference_type, reference_id, status:WalletHoldStatus::class, expires_at:datetime, requested_by, reason, hold_transaction_id, closing_transaction_id, closed_by, closing_reason, closed_at:datetime, policy_id, policy_version:integer, max_duration_seconds:integer | `app/Domains/Financial/Models/WalletHold.php` |
| WalletHoldPolicy | `wallet_hold_policies` | sqlsrv | public_id, operation_type, max_duration_seconds:integer, active:boolean, version:integer, created_by, updated_by | `app/Domains/Financial/Models/WalletHoldPolicy.php` |
| WalletHoldPolicyChange | `wallet_hold_policy_changes` | sqlsrv | public_id, policy_id, version:integer, change_type, actor_id, reason, correlation_id, before:array, after:array | `app/Domains/Financial/Models/WalletHoldPolicyChange.php` |
| Withdrawal | `withdrawals` | sqlsrv | public_id, folio, wallet_id, amount_cents:integer, currency, method:WithdrawalMethod::class, status:WithdrawalStatus::class, agent_id, cash_shift_id, external_reference | `app/Domains/Financial/Models/Withdrawal.php` |

## E3 Marketplace (9 modelos)

| Modelo | Colección/tabla | Conexión | Campos (tipo si está declarado) | Fuente |
|---|---|---|---|---|
| AuditEvent | `marketplace_commercial_audit_events` | marketplace | actor_id, actor_name, business_id, entity_type, entity_id, action, from_status, to_status, details:array, occurred_at:datetime | `app/Models/Marketplace/AuditEvent.php` |
| Business | `marketplace_businesses` | marketplace | _id, organization_id, inventory_ready:boolean, name, slug, type, owner_id, description, status, visibility, verified:boolean, logo, banner, policies, hours:array, delivery_points:array, contact:array, payment_methods:array, tags:array, spei_instructions, openpay_profile_id | `app/Models/Marketplace/Business.php` |
| BusinessApplication | `marketplace_business_applications` | marketplace | organization_id, integration_error, business_name, type, applicant_id, description, status, observations, submitted_at:datetime, reviewed_at:datetime, reviewed_by, business_id | `app/Models/Marketplace/BusinessApplication.php` |
| Cart | `marketplace_carts` | marketplace | version:integer, user_id, items:array, subtotal:decimal:2, updated_at | `app/Models/Marketplace/Cart.php` |
| Order | `marketplace_orders` | marketplace | request_hash, cart_id, cart_version:integer, total_cents:integer, reservation_id, inventory_state, reserve_payload:array, wallet_checkout:array, hold_expires_at:datetime, cancellation_reason, folio, buyer_id, business_id, items:array, subtotal:decimal:2, discount:decimal:2, total:decimal:2, currency, payment_method, payment_status, status, delivery_point, notes, idempotency_key, history:array, created_at, updated_at | `app/Models/Marketplace/Order.php` |
| PaymentIntent | `marketplace_payment_intents` | marketplace | order_id, business_id, buyer_id, method, amount:decimal:2, currency, status, reference, provider, evidence:array, metadata:array, idempotency_key | `app/Models/Marketplace/PaymentIntent.php` |
| Product | `marketplace_products` | marketplace | inventory_product_id, inventory_location_id, business_id, name, slug, kind, category, description, image, price:decimal:2, currency, active:boolean, available:boolean, variants:array, attributes:array, inventory_required:boolean, stock_snapshot:integer, tags:array | `app/Models/Marketplace/Product.php` |
| ReturnRequest | `marketplace_return_requests` | marketplace | order_id, buyer_id, business_id, reason, evidence:array, status, requested_at:datetime, resolved_at:datetime, resolution_notes, resolved_by | `app/Models/Marketplace/ReturnRequest.php` |
| Storefront | `marketplace_storefronts` | marketplace | business_id, name, brand, description, banner, logo, policies:array, hours:array, delivery_points:array, channels:array, published:boolean | `app/Models/Marketplace/Storefront.php` |

## E4 Inventario (22 modelos)

| Modelo | Colección/tabla | Conexión | Campos (tipo si está declarado) | Fuente |
|---|---|---|---|---|
| Category | `categories` | mongodb (por defecto) | business_id, slug, name, description, active:boolean, has_sizes:boolean, has_colors:boolean, has_seasons:boolean | `app/Models/InventoryModule/Category.php` |
| CostHistory | `cost_histories` | mongodb (por defecto) | business_id, product_id, cost:float, source, effective_at:datetime | `app/Models/InventoryModule/CostHistory.php` |
| CustomerReturn | `customer_returns` | mongodb (por defecto) | business_id, folio, customer_id, customer_type, status, reason, sale_reference, resolution, created_by | `app/Models/InventoryModule/CustomerReturn.php` |
| GoodsReceipt | `goods_receipts` | mongodb (por defecto) | business_id, purchase_order_id, folio, received_by, status, notes, received_at:datetime | `app/Models/InventoryModule/GoodsReceipt.php` |
| GoodsReceiptItem | `goods_receipt_items` | mongodb (por defecto) | business_id, goods_receipt_id, purchase_order_item_id, product_id, product_sku, quantity:integer, unit_cost:float | `app/Models/InventoryModule/GoodsReceiptItem.php` |
| Inventory | `inventories` | mongodb (por defecto) | business_id, product_id, variant_id, location_id, on_hand:integer, reserved:integer, available:integer, status | `app/Models/InventoryModule/Inventory.php` |
| InventoryBusiness | `inventory_businesses` | mongodb (por defecto) | business_id, name, active:boolean | `app/Models/InventoryModule/InventoryBusiness.php` |
| Location | `locations` | mongodb (por defecto) | business_id, warehouse_id, code, name, type, capacity:integer, active:boolean | `app/Models/InventoryModule/Location.php` |
| PricingReference | `pricing_references` | mongodb (por defecto) | business_id, product_id, average_cost:float, last_cost:float, suggested_margin:float | `app/Models/InventoryModule/PricingReference.php` |
| Product | `products` | mongodb (por defecto) | business_id, sku, name, description, category, sizes, colors, seasons, collections, packages, preorder, official:boolean, stock_min:integer, stock_max:integer, active:boolean | `app/Models/InventoryModule/Product.php` |
| PurchaseOrder | `purchase_orders` | mongodb (por defecto) | business_id, supplier_id, folio, status, requested_by, authorized_by, expected_at:datetime, notes, total_estimated:float, items_count:integer | `app/Models/InventoryModule/PurchaseOrder.php` |
| PurchaseOrderItem | `purchase_order_items` | mongodb (por defecto) | business_id, purchase_order_id, line:integer, product_id, product_sku, product_name, quantity:integer, unit_cost:float, subtotal:float | `app/Models/InventoryModule/PurchaseOrderItem.php` |
| ReorderRule | `reorder_rules` | mongodb (por defecto) | business_id, product_id, location_id, min_qty:integer, max_qty:integer, reorder_point:integer, active:boolean | `app/Models/InventoryModule/ReorderRule.php` |
| StockAlert | `stock_alerts` | mongodb (por defecto) | business_id, product_id, location_id, type, current_qty:integer, threshold:integer, priority, status, message | `app/Models/InventoryModule/StockAlert.php` |
| StockCount | `stock_counts` | mongodb (por defecto) | business_id, folio, warehouse_id, location_id, status, started_by, closed_by, notes, differences_count:integer | `app/Models/InventoryModule/StockCount.php` |
| StockCountItem | `stock_count_items` | mongodb (por defecto) | business_id, stock_count_id, product_id, product_sku, product_name, expected_qty:integer, counted_qty:integer, difference:integer, unit_cost:float, notes | `app/Models/InventoryModule/StockCountItem.php` |
| StockMovement | `stock_movements` | mongodb (por defecto) | business_id, product_id, variant_id, location_id, type, quantity:integer, reason, external_reference, actor_id, correlation_id | `app/Models/InventoryModule/StockMovement.php` |
| StockReservation | `stock_reservations` | mongodb (por defecto) | business_id, product_id, variant_id, location_id, quantity:integer, status, source, external_reference, expires_at:datetime | `app/Models/InventoryModule/StockReservation.php` |
| Supplier | `suppliers` | mongodb (por defecto) | business_id, code, legal_name, trade_name, tax_id, contact_name, contact_email, contact_phone, payment_terms, notes, supplied_products, contacts, status | `app/Models/InventoryModule/Supplier.php` |
| SupplierReturn | `supplier_returns` | mongodb (por defecto) | business_id, folio, supplier_id, status, reason, source_type, source_reference, created_by | `app/Models/InventoryModule/SupplierReturn.php` |
| Team4Document | `team4_documents (convención)` | inventory | — | `app/Models/InventoryModule/Team4Document.php` |
| Warehouse | `warehouses` | mongodb (por defecto) | business_id, code, name, type, active:boolean | `app/Models/InventoryModule/Warehouse.php` |

## E5 Servicios (29 modelos)

| Modelo | Colección/tabla | Conexión | Campos (tipo si está declarado) | Fuente |
|---|---|---|---|---|
| Asset | `assets` | mongodb | inventory_item_id, name, category, location, status, description, deposit_cents:integer | `app/Models/StudentServices/Rentals/Asset.php` |
| AssetCondition | `asset_conditions` | mongodb | rental_id, asset_id, inventory_item_id, type, condition, notes, recorded_at:datetime | `app/Models/StudentServices/Rentals/AssetCondition.php` |
| BenefitAssignment | `benefit_assignments` | mongodb | folio, client_id, idempotency_key, request_hash, benefit, student_id, organization_id, call_id, application_id, origin_folio, quantity:integer, consumed:integer, remaining:integer, valid_from:datetime, valid_until:datetime, status, locker_assignment_id, locker_code, locker_building, locker_zone, locker_assignment_folio, consumptions, cancellation | `app/Models/StudentServices/Benefits/BenefitAssignment.php` |
| Book | `books` | mongodb | isbn, title, authors, publisher, edition, publication_year:integer, category, description, cover, status | `app/Models/StudentServices/Library/Book.php` |
| BookCopy | `book_copies` | mongodb | book_id, code, barcode, location, status, notes | `app/Models/StudentServices/Library/BookCopy.php` |
| BookReservation | `book_reservations` | mongodb | book_id, student_id, assigned_copy_id, reserved_at:datetime, ready_at:datetime, expires_at:datetime, fulfilled_at:datetime, status, notes | `app/Models/StudentServices/Library/BookReservation.php` |
| CalendarBlock | `calendar_blocks` | mongodb | folio, resource_type, resource_id, start_at:datetime, end_at:datetime, reason, created_by, cancelled_bookings:integer | `app/Models/StudentServices/Calendars/CalendarBlock.php` |
| Facility | `facilities` | mongodb | name, type, building, capacity:integer, cost_cents:integer, active:boolean | `app/Models/StudentServices/Reservations/Facility.php` |
| LibraryFine | `library_fines` | mongodb | folio, student_id, loan_id, type, amount_cents:integer, reason, status, generated_at:datetime, payment_reference_id, verified_payment_id, paid_at:datetime, notes | `app/Models/StudentServices/Library/LibraryFine.php` |
| Loan | `loans` | mongodb | copy_id, student_id, borrowed_at:datetime, due_at:datetime, returned_at:datetime, status, renewal_count:integer, notes | `app/Models/StudentServices/Library/Loan.php` |
| Locker | `lockers` | mongodb | code, qr_code, building, zone, size, status, notes | `app/Models/StudentServices/Lockers/Locker.php` |
| LockerAssignment | `locker_assignments` | mongodb | folio, locker_id, period_id, student_id, request_id, source, status, starts_at:datetime, ends_at:datetime, released_at:datetime, release_reason, renewal_count:integer, renewed_from_id, assigned_by, notes, payment_reference | `app/Models/StudentServices/Lockers/LockerAssignment.php` |
| LockerPeriod | `locker_periods` | mongodb | code, name, starts_at:datetime, ends_at:datetime, prices_cents, status | `app/Models/StudentServices/Lockers/LockerPeriod.php` |
| LockerRequest | `locker_requests` | mongodb | folio, student_id, period_id, locker_id, preferred_size, preferred_building, request_type, status, amount_cents:integer, payment_reference, paid_at:datetime, reviewed_by, reviewed_at:datetime, notes | `app/Models/StudentServices/Lockers/LockerRequest.php` |
| NotificationOutbox | `service_notification_outbox` | mongodb | key, source_class, source_id, payload, status, attempts:integer, next_attempt_at:datetime, receipt, last_error, withdraw_pending:boolean | `app/Models/StudentServices/Notifications/NotificationOutbox.php` |
| PrintJob | `print_jobs` | mongodb | service_order_id, job_type, file_name, file_path, file_mime, color_mode, paper_size, sides, created_at, updated_at | `app/Models/StudentServices/Services/PrintJob.php` |
| Rental | `rentals` | mongodb | asset_id, inventory_item_id, student_id, requested_at:datetime, due_at:datetime, returned_at:datetime, status, notes, deposit_cents:integer, deposit_status, deposit_hold_reference, deposit_hold_expires_at:datetime, deposit_settlement_reference, deposit_settlement_reason, deposit_settlement_operator_id, deposit_released_cents:integer, damage_charge_cents:integer, deposit_settled_at:datetime, picked_up_at:datetime | `app/Models/StudentServices/Rentals/Rental.php` |
| Reservation | `reservations` | mongodb | folio, facility_id, student_id, start_at:datetime, end_at:datetime, status, idempotency_key, waitlisted_at:datetime, promoted_at:datetime, checked_in_at:datetime, checked_out_at:datetime, no_show_at:datetime, expired_at:datetime, cancelled_at:datetime, cancelled_by, cancellation_reason | `app/Models/StudentServices/Reservations/Reservation.php` |
| ResourceCalendar | `resource_calendars` | mongodb | resource_type, resource_id, open_time, close_time, slot_minutes:integer, min_booking_minutes:integer, max_booking_minutes:integer, cancel_before_minutes:integer, no_show_tolerance_minutes:integer, max_active_per_student:integer, max_advance_days:integer, max_no_shows:integer, no_show_window_days:integer, operating_days:array, updated_by | `app/Models/StudentServices/Calendars/ResourceCalendar.php` |
| RestBooking | `rest_bookings` | mongodb | folio, rest_space_id, student_id, start_at:datetime, end_at:datetime, status, idempotency_key, waitlisted_at:datetime, promoted_at:datetime, checked_in_at:datetime, checked_out_at:datetime, no_show_at:datetime, expired_at:datetime, cancelled_at:datetime, cancelled_by, cancellation_reason | `app/Models/StudentServices/RestSpaces/RestBooking.php` |
| RestSpace | `rest_spaces` | mongodb | code, name, type, location, capacity:integer, description, status, active:boolean | `app/Models/StudentServices/RestSpaces/RestSpace.php` |
| ServiceAuditLog | `service_audit_logs` | mongodb | event_id, source_domain, actor_id, actor_role, action, entity_type, entity_id, before, after, reason, correlation_id, ip_address, device, occurred_at:datetime, published_at:datetime | `app/Models/StudentServices/Audit/ServiceAuditLog.php` |
| ServiceCharge | `service_charges` | mongodb | reference, operation, provider, student_id, concept, source_reference, hold_reference, amount_cents:integer, reason, idempotency_key, status | `app/Models/StudentServices/Payments/ServiceCharge.php` |
| ServiceCheckin | `service_checkins` | mongodb | folio, student_id, student_name, credential_hint, method, service, action, reference, reference_id, granted:boolean, message, operator_id, scanned_at:datetime | `app/Models/StudentServices/ServiceAccess/ServiceCheckin.php` |
| ServiceEvent | `service_events` | mongodb | event_type, service, action, student_id, reference, reference_id, checkin_id, actor_id, data:array, occurred_at:datetime | `app/Models/StudentServices/ServiceAccess/ServiceEvent.php` |
| ServiceOrder | `service_orders` | mongodb | folio, student_id, service_type, quantity:integer, observations, quoted_amount_cents:integer, payment_status, payment_reference_id, status, requested_at:datetime, paid_at:datetime, processing_at:datetime, ready_at:datetime, delivered_at:datetime, cancelled_at:datetime | `app/Models/StudentServices/Services/ServiceOrder.php` |
| ServicePlatformEvent | `service_platform_events` | mongodb | event_id, event_name, aggregate_id, occurred_at, payload, status, attempts:integer, next_attempt_at:datetime, last_error, sent_at:datetime | `app/Models/StudentServices/Platform/ServicePlatformEvent.php` |
| SupportTicket | `support_tickets` | mongodb | folio, student_id, category, subject, description, priority, location, status, assigned_to, evidence_name, evidence_path, opened_at:datetime, sla_due_at:datetime, resolved_at:datetime, closed_at:datetime | `app/Models/StudentServices/Support/SupportTicket.php` |
| TicketEvent | `ticket_events` | mongodb | ticket_id, event_type, from_status, to_status, actor_id, message, created_at:datetime | `app/Models/StudentServices/Support/TicketEvent.php` |

## E6 Comunidad (34 modelos)

| Modelo | Colección/tabla | Conexión | Campos (tipo si está declarado) | Fuente |
|---|---|---|---|---|
| AsignacionBeneficio | `asignaciones_beneficios` | mongodb (por defecto) | organizacion_id, convocatoria_id, solicitud_id, usuario_id, estado, contrato, aval_tesoreria, aprobacion_presidencia, recibo_servicios, revocacion, recibo_cancelacion, recibo_finanzas, entregado_en:datetime, intentos:integer, proximo_intento_en:datetime, error_integracion | `app/Models/AsignacionBeneficio.php` |
| AuditoriaComunidad | `auditoria_comunidad` | mongodb (por defecto) | organizacion_id, usuario_id, accion, entidad_id, antes, despues | `app/Models/AuditoriaComunidad.php` |
| Campana | `campañas` | mongodb (por defecto) | referencia_demo, organizacion_id, nombre, asunto, cuerpo, audiencia, referencia_id, accion, texto_accion, estado, revision:integer, destinatarios, total_destinatarios:integer, procesados:integer, omitidos:integer, enviado_por, emisor_nombre, ejecucion, encolado_en:datetime, enviado_en:datetime, error, motivo_cancelacion | `app/Models/Campana.php` |
| ComisionConsejo | `comisiones_consejo` | mongodb (por defecto) | organizacion_id, nombre, nombre_clave, proposito, fecha_inicio, fecha_fin, estado, version:integer, creado_por, clave_solicitud, huella, cerrada_en:datetime, cerrada_por, motivo_cierre | `app/Models/ComisionConsejo.php` |
| ConsultaComunidad | `consulta_comunidads (convención)` | mongodb (por defecto) | referencia_demo, organizacion_id, titulo, descripcion, fecha_inicio:datetime, fecha_fin:datetime, preguntas, estado, revision:integer, audiencia, evento_id, padron, total_padron:integer, publicado_en:datetime, cerrado_en:datetime, motivo_cierre, motivo_cancelacion | `app/Models/ConsultaComunidad.php` |
| ConvocatoriaBeca | `convocatorias_becas` | mongodb (por defecto) | referencia_demo, organizacion_id, creado_por, titulo, descripcion, requisitos, tipo_beneficio_id, beneficio, monto_centavos:integer, cantidad:integer, total_espacios:integer, fecha_inicio:datetime, fecha_fin:datetime, vigencia_inicio:datetime, vigencia_fin:datetime, requisitos_documentos, requiere_documentos:boolean, estado, motivo_cancelacion, comite | `app/Models/ConvocatoriaBeca.php` |
| DelegacionOrganizacion | `delegaciones_organizacion` | mongodb (por defecto) | organizacion_id, destinatario_id, delegante_id, cargo_origen_id, firma_origen, version_organizacion:integer, capacidades:array, inicio:datetime, fin:datetime, motivo, clave_idempotencia, huella, revocada_en:datetime, revocada_por, motivo_revocacion | `app/Models/DelegacionOrganizacion.php` |
| DocumentoConsulta | `documento_consultas (convención)` | mongodb (por defecto) | — | `app/Models/DocumentoConsulta.php` |
| DocumentoOrganizacion | `documentos_organizacion` | mongodb (por defecto) | organizacion_id, titulo, categoria, descripcion, fecha_documento, visibilidad, estado, version:integer, numero_actual:integer, version_actual_id, creado_por, clave_solicitud, huella, retirado_en:datetime, retirado_por, motivo_retiro | `app/Models/DocumentoOrganizacion.php` |
| Eleccion | `elecciones` | mongodb (por defecto) | — | `app/Models/Eleccion.php` |
| Encuesta | `encuestas` | mongodb (por defecto) | — | `app/Models/Encuesta.php` |
| Evento | `eventos` | mongodb | organizacion_id, tipo_evento_id, lista_espera:boolean, motivo_cancelacion, cancelado_en:datetime, titulo, slug, descripcion, ubicacion, fecha_hora_inicio:datetime, fecha_hora_fin:datetime, costo:float, capacidad:integer, fecha_inicio_registro:datetime, fecha_fin_registro:datetime, estado, creado_por, creado_en, actualizado_en | `app/Models/Evento.php` |
| IntegranteComision | `integrantes_comision` | mongodb (por defecto) | organizacion_id, comision_id, usuario_id, funcion, clave_solicitud, huella, asignado_por, retirado_en:datetime, retirado_por, motivo_retiro | `app/Models/IntegranteComision.php` |
| Mensaje | `mensajes` | mongodb (por defecto) | organizacion_id, usuario_id, asunto, cuerpo, emisor_nombre, accion_url, texto_accion, leido_en:datetime, primera_lectura_en:datetime, archivado_en:datetime, importante:boolean, eliminado_en:datetime, cliente_id, clave_idempotencia, huella, resultado_envio, estado_entrega, categoria, tipo_aviso, expira_en:datetime, retirada_en:datetime, motivo_retiro | `app/Models/Mensaje.php` |
| MiembroOrganizacion | `miembros_organizacion` | mongodb (por defecto) | organizacion_id, usuario_id, fecha_inicio:datetime, estado, eliminado_en:datetime | `app/Models/MiembroOrganizacion.php` |
| Organizacion | `organizaciones` | mongodb (por defecto) | slug, nombre, tipo, descripcion, email, telefono, estado, clave_alta, firma_alta, alta_datos, creado_por, version_estado, historial_estados | `app/Models/Organizacion.php` |
| PagoReservaEvento | `pagos_reservas_eventos` | mongodb (por defecto) | — | `app/Models/PagoReservaEvento.php` |
| ParticipacionConsulta | `participacion_consultas (convención)` | mongodb (por defecto) | consulta_id, organizacion_id, usuario_id, respuestas | `app/Models/ParticipacionConsulta.php` |
| PeriodoOrganizacion | `periodos_organizacion` | mongodb (por defecto) | organizacion_id, nombre, nombre_clave, descripcion, fecha_inicio, fecha_fin, estado, version:integer, creado_por, clave_solicitud, huella, cancelado_en:datetime, cancelado_por, motivo_cancelacion | `app/Models/PeriodoOrganizacion.php` |
| PermisoGestionOrganizaciones | `permisos_gestion_organizaciones` | mongodb (por defecto) | usuario_id, activo:boolean, operador, motivo | `app/Models/PermisoGestionOrganizaciones.php` |
| PreferenciaBandeja | `preferencias_bandeja` | mongodb (por defecto) | usuario_id, categoria, silenciada:boolean | `app/Models/PreferenciaBandeja.php` |
| PreferenciaComunicacion | `preferencias_comunicacion` | mongodb (por defecto) | usuario_id, organizacion_id, silenciada:boolean | `app/Models/PreferenciaComunicacion.php` |
| RecomendacionBeca | `recomendaciones_becas` | mongodb (por defecto) | organizacion_id, convocatoria_id, solicitud_id, usuario_id, version_comite:integer, revision:integer, recomendacion, motivo | `app/Models/RecomendacionBeca.php` |
| RegistroEvento | `registros_eventos` | mongodb | generacion:integer, solicitudes_versionadas:boolean, reserva_hasta:datetime, vencido_en:datetime, estado, monto_centavos:integer, moneda, cancelado_en:datetime, confirmado_en:datetime, asistio_en:datetime, asistencia_por, evento_id, usuario_id, estado_pago, pago_estado, token_qr, estado_asistencia, registrado_en:datetime, actualizado_en | `app/Models/RegistroEvento.php` |
| ReporteTransparencia | `reportes_transparencia` | mongodb (por defecto) | organizacion_id, organizacion_nombre, clave_solicitud, version:integer, titulo, descripcion, fecha_inicio, fecha_fin, generado_en:datetime, metricas, estado, publicado_en:datetime, retirado_en:datetime, motivo_retiro | `app/Models/ReporteTransparencia.php` |
| RespuestaEncuesta | `respuestas_encuestas` | mongodb (por defecto) | — | `app/Models/RespuestaEncuesta.php` |
| RetiroAviso | `retiros_avisos` | mongodb (por defecto) | cliente_id, clave_idempotencia, huella, notificacion_id, resultado | `app/Models/RetiroAviso.php` |
| RolOrganizacion | `asignaciones_roles_organizacion` | mongodb (por defecto) | organizacion_id, usuario_id, slug_rol, periodo_id, fecha_inicio:datetime, fecha_fin:datetime, otorgado_por, eliminado_en:datetime | `app/Models/RolOrganizacion.php` |
| SolicitudBeca | `solicitudes_becas` | mongodb (por defecto) | organizacion_id, convocatoria_id, usuario_id, folio, motivacion, documentos, estado, enviado_en:datetime, dictamen, dictaminado_en:datetime, dictaminado_por, motivo_cancelacion, dictamen_comite | `app/Models/SolicitudBeca.php` |
| SolicitudReservaEvento | `solicitudes_reservas_eventos` | mongodb (por defecto) | usuario_id, clave_solicitud, evento_id, accion, generacion_esperada:integer, generacion_resultado:integer | `app/Models/SolicitudReservaEvento.php` |
| StaffEvento | `staff_eventos` | mongodb (por defecto) | evento_id, organizacion_id, usuario_id, asignado_por, eliminado_en:datetime | `app/Models/StaffEvento.php` |
| TipoBeneficio | `tipos_beneficio` | mongodb (por defecto) | slug, nombre, es_monetario:boolean, es_servicio:boolean, equipo:integer | `app/Models/TipoBeneficio.php` |
| VersionDocumentoOrganizacion | `versiones_documentos_organizacion` | mongodb (por defecto) | organizacion_id, documento_id, numero:integer, nombre, tipo, tamano:integer, ruta, sha256, motivo, cargado_por, clave_solicitud, huella | `app/Models/VersionDocumentoOrganizacion.php` |
| Voto | `votos` | mongodb (por defecto) | — | `app/Models/Voto.php` |

## E7 Plataforma (20 modelos)

| Modelo | Colección/tabla | Conexión | Campos (tipo si está declarado) | Fuente |
|---|---|---|---|---|
| AnalyticsEvent | `analytics_events` | platform | envelope_hash, source_domain, event_type, entity_type, entity_id, payload:array, idempotency_key, processed:boolean, processed_at:datetime, processing_error, event_id, event_name, aggregate_id, occurred_at:datetime | `app/Models/PlatformModule/AnalyticsEvent.php` |
| AuditCorrelation | `audit_correlations` | platform | correlation_id, origin_domain, description, related_entities:array | `app/Models/PlatformModule/AuditCorrelation.php` |
| AuditLog | `audit_logs` | platform | envelope_hash, actor_id, actor_role, action, entity_type, entity_id, before:array, after:array, ip_address, device, correlation_id, source_domain, reason, event_id, occurred_at:datetime | `app/Models/PlatformModule/AuditLog.php` |
| Catalog | `catalogs` | platform | name, code, items:array, description | `app/Models/PlatformModule/Catalog.php` |
| CommunityNotice | `community_notices (convención)` | platform | — | `app/Models/PlatformModule/CommunityNotice.php` |
| ConfigurationVersion | `configuration_versions` | platform | config_key, version:integer, value:array, status, created_by, approved_by:array, approved_at:datetime, rejected_reason, activated_at:datetime, superseded_at:datetime, superseded_by_version | `app/Models/PlatformModule/ConfigurationVersion.php` |
| EarningRule | `earning_rules` | platform | business_id, name, type, points_per_unit:integer, unit_amount:float, fixed_points:integer, multiplier:float, excluded_products:array, max_points_per_operation:integer, daily_cap:integer, active_from:datetime, active_to:datetime, status | `app/Models/PlatformModule/EarningRule.php` |
| FraudFlag | `fraud_flags` | platform | student_id, business_id, reason, severity, related_ledger_id, related_redemption_id, status, reviewed_by, reviewed_at:datetime, notes | `app/Models/PlatformModule/FraudFlag.php` |
| IntegrationLog | `integration_logs` | platform | domain, direction, endpoint, status, attempt:integer, request_payload:array, response_payload:array, error_message, correlation_id | `app/Models/PlatformModule/IntegrationLog.php` |
| PointCampaign | `point_campaigns` | platform | name, description, type, sponsor_type, sponsor_id, multiplier:float, starts_at:datetime, ends_at:datetime, status, scope, scope_ids:array | `app/Models/PlatformModule/PointCampaign.php` |
| PointsAccount | `points_accounts` | platform | student_id, balance:integer, pending_balance:integer, lifetime_earned:integer, lifetime_redeemed:integer, last_ledger_entry_id | `app/Models/PlatformModule/PointsAccount.php` |
| PointsLedger | `points_ledger` | platform | student_id, idempotency_key, type, amount:integer, source_domain, source_reference, related_ledger_id, rule_id, business_id, description, metadata:array | `app/Models/PlatformModule/PointsLedger.php` |
| PointsPolicy | `points_policies` | platform | name, type, expiration_days:integer, expiration_batch_strategy, daily_cap_points:integer, max_redemptions_per_day:integer, reversal_grace_period_days:integer, status, config:array | `app/Models/PlatformModule/PointsPolicy.php` |
| Redemption | `redemptions` | platform | idempotency_key, request_hash, student_id, reward_id, reward_item_id, points_spent:integer, status, redemption_code, ledger_entry_id, stock_reservation_reference, requested_at:datetime, delivered_at:datetime, cancelled_at:datetime, cancellation_reason | `app/Models/PlatformModule/Redemption.php` |
| RedemptionEvent | `redemption_events` | platform | redemption_id, from_status, to_status, actor_id, actor_role, note, metadata:array | `app/Models/PlatformModule/RedemptionEvent.php` |
| Reward | `rewards` | platform | business_id, name, description, points_required:integer, stock:integer, reserved_stock:integer, linked_product_reference, is_physical:boolean, valid_from:datetime, valid_to:datetime, limit_per_user:integer, limit_period_days:integer, status, images:array | `app/Models/PlatformModule/Reward.php` |
| RewardItem | `reward_items` | platform | reward_id, variant_name, sku_reference, stock:integer, reserved_stock:integer | `app/Models/PlatformModule/RewardItem.php` |
| RewardProgramMembership | `reward_program_memberships` | platform | business_id, business_name, category, contact_email, status, compensation_model, accepted_terms_at:datetime, joined_at:datetime, deactivated_at:datetime, deactivation_reason, metadata:array | `app/Models/PlatformModule/RewardProgramMembership.php` |
| Setting | `settings` | platform | key, value:array, type, description, is_sensitive:boolean | `app/Models/PlatformModule/Setting.php` |
| WebhookEvent | `webhook_events` | platform | source, event_id, event_type, payload:array, status, retry_count:integer, next_retry_at:datetime, processed_at:datetime | `app/Models/PlatformModule/WebhookEvent.php` |

## Tablas SQL Server (E2) declaradas en migraciones

| Tabla | Migración | Columnas (tipo) |
|---|---|---|
| `bonus_administrative_operations` | 2026_10_10_010000_create_bonus_administrative_operations.php | public_id:uuid, bonus_id:uuid, action:string, actor_id:string, idempotency_key:string, request_hash:string, reason:text, request_data:longText, before_data:longText, after_data:longText, created_at:timestamp |
| `bonus_budget_commitments` | 2026_11_07_230000_create_bonus_budget_commitments.php | public_id:uuid, budget_id:uuid, award_reference:string, bonus_id:uuid, issue_operation_id:uuid, amount_cents:bigInteger, currency:string, status:string |
| `cash_administrative_requests` | 2026_10_09_235000_create_cash_administrative_requests.php | public_id:uuid, cash_shift_id:foreignId, operation:string, amount_cents:bigInteger, currency:string, refund_request_id:uuid, operator_id:string, student_id:string, reason:text, request_key:string, request_hash:string, supervisor_required:boolean, approval_policy_snapshot:text, status:string, supervisor_id:string, supervisor_reason:text, supervisor_reviewed_at:timestamp, expires_at:timestamp, cancelled_at:timestamp, consumed_at:timestamp, settlement_key:string, cash_movement_id:uuid |
| `cash_approval_policies` | 2026_10_09_230000_create_cash_approval_policies.php | association_id:string, operation:string, currency:string, enabled:boolean, threshold_cents:bigInteger, version:integer |
| `cash_approval_policy_changes` | 2026_10_09_230000_create_cash_approval_policies.php | cash_approval_policy_id:foreignId, idempotency_key:string, request_hash:string, actor_id:string, reason:text, before_snapshot:text, after_snapshot:text, created_at:timestamp |
| `cash_movements` | 2026_10_09_180000_create_audited_cash_tables.php | public_id:uuid, cash_shift_id:foreignId, type:string, amount_cents:bigInteger, idempotency_key:string, request_hash:string, actor_id:string, reason:text, wallet_id:uuid, reference_type:string, reference_id:string |
| `cash_operation_confirmations` | 2026_10_09_220000_create_cash_operation_confirmations.php | public_id:uuid, cash_shift_id:foreignId, wallet_id:uuid, operator_id:string, student_id:string, operation:string, amount_cents:bigInteger, currency:string, reason:text, status:string, request_key:string, request_hash:string, expires_at:timestamp, confirmed_at:timestamp, rejected_at:timestamp, cancelled_at:timestamp, confirmed_by:string, confirmation_ip:string, settlement_key:string, cash_movement_id:uuid, consumed_at:timestamp |
| `cash_receipts` | 2026_10_09_200000_create_cash_receipts_table.php | public_id:uuid, cash_movement_id:foreignId, folio:string, snapshot:text, issued_at:timestamp |
| `cash_register_changes` | 2026_10_09_190000_add_cash_register_administration.php | public_id:uuid, cash_register_id:foreignId, version:unsignedInteger, action:string, before_state:text, after_state:text, actor_id:string, reason:text, idempotency_key:string, request_hash:string |
| `cash_registers` | 2026_10_09_180000_create_audited_cash_tables.php | public_id:uuid, association_id:string, name:string, currency:string, status:string, created_by:string |
| `cash_shifts` | 2026_10_09_180000_create_audited_cash_tables.php | public_id:uuid, cash_register_id:foreignId, agent_id:string, opening_amount_cents:bigInteger, status:string, opening_key:string, closing_key:string, closed_by:string, closing_reason:text, opened_at:timestamp, closed_at:timestamp |
| `event_payment_requests` | 2026_11_08_000001_create_event_payment_requests.php | public_id:uuid, source_reference:string, client_id:string, status:string, student_id:string, event_id:string, organization_id:string, amount_cents:bigInteger, reservation_expires_at:string, confirmed_at:string, recipient_wallet_id:uuid, transaction_id:uuid |
| `financial_community_notice_scans` | 2026_11_10_000100_create_financial_community_notices.php | tipo:string, since:string, after_id:unsignedBigInteger, through_id:unsignedBigInteger |
| `financial_community_notices` | 2026_11_10_000100_create_financial_community_notices.php | tipo:string, operation_id:uuid, idempotency_key:string, payload:text, status:string, attempts:unsignedInteger, next_attempt_at:dateTime, lease_token:uuid, locked_until:dateTime, last_error:string, notification_id:string, delivered_at:dateTime |
| `financial_team4_attempts` | 2026_10_21_180000_create_team4_financial_outbox.php | event_id:uuid, attempt_no:unsignedInteger, lease_token:uuid, started_at:timestamp, finished_at:timestamp, http_status:unsignedSmallInteger, result_code:string |
| `financial_team4_operator_actions` | 2026_10_26_180000_create_team4_event_operator_actions.php | action_id:uuid, event_id:uuid, action:string, request_hash:string, operator_label:string, reason:string, body_hash:string, from_state:string, to_state:string, attempts:unsignedInteger, previous_code:string, application_environment:string, created_at:timestamp |
| `financial_team4_outbox` | 2026_10_21_180000_create_team4_financial_outbox.php | event_id:uuid, stream_id:unsignedBigInteger, event_version:unsignedBigInteger, source_key:string, request_hash:string, body:text, body_hash:string, state:string, attempts:unsignedInteger, available_at:timestamp, lease_token:uuid, lease_until:timestamp, delivered_at:timestamp, last_code:string |
| `financial_team4_streams` | 2026_10_21_180000_create_team4_financial_outbox.php | payment_intent_id:string, order_id:string, business_id:string, last_version:unsignedBigInteger |
| `institutional_bonus_budgets` | 2026_11_07_220000_create_institutional_bonus_budgets.php | public_id:uuid, institution_reference:string, approval_reference:string, payer_reference:string, purpose:string, bonus_type:string, currency:string, status:string, approved_amount_cents:bigInteger, committed_amount_cents:bigInteger, consumed_amount_cents:bigInteger, version:bigInteger, valid_from:dateTime, expires_at:dateTime |
| `institutional_bonus_obligations` | 2026_11_07_240000_create_institutional_bonus_obligations.php | public_id:uuid, purchase_payment_id:uuid, bonus_id:uuid, budget_id:uuid, operation_id:uuid, amount_cents:bigInteger, refunded_amount_cents:bigInteger, currency:string, business_id:string, payer_reference:string, status:string |
| `institutional_bonus_refunds` | 2026_11_07_240000_create_institutional_bonus_obligations.php | public_id:uuid, obligation_id:uuid, refund_request_id:uuid, operation_id:uuid, amount_cents:bigInteger, created_at:timestamp |
| `institutional_bonus_settlements` | 2026_11_07_250000_create_institutional_bonus_settlements.php | public_id:uuid, obligation_id:uuid, budget_id:uuid, operation_id:uuid, amount_cents:bigInteger, currency:string, payment_provider:string, payment_reference:string, payment_key:string, recipient_reference:string, paid_at_utc:string, created_at:timestamp |
| `institutional_budget_operations` | 2026_11_07_220000_create_institutional_bonus_budgets.php | public_id:uuid, budget_id:uuid, action:string, actor_id:string, reason:string, idempotency_key:string, request_hash:string, before_data:text, after_data:text, created_at:timestamp |
| `legacy_cash_receipt_reviews` | 2026_10_12_010000_create_legacy_cash_receipt_reviews.php | public_id:uuid, transaction_id:uuid, wallet_id:uuid, classification:string, reviewed_by:string, reason:string, evidence_hash:string, snapshot:text, reviewed_at:timestamp |
| `library_fine_payment_requests` | 2026_11_07_000002_create_library_fine_payment_requests.php | public_id:uuid, source_reference:string, client_id:string, status:string, student_id:string, amount_cents:bigInteger, recipient_wallet_id:uuid, transaction_id:uuid |
| `marketplace_payment_requests` | 2026_10_21_210000_create_marketplace_payment_requests.php | public_id:uuid, client_id:string, buyer_id:string, business_id:string, order_id:string, origin_key:char, key_hash:char, request_hash:char, payload:text, amount_cents:bigInteger, currency:string, status:string, expires_at:timestamp, purchase_payment_id:uuid, confirmed_at:timestamp, audit_context:text |
| `purchase_marketplace_links` | 2026_10_21_190000_create_purchase_marketplace_links.php | purchase_payment_id:uuid, payment_intent_id:string, order_id:string, business_id:string, order_key:char, context_hash:char, items:text |
| `receipt_user_delegations` | 2026_10_12_020000_create_receipt_user_delegations.php | public_id:uuid, proof_hash:string, user_id:string, client_id:string, client_name:string, identity_session_id:string, native_session_id:text, action:string, request_path:string, request_query:text, request_digest:string, expires_at:timestamp, revoked_at:timestamp |
| `student_service_payment_requests` | 2026_11_10_000001_create_student_service_payment_requests.php | public_id:uuid, concept:string, source_reference:string, client_id:string, status:string, student_id:string, amount_cents:bigInteger, recipient_wallet_id:uuid, transaction_id:uuid |
| `student_transfer_confirmations` | 2026_10_10_030000_create_student_transfer_confirmations.php | public_id:uuid, request_key:string, request_hash:string, sender_id:string, recipient_id:string, source_wallet_id:uuid, destination_wallet_id:uuid, kind:string, amount_cents:bigInteger, concept:string, identity_method:string, recipient_snapshot:text, policy_snapshot:text, status:string, duration_seconds:integer, expires_at:timestamp, confirmed_at:timestamp, cancelled_at:timestamp, requested_ip:string, confirmed_ip:string, correlation_id:string, execution_key:string, student_transfer_id:uuid |
| `student_transfer_policies` | 2026_10_10_020000_create_student_transfer_tables.php | policy_key:string, enabled:boolean, business_timezone:string, version:integer, updated_by:string |
| `student_transfer_policy_changes` | 2026_10_10_020000_create_student_transfer_tables.php | public_id:uuid, policy_key:string, version:integer, actor_id:string, reason:text, before_data:text, after_data:text |
| `student_transfers` | 2026_10_10_020000_create_student_transfer_tables.php | public_id:uuid, idempotency_key:string, request_hash:string, source_wallet_id:uuid, destination_wallet_id:uuid, sender_id:string, recipient_id:string, actor_id:string, kind:string, amount_cents:bigInteger, currency:string, concept:string, status:string, financial_transaction_id:uuid, policy_version:integer, policy_snapshot:text, completed_at:timestamp |
| `wallet_hold_policies` | 2026_10_09_160000_create_wallet_hold_policies.php | public_id:uuid, operation_type:string, max_duration_seconds:integer, active:boolean, version:integer, created_by:string, updated_by:string |
| `wallet_hold_policy_changes` | 2026_10_09_160000_create_wallet_hold_policies.php | public_id:uuid, policy_id:uuid, version:integer, change_type:string, actor_id:string, reason:text, correlation_id:string, before:json, after:json |
| `wallet_holds` | 2026_10_09_140000_create_wallet_holds_and_ledger_deltas.php | public_id:uuid, wallet_id:uuid, amount_cents:bigInteger, currency:string, operation_type:string, reference_type:string, reference_id:string, status:string, expires_at:dateTime, requested_by:string, reason:string, hold_transaction_id:uuid, closing_transaction_id:uuid, closed_by:string, closing_reason:string, closed_at:dateTime |
| `wallets` | 2026_09_23_182121_create_wallets_collection.php | public_id:uuid, owner_type:string, owner_id:string, type:string, currency:string, status:string, available_balance_cents:bigInteger, held_balance_cents:bigInteger |

## Colecciones Mongo con migración de índices

| Colección | Migración |
|---|---|
| `academic_status_history` | 2026_09_17_000800_create_student_services_collections.php |
| `analytics_events` | 2026_09_11_000010_create_integration_and_analytics_collections.php |
| `audit_correlations` | 2026_09_11_000009_create_audit_logs_collections.php |
| `audit_logs` | 2026_09_11_000009_create_audit_logs_collections.php |
| `catalogs` | 2026_09_11_000008_create_settings_and_catalogs_collections.php |
| `comisiones_consejo` | 2026_10_16_000000_create_comisiones_consejo.php |
| `communication_preferences` | 2026_09_17_000800_create_student_services_collections.php |
| `configuration_versions` | 2026_09_11_000011_create_configuration_versions_collection.php |
| `consents` | 2026_09_17_000800_create_student_services_collections.php |
| `credential_events` | 2026_09_05_000643_create_credential_events_table.php |
| `delegaciones_organizacion` | 2026_10_15_000000_create_delegaciones_organizacion.php |
| `devices` | 2026_02_01_000500_create_devices_collection.php |
| `documentos_organizacion` | 2026_10_16_000100_create_documentos_organizacion.php |
| `earning_rules` | 2026_09_11_000004_create_earning_rules_and_campaigns_collections.php |
| `fraud_flags` | 2026_09_11_000007_create_points_policies_and_fraud_flags_collections.php |
| `integrantes_comision` | 2026_10_16_000000_create_comisiones_consejo.php |
| `integration_logs` | 2026_09_11_000010_create_integration_and_analytics_collections.php |
| `nfc_cards` | 2026_09_05_000620_create_nfc_cards_table.php |
| `periodos_organizacion` | 2026_10_16_000200_create_periodos_organizacion.php |
| `point_campaigns` | 2026_09_11_000004_create_earning_rules_and_campaigns_collections.php |
| `points_accounts` | 2026_09_11_000001_create_points_accounts_collection.php |
| `points_ledger` | 2026_09_11_000002_create_points_ledger_collection.php |
| `points_policies` | 2026_09_11_000007_create_points_policies_and_fraud_flags_collections.php |
| `qr_tokens` | 2026_02_01_000300_create_qr_tokens_collection.php |
| `qr_validations` | 2026_02_01_000400_create_qr_validations_collection.php |
| `recomendaciones_becas` | 2026_10_17_000300_create_recomendaciones_becas.php |
| `redemption_events` | 2026_09_11_000006_create_redemptions_collections.php |
| `redemptions` | 2026_09_11_000006_create_redemptions_collections.php |
| `reward_items` | 2026_09_11_000005_create_rewards_and_reward_items_collections.php |
| `reward_program_memberships` | 2026_09_11_000003_create_reward_program_memberships_collection.php |
| `rewards` | 2026_09_11_000005_create_rewards_and_reward_items_collections.php |
| `security_events` | 2026_02_01_000700_create_security_events_collection.php |
| `sessions` | 2026_02_01_000600_create_sessions_collection.php |
| `settings` | 2026_09_11_000008_create_settings_and_catalogs_collections.php |
| `solicitudes_reservas_eventos` | 2026_10_17_000100_create_solicitudes_reservas_eventos.php |
| `versiones_documentos_organizacion` | 2026_10_16_000100_create_documentos_organizacion.php |
| `webhook_events` | 2026_09_11_000010_create_integration_and_analytics_collections.php |
