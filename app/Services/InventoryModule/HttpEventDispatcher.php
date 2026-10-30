<?php

namespace App\Services\InventoryModule;

use Illuminate\Support\Facades\Http;
use MongoDB\BSON\UTCDateTime;

/** Adaptación del dispatcher E4 a IDs persistentes y recibos verificables. */
class HttpEventDispatcher
{
    public const EVENTS = ['inventory.movement_created', 'inventory.stock_updated', 'inventory.adjustment_made',
        'inventory.cost_updated', 'inventory.return_processed', 'inventory.reservation.expired'];

    public function dispatch(array $event): void
    {
        $url = (string) config('team4.outbox.url');
        $secret = (string) config('team4.outbox.secret');
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null
            || parse_url($url, PHP_URL_USER) !== null || strlen($secret) < 20) {
            throw new \DomainException('inventory_receiver_not_configured');
        }
        if (empty($event['business_id']) || ! ($event['created_at'] ?? null) instanceof UTCDateTime
            || ! in_array($event['topic'] ?? '', self::EVENTS, true)) {
            throw new \DomainException('inventory_envelope_incomplete');
        }
        $eventId = 'evt_inv_'.(string) $event['_id'];
        $payload = (array) $event['payload'];
        $payload['business_id'] = (string) $event['business_id'];
        $body = json_encode(['event_id' => $eventId, 'event_type' => $event['topic'], 'source_domain' => 'inventory',
            'occurred_at' => $event['created_at']->toDateTime()->format(DATE_ATOM), 'payload' => $payload],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $time = (string) time();
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $signature = hash_hmac('sha256', $time.'|POST|'.$path.'|'.hash('sha256', $body), $secret);
        $response = Http::acceptJson()->connectTimeout(3)->timeout(10)->withOptions(['allow_redirects' => false])
            ->withHeaders(['X-Team-Timestamp' => $time, 'X-Team-Signature' => $signature])
            ->withBody($body, 'application/json')->post($url);
        if (! in_array($response->status(), [200, 201, 202], true) || $response->json('event_id') !== $eventId
            || ! in_array($response->json('status'), ['accepted', 'duplicate'], true)) {
            throw new \DomainException('inventory_receipt_not_acknowledged');
        }
    }
}
