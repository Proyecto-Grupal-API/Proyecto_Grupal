# Módulo 2.9 — Tickets y comprobantes

## Alcance adoptado

La documentación de continuidad identifica el submódulo 2.9 como **Tickets y comprobantes** y su responsabilidad como **evidencia de operaciones**.

El documento no define un contrato detallado ni confirma una tabla `financial_receipts`. Por esa razón, esta implementación no crea una nueva fuente de verdad ni duplica información contable.

El comprobante se construye de forma de solo lectura a partir de:

- `FinancialTransaction`
- `LedgerEntry`
- `Wallet` únicamente para obtener la moneda del movimiento

El ledger continúa siendo la fuente de verdad contable.

## API

### Consultar comprobante de una transacción

```http
GET /api/v1/financial/transactions/{transactionId}/receipt
Authorization: Bearer <token con financial:read>
X-Request-Id: opcional
```

No requiere `Idempotency-Key` porque es una consulta sin efectos financieros.

Respuesta:

```json
{
  "data": {
    "receipt_id": "uuid-transaccion",
    "transaction_id": "uuid-transaccion",
    "status": "COMPLETADA",
    "reference_type": "TOPUP",
    "reference_id": "uuid-referencia",
    "original_transaction_id": null,
    "issued_at": "2026-10-07T18:00:00.000000Z",
    "entries": [
      {
        "id": "uuid-entry",
        "wallet_id": "uuid-wallet",
        "currency": "MXN",
        "movement_type": "RECARGA",
        "amount_cents": 10000,
        "balance_after_cents": 10000,
        "available_balance_after_cents": 10000,
        "held_balance_after_cents": 0,
        "created_at": "2026-10-07T18:00:00.000000Z"
      }
    ]
  },
  "meta": {
    "request_id": "uuid",
    "api_version": "v1"
  }
}
```

Si la transacción existe pero todavía no tiene movimientos en el ledger, la API devuelve conflicto `409` porque todavía no hay evidencia contable con la cual construir el comprobante.

Los recursos inexistentes conservan el comportamiento `404` del proyecto.

## Frontend Vue/Inertia

Se agregan dos vistas:

```text
/finanzas/comprobantes
/finanzas/comprobantes/{transactionId}
```

La primera lista los movimientos de la wallet del usuario autenticado. La segunda genera la vista imprimible del comprobante.

Para no exponer operaciones ajenas, el detalle comprueba que exista un `LedgerEntry` de esa transacción asociado a la wallet del usuario.

La vista de detalle incluye **Imprimir / guardar PDF**, usando la impresión del navegador. No se introduce una librería de PDF porque el requisito recuperado no especifica un formato PDF generado por servidor.

## Decisiones de arquitectura

- No se modifican saldos.
- No se modifica `LedgerService`.
- No se modifica `WalletService`.
- No se agregan migraciones.
- No se crea una tabla `financial_receipts` sin un requisito confirmado.
- El comprobante reutiliza la transacción y el ledger existentes.
- La API permanece bajo `/api/v1/financial`.
- La consulta API requiere `financial:read`.
- La interfaz Vue no calcula saldos ni reconstruye operaciones: solo presenta datos entregados por Laravel.

## Archivos del submódulo

- `app/Domains/Financial/Services/ReceiptService.php`
- `app/Http/Controllers/Financial/ReceiptController.php`
- `app/Http/Controllers/Financial/FinancialReceiptController.php`
- `resources/js/Pages/Financial/Receipts.vue`
- `resources/js/Pages/Financial/Receipt.vue`
- `routes/api.php`
- `routes/web.php`
- `tests/Feature/Financial/ReceiptServiceTest.php`
- `tests/Feature/Financial/ReceiptApiTest.php`
- `docs/financial-submodule-2.9.md`
