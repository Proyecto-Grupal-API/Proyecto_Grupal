<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (app()->environment('testing') && config('database.default') === 'mongodb') {
            DB::connection('mongodb')
                ->getMongoClient()
                ->selectDatabase(config('database.connections.mongodb.database'))
                ->drop();
        }
    }
}
