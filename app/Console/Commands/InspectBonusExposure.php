<?php

namespace App\Console\Commands;

use App\Domains\Financial\Services\BonusExposureQueryService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/** Operator CLI only; no public route, grants, repairs or disclosure of identities. */
final class InspectBonusExposure extends Command
{
    protected $signature = 'financial:bonus-exposure {--type= : Tipo literal, por ejemplo BECA} {--currency= : Moneda, por ejemplo MXN}';
    protected $description = 'Consulta exposicion actual de bonos sin modificar saldos ni certificar financiamiento';

    public function handle(BonusExposureQueryService $service): int
    {
        try {
            $result = $service->report($this->option('type'), $this->option('currency'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
