<?php
namespace App\Console\Commands;
use App\Api\InventoryModule\Team3ApiService;
use App\Models\InventoryModule\InventoryBusiness;
use App\Services\InventoryModule\BusinessContext;
use App\Services\InventoryModule\ReservationService;
use App\Services\InventoryModule\OutboxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class ExpireInventoryReservations extends Command
{
    protected $signature='inventario:expirar-reservas';
    protected $description='Libera reservas vencidas de los negocios de Inventarios.';
    public function handle(): int
    {
        $count=0;$failed=0;
        foreach (InventoryBusiness::where('active',true)->get() as $business) {
            app(BusinessContext::class)->select($business->business_id);
            $count+=app(ReservationService::class)->expireOverdue();
            $expired=DB::connection('inventory')->getCollection('team3_api_reservations')->find([
                'business_id'=>$business->business_id,'status'=>'RESERVED', 'expires_at'=>['$lt'=>new \MongoDB\BSON\UTCDateTime()]]);
            foreach ($expired as $row) {
                $id=(string) $row['reservation_id'];
                try {
                    app(Team3ApiService::class)->release($id,['idempotency_key'=>'expiry-'.$id,'reason'=>'EXPIRED']);
                    if (($row['origin_system'] ?? '') === 'E7') {
                        app(OutboxService::class)->publish('inventory.reservation.expired',['reservation_id'=>$id,
                            'origin_reference'=>$row['origin_reference'] ?? '', 'expired_at'=>now()->toIso8601String()]);
                    }
                    $count++;
                } catch (\Throwable $e) { report($e);$failed++; }
            }
        }
        request()->attributes->remove('inventory_business_id');
        $this->info('Reservas liberadas: '.$count.'. Fallos: '.$failed.'.');
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
