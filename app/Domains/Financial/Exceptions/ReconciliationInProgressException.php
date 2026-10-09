<?php

namespace App\Domains\Financial\Exceptions;

use RuntimeException;

/**
 * Ya existe una conciliación en curso para la misma fecha de negocio.
 * Se rechaza la nueva ejecución para no producir corridas incompatibles.
 */
class ReconciliationInProgressException extends RuntimeException
{
    public const CODE = 'RECONCILIATION_IN_PROGRESS';

    public function __construct(
        public readonly string $businessDate
    ) {
        parent::__construct(
            "Ya hay una conciliación en curso para {$businessDate}."
        );
    }
}
