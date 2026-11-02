<?php

namespace App\Support;

use MongoDB\BSON\Regex;

class NfcUid
{
    public static function normalize(string $uid): string
    {
        return strtoupper(trim($uid));
    }

    /** Match legacy UIDs written before canonicalization without changing them. */
    public static function legacyMatch(string $uid): Regex
    {
        return new Regex('^\s*'.preg_quote($uid, '/').'\s*$', 'i');
    }
}
