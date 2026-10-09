<?php
namespace App\Console\Commands;

use App\Domains\Financial\Services\WalletHoldService;
use Illuminate\Console\Command;

class ExpireWalletHolds extends Command
{
    protected $signature = 'financial:expire-holds';
    protected $description = 'Libera las retenciones vencidas sin crear ni duplicar dinero.';
    public function handle(WalletHoldService $service): int
    {
        $this->info('Retenciones vencidas liberadas: ' . $service->expireDue());
        return self::SUCCESS;
    }
}
