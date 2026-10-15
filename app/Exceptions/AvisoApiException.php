<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class AvisoApiException extends HttpException
{
    public function __construct(int $status, public readonly string $codigo, string $mensaje)
    {
        parent::__construct($status, $mensaje);
    }
}
