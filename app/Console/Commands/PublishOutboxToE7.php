<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class PublishOutboxToE7 extends Command {
    protected $signature = 'team4:publish-outbox';
    protected $description = 'Publica eventos del outbox a Eq.7 (Reservas vencidas)';
    public function handle() {
        $url = env('TEAM7_WEBHOOK_URL');
        if (!$url) { $this->warn('TEAM7_WEBHOOK_URL no configurada.'); return 0; }
        $coll = DB::connection('mongodb')->getCollection('integration_outbox');
        foreach ($coll->find(['status' => 'PENDING', 'topic' => 'inventory.reservation.expired']) as $event) {
            $res = Http::post($url, $event['payload']);
            if ($res->successful()) {
                $coll->updateOne(['_id' => $event['_id']], ['$set' => ['status' => 'SENT', 'sent_at' => now()]]);
                $this->info("Enviado: {$event['_id']}");
            } else {
                $coll->updateOne(['_id' => $event['_id']], ['$set' => ['status' => 'FAILED', 'error' => $res->body()]]);
            }
        }
        return 0;
    }
}