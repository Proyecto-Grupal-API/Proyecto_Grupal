<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use MongoDB\Database;
use RuntimeException;
use Tests\TestCase;

/** Reuse only real migrated schema; never cache mutable test documents. */
final class MongoSchemaCache
{
    private static ?string $owner = null;

    private static array $schema = [];

    private static string $migrationLedger = '';

    public static function forget(): void
    {
        self::$owner = null;
        self::$schema = [];
        self::$migrationLedger = '';
    }

    public static function reset(Database $database, string $owner): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'mongodb') {
            throw new RuntimeException('Schema reuse is restricted to MongoDB testing.');
        }
        TestCase::assertSafeMongoDatabaseName($database->getDatabaseName());

        if (self::$owner === $owner) {
            $actual = self::schema($database);
            $expectedCollections = array_intersect_key($actual, self::$schema);
            if ($expectedCollections === self::$schema && self::ledger($database) === self::$migrationLedger) {
                foreach ($actual as $name => $_) {
                    if (! array_key_exists($name, self::$schema)) {
                        // Collections created by a test must not leak their data or indexes.
                        $database->selectCollection($name)->drop();
                    } elseif ($name !== 'migrations') {
                        $database->selectCollection($name)->deleteMany([]);
                    }
                }

                return;
            }
        }

        // Class boundary or schema/ledger drift: rebuild using the real migrations.
        self::forget();
        $database->drop();
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException('Failed to prepare the real MongoDB testing schema.');
        }
        self::$schema = self::schema($database);
        self::$migrationLedger = self::ledger($database);
        self::$owner = $owner;
    }

    private static function schema(Database $database): array
    {
        $schema = [];
        foreach ($database->listCollections() as $collection) {
            $name = $collection->getName();
            $indexes = [];
            foreach ($database->selectCollection($name)->listIndexes() as $index) {
                $indexes[$index->getName()] = json_encode($index, JSON_THROW_ON_ERROR);
            }
            ksort($indexes);
            $schema[$name] = [json_encode($collection->getOptions(), JSON_THROW_ON_ERROR), $indexes];
        }
        ksort($schema);

        return $schema;
    }

    private static function ledger(Database $database): string
    {
        return json_encode(iterator_to_array($database->selectCollection('migrations')->find([], [
            'sort' => ['migration' => 1],
        ])), JSON_THROW_ON_ERROR);
    }
}
