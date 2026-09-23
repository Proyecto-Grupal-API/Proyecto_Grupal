<?php

namespace App\Jobs;

use App\Services\EntregaCampanas;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class EnviarCampana implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $timeout = 45;

    public $backoff = 10;

    public function __construct(public string $campanaId, public string $ejecucion)
    {
        $this->onConnection('comunidad');
        $this->onQueue('comunidad');
    }

    public function handle(EntregaCampanas $entrega): void
    {
        if ($entrega->lote($this->campanaId, $this->ejecucion)) {
            self::dispatch($this->campanaId, $this->ejecucion);
        }
    }

    public function failed(?Throwable $e): void
    {
        app(EntregaCampanas::class)->fallo($this->campanaId, $this->ejecucion);
    }
}
