<?php

namespace App\Domains\Financial\Exceptions;

use RuntimeException;

/**
 * Un módulo externo (por ejemplo identidad/roles) no respondió.
 * La operación financiera se rechaza completa; no se registran
 * movimientos parciales.
 */
class FinancialDependencyUnavailableException extends RuntimeException
{
}
