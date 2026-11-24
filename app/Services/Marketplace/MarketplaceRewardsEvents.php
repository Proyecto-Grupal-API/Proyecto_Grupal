<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use Ramsey\Uuid\Uuid;

/**
 * Contratos del diseño (§15) «Acumular puntos por venta» y «Revertir puntos por devolución»: Tienda avisa a
 * Recompensas cuando una venta queda confirmada y pagada, y cuando se aprueba su devolución.
 *
 * Tienda no calcula puntos ni toca el ledger: deja el aviso en su propia bandeja de salida y un proceso
 * planificado lo entrega firmado a la recepción de Recompensas, que decide qué movimiento corresponde.
 * Sin la bandera `MARKETPLACE_REWARDS_ENABLED` no se encola ni se envía nada.
 */
final class MarketplaceRewardsEvents
{
    public const COLLECTION = 'marketplace_rewards_outbox';

    public const CONFIRMED = 'marketplace.order.confirmed.v1';

    public const REFUNDED = 'marketplace.order.refunded.v1';

    private const PATH = '/api/v1/plataforma/eventos';

    public function enabled(): bool
    {
        return config('marketplace.rewards.enabled') === true;
    }

    /** Encola el aviso de un pedido. Un pedido produce como mucho un aviso de cada tipo. */
    public function queue(Order $order, string $eventName): void
    {
        if (! $this->enabled() || ! in_array($eventName, [self::CONFIRMED, self::REFUNDED], true)) {
            return;
        }
        $folio = (string) $order->folio;
        $now = new UTCDateTime(now());
        $this->collection()->updateOne(
            ['event_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'campus:marketplace:'.$eventName.':'.$folio)->toString()],
            ['$setOnInsert' => [
                'event_name' => $eventName, 'order_folio' => $folio, 'business_id' => (string) $order->business_id,
                'buyer_id' => (string) $order->buyer_id, 'total_cents' => (int) ($order->total_cents ?? round(((float) $order->total) * 100)),
                'currency' => (string) ($order->currency ?: 'MXN'), 'occurred_at' => now()->toIso8601String(),
                'status' => 'pending', 'attempts' => 0, 'last_error' => null, 'sent_at' => null, 'created_at' => $now, 'updated_at' => $now,
            ]],
            ['upsert' => true]
        );
    }

    /** @return array{enabled: bool, sent: int, failed: int} */
    public function deliver(int $limit = 50): array
    {
        if (! $this->enabled()) {
            return ['enabled' => false, 'sent' => 0, 'failed' => 0];
        }
        [$url, $secret] = $this->target();
        $sent = $failed = 0;
        $rows = $this->collection()->find(['status' => ['$in' => ['pending', 'failed']]], ['sort' => ['created_at' => 1], 'limit' => max(1, min($limit, 200))]);
        foreach ($rows as $row) {
            $error = $this->send($url, $secret, [
                'event_id' => (string) $row['event_id'], 'event_name' => (string) $row['event_name'], 'source_domain' => 'marketplace',
                'aggregate_id' => (string) $row['order_folio'], 'occurred_at' => (string) $row['occurred_at'],
                // Sólo identificadores e importes: nada de nombres ni correos.
                'payload' => ['order_folio' => (string) $row['order_folio'], 'business_id' => (string) $row['business_id'],
                    'buyer_id' => (string) $row['buyer_id'], 'total_cents' => (int) $row['total_cents'], 'currency' => (string) $row['currency']],
            ]);
            $this->collection()->updateOne(['_id' => $row['_id']], ['$set' => [
                'status' => $error === null ? 'sent' : 'failed', 'last_error' => $error, 'updated_at' => new UTCDateTime(now()),
                'sent_at' => $error === null ? new UTCDateTime(now()) : null,
            ], '$inc' => ['attempts' => 1]]);
            $error === null ? $sent++ : $failed++;
        }

        return ['enabled' => true, 'sent' => $sent, 'failed' => $failed];
    }

    private function send(string $url, string $secret, array $body): ?string
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $time = (string) time();
        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(10)->withoutRedirecting()->withHeaders([
                'X-Platform-Domain' => 'marketplace', 'X-Platform-Timestamp' => $time,
                'X-Platform-Signature' => hash_hmac('sha256', $time.'|POST|'.self::PATH.'|'.hash('sha256', $json), $secret),
            ])->withBody($json, 'application/json')->post($url.self::PATH);
            if (! in_array($response->status(), [200, 202], true) || $response->json('event_id') !== $body['event_id']
                || ! in_array($response->json('estado'), ['aceptado', 'duplicado'], true)) {
                return 'invalid_receipt_'.$response->status();
            }
        } catch (\Throwable) {
            return 'transport_unavailable';
        }

        return null;
    }

    /** Misma regla de destino que el resto de emisores hacia Recompensas: https, o http sólo hacia el propio laboratorio. */
    private function target(): array
    {
        $url = rtrim((string) config('marketplace.rewards.url'), '/');
        $parts = parse_url($url);
        abort_unless(is_array($parts) && isset($parts['host'], $parts['scheme']) && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment']) && empty($parts['path'])
            && ($parts['scheme'] === 'https' || (app()->environment(['local', 'testing']) && $parts['scheme'] === 'http'
                && in_array($parts['host'], ['app', 'localhost', '127.0.0.1'], true))), 503, 'Destino de Recompensas no permitido.');
        $secret = config('platform.ingest.secrets.marketplace');
        abort_unless(is_string($secret) && strlen($secret) >= 20, 503, 'Falta la credencial de transporte hacia Recompensas.');

        return [$url, $secret];
    }

    private function collection(): Collection
    {
        return DB::connection('marketplace')->getCollection(self::COLLECTION);
    }
}
