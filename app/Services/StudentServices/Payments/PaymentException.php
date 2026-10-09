<?php

namespace App\Services\StudentServices\Payments;

use RuntimeException;

/**
 * Rechazo de una operación de dinero (saldo insuficiente, retención cerrada,
 * importe inválido). Es RuntimeException para que los controladores del
 * Equipo 5 la muestren como cualquier otra regla de negocio.
 */
class PaymentException extends RuntimeException {}
