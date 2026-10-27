<?php

namespace App\Domains\Financial\Integrations\Team4;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class PaymentEventPayload
{
    public static function canonical(array $input): array
    {
        $required = ['payment_intent_id', 'order_id', 'business_id', 'status', 'amount_cents', 'occurred_at'];
        foreach ($required as $key) {
            if (! array_key_exists($key, $input)) {
                throw new InvalidArgumentException('Campo requerido: '.$key);
            }
        }
        if (array_diff(array_keys($input), [...$required, 'refund_id', 'items', 'currency'])) {
            throw new InvalidArgumentException('Campos no admitidos.');
        }
        foreach (['payment_intent_id', 'order_id', 'business_id'] as $key) {
            self::identifier($input[$key]);
        }
        if (! in_array($input['status'], ['PENDING', 'PAID', 'FAILED', 'REFUNDED', 'PARTIALLY_REFUNDED'], true)) {
            throw new InvalidArgumentException('Estado inválido.');
        }
        if (! is_int($input['amount_cents']) || $input['amount_cents'] < 0 || $input['amount_cents'] > 9007199254740991) {
            throw new InvalidArgumentException('Importe entero fuera de rango.');
        }
        if (($input['currency'] ?? 'MXN') !== 'MXN') {
            throw new InvalidArgumentException('Solo MXN en esta integración.');
        }
        $refund = in_array($input['status'], ['REFUNDED', 'PARTIALLY_REFUNDED'], true);
        if ($refund) {
            self::identifier($input['refund_id'] ?? null);
            if ($input['amount_cents'] === 0) {
                throw new InvalidArgumentException('Devolución sin importe.');
            }
        } elseif (! empty($input['refund_id'])) {
            throw new InvalidArgumentException('refund_id solo corresponde a devolución.');
        }
        if (! is_string($input['occurred_at']) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $input['occurred_at'])) {
            throw new InvalidArgumentException('Fecha ISO 8601 con zona requerida.');
        }
        $date = CarbonImmutable::parse($input['occurred_at'])->utc()->format('Y-m-d\TH:i:s.u\Z');
        $items = $input['items'] ?? [];
        if (! is_array($items) || ! array_is_list($items) || count($items) > 100) {
            throw new InvalidArgumentException('items inválido.');
        }
        $clean = [];
        $seen = [];
        foreach ($items as $item) {
            if (! is_array($item) || array_diff(array_keys($item), ['product_id', 'variant_id', 'location_id', 'quantity'])) {
                throw new InvalidArgumentException('Artículo inválido.');
            }
            self::identifier($item['product_id'] ?? null);
            self::identifier($item['location_id'] ?? null);
            if (($item['variant_id'] ?? null) !== null) {
                self::identifier($item['variant_id']);
            }
            if (! is_int($item['quantity'] ?? null) || $item['quantity'] < 1 || $item['quantity'] > 2147483647) {
                throw new InvalidArgumentException('Cantidad inválida.');
            }
            $row = ['product_id' => $item['product_id'], 'variant_id' => $item['variant_id'] ?? null, 'location_id' => $item['location_id'], 'quantity' => $item['quantity']];
            $key = json_encode([$row['product_id'], $row['variant_id'], $row['location_id']], JSON_THROW_ON_ERROR);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Artículo duplicado.');
            }$seen[$key] = true;
            $clean[] = $row;
        }
        usort($clean, fn ($a, $b) => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));

        return ['payment_intent_id' => $input['payment_intent_id'], 'order_id' => $input['order_id'], 'business_id' => $input['business_id'],
            'status' => $input['status'], 'refund_id' => $input['refund_id'] ?? null, 'amount_cents' => $input['amount_cents'], 'currency' => 'MXN', 'occurred_at' => $date, 'items' => $clean];
    }

    public static function identifier(mixed $value): void
    {
        if (! is_string($value) || $value === '' || strlen($value) > 100 || preg_match('/[\x00-\x20\x7f]/', $value)) {
            throw new InvalidArgumentException('Identificador inválido.');
        }
    }

    public static function body(array $canonical, string $eventId, int $version): string
    {
        $cents = $canonical['amount_cents'];
        unset($canonical['amount_cents']);
        $base = json_encode(['event_id' => $eventId, 'event_version' => $version, ...$canonical], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // Emit a numeric JSON decimal directly; never convert money to binary float.
        $amount = intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);

        return substr($base,0,-1).',"amount":'.$amount.'}';
    }
}
