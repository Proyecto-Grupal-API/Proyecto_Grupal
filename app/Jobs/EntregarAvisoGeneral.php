<?php

namespace App\Jobs;

use App\Models\AvisoGeneral;
use App\Services\EntregaAvisoGeneral;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Misma cola que las campañas de E6 (conexión y cola «comunidad»); un lote por ejecución. */
class EntregarAvisoGeneral implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $timeout = 45;

    public $backoff = 10;

    public function __construct(public string $avisoId)
    {
        $this->onConnection('comunidad');
        $this->onQueue('comunidad');
    }

    public function handle(EntregaAvisoGeneral $entrega): void
    {
        if ($entrega->lote($this->avisoId)) {
            self::dispatch($this->avisoId);
        }
    }

    public function failed(?Throwable $e): void
    {
        AvisoGeneral::where('_id', $this->avisoId)->update(['estado' => 'fallido', 'error' => 'La entrega se interrumpió; se puede reanudar.']);
    }
}
