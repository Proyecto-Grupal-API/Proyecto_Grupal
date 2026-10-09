<?php

namespace App\Domains\Financial\Enums;

enum AlertType: string
{
    case LIMITE_EXCEDIDO = 'LIMITE_EXCEDIDO';
    case OPERACION_BLOQUEADA = 'OPERACION_BLOQUEADA';

    // El movimiento quedó registrado pero no pudo evaluarse contra los
    // límites (p. ej. el proveedor de roles no respondió). Se registra
    // para que el control nunca se omita en silencio.
    case CONTROL_NO_EVALUADO = 'CONTROL_NO_EVALUADO';
}
