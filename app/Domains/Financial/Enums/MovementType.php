<?php

namespace App\Domains\Financial\Enums;

enum MovementType: string
{
    case RECARGA = 'RECARGA';
    case PAGO = 'PAGO';
    case RETIRO = 'RETIRO';

    case TRANSFERENCIA_SALIDA = 'TRANSFERENCIA_SALIDA';
    case TRANSFERENCIA_ENTRADA = 'TRANSFERENCIA_ENTRADA';

    case DEVOLUCION = 'DEVOLUCION';
    case REVERSO = 'REVERSO';

    case RETENCION = 'RETENCION';
    case LIBERACION = 'LIBERACION';

    case AJUSTE_CREDITO = 'AJUSTE_CREDITO';
    case AJUSTE_DEBITO = 'AJUSTE_DEBITO';
}