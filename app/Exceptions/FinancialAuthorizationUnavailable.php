<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class FinancialAuthorizationUnavailable extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Authorization infrastructure unavailable.', 0, $previous);
    }
}
