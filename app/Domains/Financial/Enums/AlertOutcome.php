<?php

namespace App\Domains\Financial\Enums;

/**
 * Resultado de la operación que originó una alerta (evidencia).
 */
enum AlertOutcome: string
{
    // El control preventivo rechazó la solicitud; no hubo movimiento.
    case BLOQUEADA = 'BLOQUEADA';

    // Un límite BLOQUEAR quedó rebasado por un movimiento ya registrado:
    // la operación no tiene control preventivo o hubo una carrera entre
    // solicitudes (no existe reserva atómica). No se modifica el movimiento.
    case EXCEDENTE_NO_PREVENIDO = 'EXCEDENTE_NO_PREVENIDO';

    // Un límite ALERTAR se rebasó; el movimiento quedó registrado.
    case REGISTRADA_CON_ALERTA = 'REGISTRADA_CON_ALERTA';

    // El movimiento quedó registrado pero no se pudo evaluar.
    case NO_EVALUADA = 'NO_EVALUADA';
}
