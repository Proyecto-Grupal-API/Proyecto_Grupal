<?php

namespace App\Domains\Financial\Enums;

enum ReconciliationCheck: string
{
    case RETENIDO_WALLET_VS_LEDGER = 'RETENIDO_WALLET_VS_LEDGER';
    case SALDO_WALLET_VS_LEDGER = 'SALDO_WALLET_VS_LEDGER';
    case SALDO_WALLET_VS_ULTIMO_MOVIMIENTO = 'SALDO_WALLET_VS_ULTIMO_MOVIMIENTO';
    case RETENIDO_WALLET_VS_ULTIMO_MOVIMIENTO = 'RETENIDO_WALLET_VS_ULTIMO_MOVIMIENTO';
    case TRANSACCION_PENDIENTE = 'TRANSACCION_PENDIENTE';
    case TRANSACCION_SIN_MOVIMIENTOS = 'TRANSACCION_SIN_MOVIMIENTOS';
    case TRANSFERENCIA_DESCUADRADA = 'TRANSFERENCIA_DESCUADRADA';
    case RECARGA_SIN_MOVIMIENTO = 'RECARGA_SIN_MOVIMIENTO';
    case RECARGA_MONTO_DIFERENTE = 'RECARGA_MONTO_DIFERENTE';
    case RETIRO_SIN_MOVIMIENTO = 'RETIRO_SIN_MOVIMIENTO';
    case RETIRO_MONTO_DIFERENTE = 'RETIRO_MONTO_DIFERENTE';
    case COMPRA_TOTAL_DESCUADRADO = 'COMPRA_TOTAL_DESCUADRADO';
    case COMPRA_SIN_MOVIMIENTO_WALLET = 'COMPRA_SIN_MOVIMIENTO_WALLET';
    case COMPRA_MONTO_WALLET_DIFERENTE = 'COMPRA_MONTO_WALLET_DIFERENTE';
    case COMPRA_BONOS_DESCUADRADOS = 'COMPRA_BONOS_DESCUADRADOS';
    case BONO_SALDO_VS_LEDGER = 'BONO_SALDO_VS_LEDGER';

    // Diferencias reportadas por la fuente de caja (2.8). Solo se ejecuta
    // cuando CashReconciliationSource::isIntegrated() es true.
    case CAJA_DIFERENCIA = 'CAJA_DIFERENCIA';

    /**
     * Comprobaciones internas del módulo (siempre se ejecutan).
     *
     * @return array<int, self>
     */
    public static function internal(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $c) => $c !== self::CAJA_DIFERENCIA
        ));
    }
}
