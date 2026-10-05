# Módulo 2.7 — Retenciones, devoluciones y reversos

Este documento describe el contrato implementado para el submódulo financiero 2.7.

## API

Todas las rutas están bajo `/api/v1/financial` y conservan el mecanismo OAuth de servicio existente.

| Método | Ruta | Scope | Uso |
|---|---|---|---|
| POST | `/wallets/{walletId}/holds` | `financial:write` | Crear una retención |
| POST | `/holds/{transactionId}/release` | `financial:write` | Liberar una retención |
| POST | `/transactions/{transactionId}/refund` | `financial:write` | Solicitar una devolución |
| POST | `/transactions/{transactionId}/reverse` | `financial:write` | Ejecutar un reverso autorizado |

Las operaciones con efectos financieros requieren `Idempotency-Key`.

## Retención

Entrada:

```json
{
  "amount_cents": 10000,
  "reference_type": "PURCHASE",
  "reference_id": "purchase-123",
  "metadata": {}
}
```

La wallet pasa de `available_balance_cents` a `held_balance_cents` dentro de la misma transacción SQL Server y se registra un `RETENCION` en el ledger.

## Liberación

```http
POST /api/v1/financial/holds/{transactionId}/release
Idempotency-Key: unique-key
```

La liberación crea una nueva `FinancialTransaction` relacionada con la retención original mediante `original_transaction_id` y registra `LIBERACION` en el ledger.

## Devolución

```json
{
  "amount_cents": 5000,
  "reason": "Compra cancelada"
}
```

La operación implementada es **solicitud de devolución**: crea una transacción `PENDIENTE` y no mueve saldo. La aprobación posterior por negocio/administrador queda deliberadamente fuera del cambio porque la documentación del proyecto no fija todavía su rol, estados ni flujo exactos.

Las solicitudes acumuladas no pueden superar el importe negativo original de una operación `PAGO`, `RETIRO` o `TRANSFERENCIA_SALIDA`.

## Reverso

```json
{
  "reason": "Corrección administrativa"
}
```

El reverso crea movimientos `REVERSO` inversos a todos los asientos de la transacción original, cambia el estado original a `REVERTIDA` y mantiene la relación mediante `original_transaction_id`. Retenciones y liberaciones no se reversan genéricamente; una retención debe pasar por su liberación correspondiente.

## Respuesta

Las respuestas exitosas usan el contrato existente:

```json
{
  "data": {
    "id": "uuid",
    "status": "COMPLETADA",
    "reference_type": "REVERSO",
    "reference_id": "uuid",
    "original_transaction_id": "uuid",
    "metadata": {},
    "entries": []
  },
  "meta": {
    "request_id": "uuid",
    "api_version": "v1"
  }
}
```

Los conflictos financieros devuelven `409`; la validación de entrada usa `422` y los recursos inexistentes mantienen `404`.

## Frontend Vue/Inertia

La pantalla `/finanzas/ajustes` permite al usuario solicitar una devolución sobre movimientos de su propia wallet que sean elegibles. La interfaz no expone la ejecución de reversos ni la creación/liberación de retenciones, porque la autorización de esos procesos corresponde al dominio financiero y no está definida como una acción del usuario normal.
