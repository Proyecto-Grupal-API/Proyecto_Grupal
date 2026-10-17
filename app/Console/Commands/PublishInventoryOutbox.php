<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Operation\FindOneAndUpdate;

class PublishInventoryOutbox extends Command
{
    protected $signature = 'team4:publish-outbox';

    protected $description = 'Entrega eventos de reservas vencidas de E4 al receptor E7 configurado.';

    public function handle(): int
    {
        if (! config('team4.outbox.enabled')) {
            $this->info('Transporte E7 deshabilitado.');

            return self::SUCCESS;
        }
        $url = config('team4.outbox.url');
        $token = config('team4.outbox.token');
        if (! $url || ! $token || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $this->error('Faltan el receptor HTTP y su credencial de servicio.');

            return self::FAILURE;
        }
        $coll = DB::connection('inventory')->getCollection('integration_outbox');
        $now = new UTCDateTime(now());
        $filter = ['topic' => 'inventory.reservation.expired', 'status' => ['$in' => ['PENDING', 'FAILED']],
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
                if (empty($event['business_id'])) {
                    throw new \DomainException('legacy_business_missing');
                }
                $payload = (array) $event['payload'];
                $payload['business_id'] = $event['business_id'];
                $response = Http::acceptJson()->withToken($token)->connectTimeout(5)->timeout(10)
                    ->withOptions(['allow_redirects' => false])->withHeaders([
                        'X-Event-Id' => (string) $event['_id'], 'X-Event-Type' => $event['topic'], 'X-Event-Version' => '1',
                    ])->post($url, $payload);
                if (! $response->successful()) {
                    $error = 'HTTP '.$response->status();
                }
            } catch (\Throwable $e) {
                $error = class_basename($e);
            }
            $updates = ['status' => $error === null ? 'SENT' : 'FAILED', 'last_error' => $error, 'updated_at' => new UTCDateTime(now())];
            if ($error === null) {
                $updates['delivered_at'] = new UTCDateTime(now());
            } else {
                $failed++;
                $updates['next_attempt_at'] = new UTCDateTime(now()->addMinutes(5));
            }
            $coll->updateOne(['_id' => $event['_id'], 'claim_token' => $claim], [
                '$set' => $updates, '$inc' => ['attempts' => 1], '$unset' => ['claim_token' => '', 'claim_expires_at' => ''],
            ]);
        }
        $this->info('Entrega E7 revisada; fallos: '.$failed.'.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
