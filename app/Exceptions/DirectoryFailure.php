<?php

namespace App\Exceptions;

use RuntimeException;

/** Public codes only: never attach driver exceptions, queries or submitted values. */
final class DirectoryFailure extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, public readonly int $retryAfter = 0)
    {
        parent::__construct($errorCode);
    }
}
