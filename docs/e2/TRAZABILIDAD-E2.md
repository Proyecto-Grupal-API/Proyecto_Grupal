# Trazabilidad E2 — compromisos → código → pruebas → commits

| Versión | Fecha | Responsables | Cambio |
|---|---|---|---|
| 1.0 | 2026-11-12 | Equipo 2 (autores en Git: Daniel Beltrán, Codex, Daniel Beltran, Viko43, Julian-Darkstar); con Claude Code | Corte MERGE_COMPLETO ffb4abd + parche de cierre; resultados del laboratorio aislado |

Fuentes de requisitos: docs/modulo-2/estado-modulo-2-2026-11-10.md (apartados 2.1–2.10 del diseño), docs/modulo-2/2.5-*.md, contratos docs/integration/API_E2_*.md y CONTRATO_PAGO_EVENTOS_E2_E6.md. Resultados: suite Feature completa (perfil integrated-financial) y Unit. Commits: git log real.

| Req. | Compromiso | Fuente | Estado | Pruebas (resultado) | Evidencia | Commits | Primer → último |
|---|---|---|---|---|---|---|---|
| 2.1 | Cuentas wallet | Diseño 2.1 | Implementado | WalletPurchaseServiceTest, MarketplaceWalletCheckoutTest (20 OK / 0 fallos) | Panel /finanzas (saldo 75,00 = ledger) | 1 | 52edb36 → 52edb36 |
| 2.2 | Ledger de movimientos | Diseño 2.2 | Implementado | WalletPurchaseServiceTest, InstitutionalBudgetIntegrityTest (60 OK / 0 fallos) | D02 −2500/+2500 | 3 | 52edb36 → 814f4ad |
| 2.3 | Recargas de dinero digital | Diseño 2.3 | Implementado | TopUpWithdrawalLimitsTest, CashShiftReconciliationTest (8 OK / 0 fallos) | Recarga en caja conciliada (CashShiftReconciliation) y reglas de solicitud/límite (TopUpWithdrawalLimits) | 1 | 52edb36 → 52edb36 |
| 2.4 | Retiros en asociaciones | Diseño 2.4 | Implementado | TopUpWithdrawalLimitsTest, CashShiftReconciliationTest (8 OK / 0 fallos) | Retiro por transferencia debita una vez; saldo insuficiente sin efectos; efectivo sólo por caja | 1 | 52edb36 → 52edb36 |
| 2.5 | Bonos y saldos restringidos (BECA) | Diseño 2.5 + docs/modulo-2/2.5-* | Implementado | BonusAdministrationWebTest, InstitutionalBecaIssuanceTest, InstitutionalBecaPurchaseTest, InstitutionalBecaCheckoutTest, InstitutionalBecaReleaseTest, InstitutionalBecaExpirationTest, InstitutionalClosedBecaRefundTest, InstitutionalBudgetServiceTest (134 OK / 0 fallos) |  | 5 | 52edb36 → 6017e73 |
| 2.6 | Transferencias y regalos de saldo | Diseño 2.6 | Implementado | PersonalWalletTransferAuthorizationTest, TransferIdentityBoundaryTest (3 OK / 0 fallos) |  | 18 | 52edb36 → 0963365 |
| 2.7 | Retenciones, devoluciones y reversos | Diseño 2.7 | Implementado | RentalDepositSettlementTest, RentalDepositPilotTest, InstitutionalClosedBecaRefundTest, MarketplaceCheckoutCancellationTest (23 OK / 0 fallos) | D02 devolución E2 | 2 | 52edb36 → d194239 |
| 2.8 | Caja de asociaciones y turnos | Diseño 2.8 | Implementado | CashShiftReconciliationTest, DelegacionesComunidadTest (11 OK / 0 fallos) |  | 2 | 52edb36 → 0641a56 |
| 2.9 | Tickets y comprobantes financieros | Diseño 2.9 | Implementado | BeneficiosServiciosReceiptTest, InstitutionalReceiptTest, PersonalReceiptVerificationTest (69 OK / 0 fallos) | UI /finanzas/comprobantes | 2 | 52edb36 → 2cca14e |
| 2.10 | Límites, alertas y conciliación | Diseño 2.10 | Implementado | TopUpWithdrawalLimitsTest, WalletPurchaseServiceTest, CashShiftReconciliationTest (21 OK / 0 fallos) | Límites BLOQUEAR con alerta del intento, ALERTAR no bloquea; conciliación de caja; UI /finanzas/controles | 3 | 52edb36 → 93e13af |
| E2-X | Checkout y avisos para E3/E5/E6 | Contratos docs/integration | Implementado | EventCheckoutTest, StudentServiceCheckoutTest, LibraryFineCheckoutTest, FinancialCommunityInboxTest, PurchaseMarketplaceEventsTest (21 OK / 0 fallos) | D02, F05 | 5 | 42496fa → 0963365 |

Código por compromiso: columna `codigo` del CSV.
Nota: Las 6 pruebas listadas en tests_fixed_after_run fallaron en la primera pasada por defectos de prueba preexistentes y se corrigieron y re-ejecutaron en verde (checkpoint 14).
