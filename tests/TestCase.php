<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use RuntimeException;
use Tests\Support\MongoSchemaCache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Bootstrap configuration before Laravel initializes testing traits such as RefreshDatabase.
        $this->refreshApplication();
        $this->assertSafeMongoTestTarget();
        ParallelTesting::callSetUpTestCaseCallbacks($this);
        $this->assertSafeMongoTestTarget();
        $database = DB::connection('mongodb')->getDatabase();
        if (in_array('reusable-mongo-schema', $this->groups(), true)) {
            MongoSchemaCache::reset($database, static::class);
        } else {
            // Migration/index tests and all non-opted-in classes retain physical isolation.
            MongoSchemaCache::forget();
            $database->drop();
        }

        parent::setUp();

        // Detect any connection drift after Laravel's testing traits have initialized.
        $this->assertSafeMongoTestTarget();
    }

    protected function mongoTestDatabaseName(): string
    {
        return DB::connection('mongodb')->getDatabaseName();
    }

    private function assertSafeMongoTestTarget(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'mongodb') {
            throw new RuntimeException('Blocked destructive test database operation: expected the testing environment and MongoDB connection.');
        }

        self::assertSafeMongoDatabaseName($this->mongoTestDatabaseName());
    }

    public static function assertSafeMongoDatabaseName(string $databaseName): void
    {
        if ($databaseName !== 'campus_virtual_testing') {
            throw new RuntimeException(
                "Blocked destructive test database operation: resolved MongoDB database [{$databaseName}] is not allowed."
            );
        }
    }
}
