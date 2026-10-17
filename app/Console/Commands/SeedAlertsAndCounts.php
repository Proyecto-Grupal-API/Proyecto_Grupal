<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedAlertsAndCounts extends Command {
    protected $signature = 'team4:seed-alerts';
    protected $description = 'Genera datos de prueba para alertas y conteos';
    public function handle() {
        $db = DB::connection('mongodb');
        $bid = config('team4.business_id', 'BUS-CD-SOUV-001');
        $db->getCollection('stock_alerts')->insertOne(['business_id' => $bid, 'product_id' => 'PRD-001', 'status' => 'ACTIVE', 'priority' => 'HIGH', 'message' => 'Stock bajo minimo', 'created_at' => now()]);
        $db->getCollection('stock_counts')->insertOne(['business_id' => $bid, 'status' => 'PENDING', 'type' => 'CICLICO', 'created_at' => now()]);
        $this->info('Datos de prueba inyectados.');
    }
}