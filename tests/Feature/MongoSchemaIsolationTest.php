<?php

use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\BulkWriteException;
use Tests\Support\MongoSchemaCache;

function mongoIsolationMigrationNames(): array
{
    $names = array_map(fn ($path) => pathinfo($path, PATHINFO_FILENAME), glob(database_path('migrations/*.php')));
    sort($names);

    return $names;
}

function mongoIsolationRecordedMigrationNames($database): array
{
    return array_map(fn ($record) => $record['migration'], iterator_to_array($database->selectCollection('migrations')
        ->find([], ['sort' => ['migration' => 1], 'projection' => ['migration' => 1, '_id' => 0]])));
}

test('schema reuse clears documents and test collections while preserving real unique indexes', function () {
    $database = DB::connection('mongodb')->getDatabase();
    MongoSchemaCache::reset($database, 'isolation-fixture');
    $users = $database->selectCollection('users');
    $before = json_encode(iterator_to_array($users->listIndexes()));
    $users->insertOne(['email' => 'ephemeral@example.test']);
    $database->selectCollection('ephemeral_test_collection')->insertOne(['fixture' => true]);

    MongoSchemaCache::reset($database, 'isolation-fixture');

    expect($users->countDocuments([]))->toBe(0)
        ->and(iterator_to_array($database->listCollectionNames(['filter' => ['name' => 'ephemeral_test_collection']])))->toBe([])
        ->and(json_encode(iterator_to_array($users->listIndexes())))->toBe($before);
    $users->insertOne(['email' => 'unique@example.test']);
    expect(fn () => $users->insertOne(['email' => 'unique@example.test']))
        ->toThrow(BulkWriteException::class);
});

test('schema reuse rebuilds altered indexes and migration ledger instead of leaking them', function () {
    $database = DB::connection('mongodb')->getDatabase();
    MongoSchemaCache::reset($database, 'drift-fixture');
    $database->selectCollection('users')->dropIndex('email_1');
    $database->selectCollection('users')->insertOne(['email' => 'drift@example.test']);
    MongoSchemaCache::reset($database, 'drift-fixture');
    $indexes = iterator_to_array($database->selectCollection('users')->listIndexes());
    expect(array_values(array_filter($indexes, fn ($index) => $index->getName() === 'email_1'))[0]->isUnique())->toBeTrue()
        ->and($database->selectCollection('users')->countDocuments([]))->toBe(0);

    $database->selectCollection('migrations')->deleteMany([]);
    MongoSchemaCache::reset($database, 'drift-fixture');
    expect($database->selectCollection('migrations')->countDocuments([]))->toBe(count(mongoIsolationMigrationNames()))
        ->and(mongoIsolationRecordedMigrationNames($database))->toBe(mongoIsolationMigrationNames());
});

test('schema reuse does not carry data across class boundaries', function () {
    $database = DB::connection('mongodb')->getDatabase();
    MongoSchemaCache::reset($database, 'class-a');
    $database->selectCollection('users')->insertOne(['email' => 'class-a@example.test']);
    MongoSchemaCache::reset($database, 'class-b');
    expect($database->selectCollection('users')->countDocuments([]))->toBe(0)
        ->and($database->selectCollection('migrations')->countDocuments([]))->toBe(count(mongoIsolationMigrationNames()))
        ->and(mongoIsolationRecordedMigrationNames($database))->toBe(mongoIsolationMigrationNames());
});
