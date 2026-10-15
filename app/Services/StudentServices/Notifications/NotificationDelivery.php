<?php
namespace App\Services\StudentServices\Notifications;

use App\Models\StudentServices\Notifications\NotificationOutbox;
use App\Services\ClienteModuloHttp;
use Illuminate\Support\Facades\Cache;

class NotificationDelivery
{
    public function deliver(NotificationOutbox $entry): void
    {
        Cache::store('file')->lock('aviso-e5:'.$entry->id, 45)->get(function () use ($entry) {
            $entry->refresh();
            if (! in_array($entry->status, ['pending', 'delivered'], true)) { return; }
            $withdraw = $entry->withdraw_pending || ! app(NotificationPlanner::class)->applicable($entry);
            if ($entry->status === 'delivered' && ! $withdraw) { return; }
            try {
                if ($withdraw) {
                    $receipt = $entry->receipt;
                    if (! $receipt) {
                        $r = app(ClienteModuloHttp::class)->para('avisos', 'notifications:read')->get('/api/v1/comunidad/notificaciones', ['clave_idempotencia' => $entry->key]);
                        if ($r->status() === 404) { $entry->update(['status' => 'withdrawn', 'last_error' => null]); return; }
                        $receipt = $r->throw()->json();
                    }
                    app(ClienteModuloHttp::class)->para('avisos', 'notifications:send')
                        ->withHeader('Idempotency-Key', 'retiro:'.$entry->id)
                        ->post('/api/v1/comunidad/notificaciones/'.rawurlencode($receipt['notificacion_id']).'/retiro', ['motivo' => 'El aviso de Servicios ya no aplica.'])->throw();
                    $entry->update(['status' => 'withdrawn', 'last_error' => null]);
                    return;
                }
                $r = app(ClienteModuloHttp::class)->para('avisos', 'notifications:send')->withHeader('Idempotency-Key', $entry->key)
                    ->post('/api/v1/comunidad/notificaciones', $entry->payload)->throw();
                $receipt = $r->json();
                if (! isset($receipt['notificacion_id']) || ! in_array($receipt['estado'] ?? null, ['entregada', 'omitida'], true)) {
                    throw new \RuntimeException('Respuesta inválida de Comunidad.');
                }
                $entry->update(['status' => $receipt['estado'] === 'omitida' ? 'omitted' : 'delivered', 'receipt' => $receipt, 'last_error' => null]);
            } catch (\Throwable $e) {
                $attempt = (int) $entry->attempts + 1;
                $entry->update(['attempts' => $attempt, 'last_error' => 'No se completó la entrega o retiro. Se reintentará con la misma clave.',
                    'next_attempt_at' => now()->addSeconds(min(3600, 30 * (2 ** min($attempt, 7))))]);
                report($e);
            }
        });
    }
}
