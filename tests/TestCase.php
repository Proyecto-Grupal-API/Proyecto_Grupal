<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Evitar que distintas pruebas compartan el limite OAuth.
        static $requestIpSequence = 0;
        ++$requestIpSequence;
        $this->withServerVariables([
            'REMOTE_ADDR' => '127.1.' . intdiv($requestIpSequence, 254) . '.' . (($requestIpSequence % 254) + 1),
        ]);

        if (app()->environment('testing') && config('database.default') === 'mongodb') {
            DB::connection('mongodb')
                ->getMongoClient()
                ->selectDatabase(config('database.connections.mongodb.database'))
                ->drop();
        }
    }
}
