<?php

namespace App\Console\Commands;

use App\Services\CommunityAnalyticsDelivery;
use Illuminate\Console\Command;

class PublishCommunityAnalytics extends Command
{
    protected $signature='comunidad:publicar-analitica {--limit=30}';
    protected $description='Entrega agregados publicados y participación de procesos cerrados a E7 sin datos nominales.';
    public function handle(CommunityAnalyticsDelivery $delivery): int
    {
        $limit=filter_var($this->option('limit'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);
        if($limit===false){$this->error('El límite debe estar entre 1 y 100.');return self::INVALID;}
        try{$r=$delivery->deliver($limit);}catch(\InvalidArgumentException $e){$this->error($e->getMessage());return self::INVALID;}
        $this->line(json_encode($r,JSON_THROW_ON_ERROR));
        return $r['failed'] || $r['invalid'] ? self::FAILURE : self::SUCCESS;
    }
}
