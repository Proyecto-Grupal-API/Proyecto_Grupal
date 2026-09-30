<?php

namespace App\Services\PointsLedger\Exception;

use RuntimeException;

class NoActiveEarningRuleException extends RuntimeException
{
    public static function forBusiness(string $businessId): self
    {
        return new self("No hay una EarningRule activa para el negocio [{$businessId}] en la fecha de la operación.");
    }
}
