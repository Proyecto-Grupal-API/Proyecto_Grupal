<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Bandeja de salida de Tienda hacia Recompensas: un aviso por pedido y tipo. Sólo crea índices; no toca datos. */
return new class extends Migration
{
    public function up(): void
    {
        $collection = DB::connection('marketplace')->getCollection('marketplace_rewards_outbox');
        $collection->createIndex(['event_id' => 1], ['unique' => true, 'name' => 'marketplace_rewards_outbox_event_unique']);
        $collection->createIndex(['status' => 1, 'created_at' => 1], ['name' => 'marketplace_rewards_outbox_pending']);
    }

    public function down(): void
    {
        $collection = DB::connection('marketplace')->getCollection('marketplace_rewards_outbox');
        foreach (['marketplace_rewards_outbox_event_unique', 'marketplace_rewards_outbox_pending'] as $index) {
            try {
                $collection->dropIndex($index);
            } catch (Throwable) {
                // el índice no existía
            }
        }
    }
};
