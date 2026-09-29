<?php

namespace App\Services;

class TemporaryPasswordGenerator
{
    public function generate(): string
    {
        // 192 bits from the operating system CSPRNG; printable without ambiguous characters.
        return bin2hex(random_bytes(24));
    }
}
