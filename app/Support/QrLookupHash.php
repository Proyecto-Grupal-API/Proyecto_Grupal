<?php

namespace App\Support;

use RuntimeException;

class QrLookupHash
{
    public static function code(string $code): string
    {
        return self::digest('qr-code', $code);
    }

    public static function shortCode(string $shortCode): string
    {
        return self::digest('qr-short', $shortCode);
    }

    private static function digest(string $domain, string $value): string
    {
        $key = config('qr.lookup_key');

        if (! is_string($key) || strlen($key) < 32) {
            throw new RuntimeException('QR lookup key is missing or too short. Configure QR_LOOKUP_KEY or APP_KEY.');
        }

        return hash_hmac('sha256', $domain."\0".$value, $key);
    }
}
