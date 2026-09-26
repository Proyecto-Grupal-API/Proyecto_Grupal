<?php

namespace App\Enums;

enum ConsentType: string
{
    case Terms = 'terms';
    case Privacy = 'privacy';
    case Marketing = 'marketing';

    public function requiresVersion(): bool
    {
        return $this !== self::Marketing;
    }
}
