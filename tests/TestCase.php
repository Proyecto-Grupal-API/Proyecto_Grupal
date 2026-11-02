<?php

namespace Tests;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use RuntimeException;
use Tests\Support\E1TestTarget;
use Tests\Support\MongoSchemaCache;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        if (E1TestTarget::profile() === 'isolated') { return parent::createApplication(); }
        E1TestTarget::assertBeforeBootstrap(dirname(__DIR__));
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        foreach ([\Illuminate\Foundation\Testing\WithCachedConfig::class,
            \Illuminate\Foundation\Testing\WithCachedRoutes::class] as $trait) {
            \Tests\Support\E1IntegratedMongoTarget::require(! isset($this->traitsUsedByTest[$trait]));
        }
        E1TestTarget::install($app);
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        return $app;
    }

    public function artisan($command, $parameters = [])
    {
        if (E1TestTarget::profile() === 'integrated') { E1TestTarget::assertApplication(); }
        return parent::artisan($command, $parameters);
    }

    public function seed($class = 'Database\\Seeders\\DatabaseSeeder')
    {
        if (E1TestTarget::profile() === 'integrated') { E1TestTarget::assertApplication(); }
        return parent::seed($class);
    }

    protected function setUp(): void
    {
        // Bootstrap configuration before Laravel initializes testing traits such as RefreshDatabase.
        E1TestTarget::assertBeforeBootstrap(dirname(__DIR__));
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
            E1TestTarget::assertApplication($database);
            $database->drop();
        }

        parent::setUp();
        $this->withoutVite();

        // Detect any connection drift after Laravel's testing traits have initialized.
        $this->assertSafeMongoTestTarget();

        if (in_array('assignment-authority-fixtures', $this->groups(), true)) {
            (require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php'))->up();
            $this->seed(RoleSeeder::class);
        }
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

        E1TestTarget::assertApplication();
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
