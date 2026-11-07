<?php

namespace App\Console\Commands;

use App\Services\InventoryModule\HttpEventDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Operation\FindOneAndUpdate;

class PublishInventoryOutbox extends Command
{
    protected $signature = 'team4:publish-outbox';

    protected $description = 'Entrega eventos de reservas vencidas de E4 al receptor E7 configurado.';

    public function handle(HttpEventDispatcher $dispatcher): int
    {
        if (! config('team4.outbox.enabled')) {
            $this->info('Transporte E7 deshabilitado.');

            return self::SUCCESS;
        }
        $coll = DB::connection('inventory')->getCollection('integration_outbox');
        $now = new UTCDateTime(now());
        $filter = ['topic' => ['$in' => HttpEventDispatcher::EVENTS], 'status' => ['$in' => ['PENDING', 'FAILED']],
            '$and' => [
                ['$or' => [['next_attempt_at' => null], ['next_attempt_at' => ['$lte' => $now]]]],
                ['$or' => [['claim_expires_at' => null], ['claim_expires_at' => ['$lte' => $now]]]],
            ]];
        $failed = 0;
        foreach ($coll->find($filter, ['limit' => 100, 'sort' => ['_id' => 1]]) as $candidate) {
            $claim = (string) Str::uuid();
            $event = $coll->findOneAndUpdate($filter + ['_id' => $candidate['_id']], ['$set' => [
                'claim_token' => $claim, 'claim_expires_at' => new UTCDateTime(now()->addSeconds(30)),
            ]], ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]);
            if (! $event) {
                continue;
            }
            $error = null;
            try {
                $dispatcher->dispatch((array) $event);
            } catch (\Throwable $e) {
                $error = $e instanceof \DomainException ? $e->getMessage() : class_basename($e);
            }
            $updates = ['status' => $error === null ? 'SENT' : 'FAILED', 'last_error' => $error, 'updated_at' => new UTCDateTime(now())];
            if ($error === null) {
                $updates['delivered_at'] = new UTCDateTime(now());
            } else {
                $failed++;
                $delay = min(60, 2 ** min(6, max(0, (int) ($event['attempts'] ?? 0))));
                $updates['next_attempt_at'] = new UTCDateTime(now()->addMinutes($delay));
            }
            $coll->updateOne(['_id' => $event['_id'], 'claim_token' => $claim], [
                '$set' => $updates, '$inc' => ['attempts' => 1], '$unset' => ['claim_token' => '', 'claim_expires_at' => ''],
            ]);
        }
        $this->info('Entrega E7 revisada; fallos: '.$failed.'.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
