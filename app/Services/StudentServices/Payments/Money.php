<?php

namespace App\Services\StudentServices\Payments;

use InvalidArgumentException;
use MongoDB\BSON\Decimal128;

/**
 * Importes del Equipo 5 en centavos enteros (MXN), igual que el ledger del
 * Equipo 2. Nunca se usa float para guardar dinero.
 */
final class Money
{
    /**
     * Máximo aceptado: 10 millones de pesos. Evita desbordar enteros con
     * capturas absurdas.
     */
    private const MAX_PESOS_DIGITS = 8;

    /**
     * Convierte un importe en pesos a centavos sin pasar por float.
     * Acepta "150", "150.5", "150.50", 150.5 o un Decimal128 (incluido el
     * formato "1.5E+2" con que Mongo a veces lo devuelve). Rechaza más de dos
     * decimales, negativos, booleanos y notación científica escrita a mano.
     *
     * @throws InvalidArgumentException
     */
    public static function toCents(mixed $pesos): int
    {
        if (is_bool($pesos) || $pesos === null) {
            throw new InvalidArgumentException('Importe inválido.');
        }

        if ($pesos instanceof Decimal128 || is_float($pesos)) {
            $text = is_float($pesos)
                ? self::floatToText($pesos)
                : self::decimalToText((string) $pesos);
        } elseif (is_int($pesos) || is_string($pesos)) {
            $text = trim((string) $pesos);
        } else {
            throw new InvalidArgumentException('Importe inválido.');
        }

        if (! preg_match('/^(\d{1,'.self::MAX_PESOS_DIGITS.'})(?:\.(\d{1,2}))?$/', $text, $parts)) {
            throw new InvalidArgumentException("Importe inválido: {$text}");
        }

        return ((int) $parts[1]) * 100 + (int) str_pad($parts[2] ?? '0', 2, '0');
    }

    /**
     * Centavos a texto en pesos con dos decimales ("150.00", "-12.50").
     */
    public static function toPesos(?int $cents): ?string
    {
        if ($cents === null) {
            return null;
        }

        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }

    /**
     * Un float de PHP (por ejemplo 220.5 leído de una base sin Decimal128)
     * a texto con a lo más dos decimales; si trae más, no es un importe.
     */
    private static function floatToText(float $value): string
    {
        $rounded = round($value, 2);

        if (abs($rounded - $value) > 1e-9) {
            throw new InvalidArgumentException('El importe tiene más de dos decimales.');
        }

        return rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');
    }

    /**
     * Normaliza la representación de Decimal128 ("150.00", "1.5E+2",
     * "-0.00") a texto plano.
     */
    private static function decimalToText(string $value): string
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?E([+-]\d+)$/i', $value, $parts)) {
            $digits = $parts[2].$parts[3];
            $point = strlen($parts[2]) + (int) $parts[4];
            $digits = $point > strlen($digits) ? str_pad($digits, $point, '0') : $digits;
            $value = $parts[1].($point <= 0 ? '0.'.str_repeat('0', -$point).$digits : substr($digits, 0, $point).($point < strlen($digits) ? '.'.substr($digits, $point) : ''));
        }

        $value = preg_replace('/^-(0+(\.0+)?)$/', '$1', $value) ?? $value;

        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value === '' ? '0' : $value;
    }
}
