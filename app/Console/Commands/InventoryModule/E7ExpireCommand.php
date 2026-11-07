<?php

namespace App\Console\Commands\InventoryModule;

use Illuminate\Console\Command;

class E7ExpireCommand extends Command
{
    protected $signature = 'e7:expire';

    protected $description = 'Libera reservas vencidas de E7 y encola el aviso correspondiente.';

    public function handle(): int
    {
        return $this->call('inventario:expirar-reservas', ['--only-e7' => true]);
    }
}
