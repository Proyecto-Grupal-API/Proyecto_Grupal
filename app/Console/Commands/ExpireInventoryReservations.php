<?php
namespace App\Console\Commands;
use App\Api\InventoryModule\Team3ApiService;
use App\Models\InventoryModule\InventoryBusiness;
use App\Services\InventoryModule\BusinessContext;
use App\Services\InventoryModule\ReservationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class ExpireInventoryReservations extends Command
{
    protected $signature='inventario:expirar-reservas {--only-e7 : Limita el proceso a reservas de E7}';
    protected $description='Libera reservas vencidas de los negocios de Inventarios.';
    public function handle(): int
    {
        $count=0;$failed=0;
        foreach (InventoryBusiness::where('active',true)->get() as $business) {
            app(BusinessContext::class)->select($business->business_id);
            $filter = ['business_id' => $business->business_id, 'status' => 'RESERVED',
                'expires_at' => ['$lt' => new \MongoDB\BSON\UTCDateTime(now())]];
            if ($this->option('only-e7')) {
                $filter['origin_system'] = 'E7';
            } else {
                $count += app(ReservationService::class)->expireOverdue();
            }
            $expired = DB::connection('inventory')->getCollection('team3_api_reservations')->find($filter);
            foreach ($expired as $row) {
                $id=(string) $row['reservation_id'];
                try {
                    $count += (int) app(Team3ApiService::class)->expire($id);
                } catch (\Throwable $e) { report($e);$failed++; }
            }
        }
        request()->attributes->remove('inventory_business_id');
        $this->info('Reservas liberadas: '.$count.'. Fallos: '.$failed.'.');
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
