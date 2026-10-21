<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Domains\Financial\Integrations\Team4\Team4WebhookDelivery;
final class DeliverTeam4PaymentEvents extends Command {
    protected $signature='financial:team4-deliver {--limit=50}';
    protected $description='Deliver a bounded batch of persisted Team4 payment events. Never creates payments.';
    public function handle(Team4WebhookDelivery $delivery): int {
        if(!config('financial_team4.enabled')){$this->info('Emisor desactivado. No se enviaron eventos.');return self::SUCCESS;}
        try{$n=$delivery->run((int)$this->option('limit'));$this->info('Intentos procesados: '.$n);return self::SUCCESS;}
        catch(\Throwable $e){$this->error('No se pudo procesar la outbox. Revisa configuración, tablas y conectividad.');logger()->error('Team4 outbox command failed.',['exception_type'=>$e::class]);return self::FAILURE;}
    }
}
