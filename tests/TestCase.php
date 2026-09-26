<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        if (config('database.connections.mongodb.database') !== 'campus_digital_testing') {
            throw new \RuntimeException('Tests require the isolated campus_digital_testing database.');
        }

        if (! in_array(\Tests\Concerns\RefreshMongoDatabase::class, class_uses_recursive(static::class), true) && app()->environment('testing') && config('database.default') === 'mongodb') {
            DB::connection('mongodb')
                ->getMongoClient()
                ->selectDatabase(config('database.connections.mongodb.database'))
                ->drop();
        }
    }
}
