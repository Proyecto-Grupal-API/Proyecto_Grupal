<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use RuntimeException;

trait RefreshMongoDatabase
{
    protected function setUpRefreshMongoDatabase(): void
    {
        $connection = DB::connection('mongodb');
        if (! app()->environment('testing') || $connection->getDatabaseName() !== 'campus_digital_testing') {
            throw new RuntimeException('Las pruebas solo pueden limpiar campus_digital_testing.');
        }
        $connection->getDatabase()->drop();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }
}
