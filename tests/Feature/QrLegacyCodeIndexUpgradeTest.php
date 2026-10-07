<?php

use Illuminate\Support\Facades\DB;
use MongoDB\Collection;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Model\IndexInfo;

beforeEach(function () {
    expect(app()->environment('testing'))->toBeTrue()
        ->and(config('database.default'))->toBe('mongodb')
        ->and(config('database.connections.mongodb.database'))->toBe('campus_virtual_testing');
});

function qrCodeIndexMigration(): object
{
    return require database_path('migrations/2026_09_23_000200_make_qr_code_index_partial.php');
}

function qrCodeIndexCollection(): Collection
{
    $connection = DB::connection('mongodb');
    $connection->getMongoClient()
        ->selectDatabase($connection->getDatabaseName())
        ->createCollection('qr_tokens');

    return $connection->getCollection('qr_tokens');
}

function qrCodeIndex(Collection $collection): ?IndexInfo
{
    foreach ($collection->listIndexes() as $index) {
        if ($index->getName() === 'code_1') {
            return $index;
        }
    }

    return null;
}

function expectPartialQrCodeIndex(Collection $collection): void
{
    $index = qrCodeIndex($collection);
    expect($index)->not->toBeNull()
        ->and($index->getName())->toBe('code_1')
        ->and($index->getKey())->toBe(['code' => 1])
        ->and($index->isUnique())->toBeTrue()
        ->and($index->isSparse())->toBeFalse()
        ->and((array) $index['partialFilterExpression'])->toEqual(['code' => ['$type' => 'string']]);
}

function expectQrCodeIndexBehavior(Collection $collection): void
{
    $collection->insertOne(['type' => 'dynamic', 'code_hash' => 'hash-one']);
    $collection->insertOne(['type' => 'identification', 'code_hash' => 'hash-two']);
    $collection->insertOne(['type' => 'dynamic', 'code' => 'LEGACY-ONE']);

    expect($collection->countDocuments(['code' => ['$exists' => false]]))->toBe(2)
        ->and(fn () => $collection->insertOne(['type' => 'dynamic', 'code' => 'LEGACY-ONE']))
        ->toThrow(BulkWriteException::class);
}

test('up upgrades the actual legacy unique index without losing legacy uniqueness', function () {
    $collection = qrCodeIndexCollection();
    $collection->createIndex(['code' => 1], ['name' => 'code_1', 'unique' => true]);
    $collection->insertOne(['type' => 'dynamic', 'code' => 'LEGACY-ONE']);
    $collection->insertOne(['type' => 'identification', 'code' => 'LEGACY-TWO']);

    qrCodeIndexMigration()->up();

    expectPartialQrCodeIndex($collection);
    $collection->insertOne(['type' => 'dynamic', 'code_hash' => 'hash-one']);
    $collection->insertOne(['type' => 'identification', 'code_hash' => 'hash-two']);
    expect($collection->countDocuments(['code' => ['$exists' => false]]))->toBe(2)
        ->and($collection->countDocuments(['code' => ['$type' => 'string']]))->toBe(2)
        ->and(fn () => $collection->insertOne(['code' => 'LEGACY-ONE']))
        ->toThrow(BulkWriteException::class);
});

test('up accepts an already correct partial index without changing documents', function () {
    $collection = qrCodeIndexCollection();
    $collection->createIndex(['code' => 1], [
        'name' => 'code_1', 'unique' => true,
        'partialFilterExpression' => ['code' => ['$type' => 'string']],
    ]);
    $collection->insertOne(['type' => 'dynamic', 'code_hash' => 'existing-hash']);

    qrCodeIndexMigration()->up();

    expectPartialQrCodeIndex($collection);
    expect($collection->countDocuments([]))->toBe(1);
});

test('up recovers when code_1 is absent after an interrupted upgrade', function () {
    $collection = qrCodeIndexCollection();

    qrCodeIndexMigration()->up();

    expectPartialQrCodeIndex($collection);
    expectQrCodeIndexBehavior($collection);
});

test('up rejects an unexpected code_1 definition without dropping it', function (array $options, array $key) {
    $collection = qrCodeIndexCollection();
    $collection->createIndex($key, ['name' => 'code_1'] + $options);

    expect(fn () => qrCodeIndexMigration()->up())
        ->toThrow(RuntimeException::class, 'Unexpected definition for qr_tokens.code_1');

    $index = qrCodeIndex($collection);
    expect($index)->not->toBeNull()
        ->and($index->getKey())->toBe($key)
        ->and($index->isUnique())->toBe($options['unique'] ?? false)
        ->and($index->isSparse())->toBe($options['sparse'] ?? false);
})->with([
    'not unique' => [['unique' => false], ['code' => 1]],
    'sparse' => [['unique' => true, 'sparse' => true], ['code' => 1]],
    'wrong key' => [['unique' => true], ['other_code' => 1]],
    'wrong partial filter' => [[
        'unique' => true,
        'partialFilterExpression' => ['code' => ['$exists' => true]],
    ], ['code' => 1]],
]);

test('up rejects a missing prerequisite collection', function () {
    expect(fn () => qrCodeIndexMigration()->up())
        ->toThrow(RuntimeException::class, 'prerequisite qr_tokens collection is missing');
});

test('down restores the legacy index only when existing documents are compatible', function () {
    $collection = qrCodeIndexCollection();
    $collection->createIndex(['code' => 1], [
        'name' => 'code_1', 'unique' => true,
        'partialFilterExpression' => ['code' => ['$type' => 'string']],
    ]);
    $collection->insertOne(['code' => 'LEGACY-ONE']);
    $collection->insertOne(['type' => 'dynamic', 'code_hash' => 'hash-one']);

    qrCodeIndexMigration()->down();

    $index = qrCodeIndex($collection);
    expect($index)->not->toBeNull()
        ->and($index->getKey())->toBe(['code' => 1])
        ->and($index->isUnique())->toBeTrue()
        ->and($index->offsetExists('partialFilterExpression'))->toBeFalse()
        ->and($collection->countDocuments([]))->toBe(2)
        ->and(fn () => $collection->insertOne(['code_hash' => 'hash-two']))
        ->toThrow(BulkWriteException::class);
});

test('down refuses an incompatible rollback without changing the index or documents', function () {
    $collection = qrCodeIndexCollection();
    $collection->createIndex(['code' => 1], [
        'name' => 'code_1', 'unique' => true,
        'partialFilterExpression' => ['code' => ['$type' => 'string']],
    ]);
    $collection->insertOne(['type' => 'dynamic', 'code_hash' => 'hash-one']);
    $collection->insertOne(['type' => 'identification', 'code_hash' => 'hash-two']);

    expect(fn () => qrCodeIndexMigration()->down())
        ->toThrow(RuntimeException::class, 'MongoDB rejected the full unique index preflight');

    expectPartialQrCodeIndex($collection);
    expect($collection->countDocuments([]))->toBe(2);
});

test('down rejects null and missing code collision before dropping the partial index', function () {
    $collection = qrCodeIndexCollection();
    $collection->createIndex(['code' => 1], [
        'name' => 'code_1', 'unique' => true,
        'partialFilterExpression' => ['code' => ['$type' => 'string']],
    ]);
    $collection->insertOne(['type' => 'dynamic', 'code' => null]);
    $collection->insertOne(['type' => 'identification']);

    expect(fn () => qrCodeIndexMigration()->down())
        ->toThrow(RuntimeException::class, 'MongoDB rejected the full unique index preflight');

    expectPartialQrCodeIndex($collection);
    expect($collection->countDocuments([]))->toBe(2);
});

test('down rejects duplicate non-string code values before dropping the partial index', function () {
    $collection = qrCodeIndexCollection();
    $collection->createIndex(['code' => 1], [
        'name' => 'code_1', 'unique' => true,
        'partialFilterExpression' => ['code' => ['$type' => 'string']],
    ]);
    $collection->insertOne(['type' => 'dynamic', 'code' => 12345]);
    $collection->insertOne(['type' => 'identification', 'code' => 12345]);

    expect(fn () => qrCodeIndexMigration()->down())
        ->toThrow(RuntimeException::class, 'MongoDB rejected the full unique index preflight');

    expectPartialQrCodeIndex($collection);
    expect($collection->countDocuments([]))->toBe(2);
});
