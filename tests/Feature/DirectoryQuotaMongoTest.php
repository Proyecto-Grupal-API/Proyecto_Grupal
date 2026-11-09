<?php

use App\Services\Directory\DirectoryQuotaStore;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;
use Tests\Support\E1TestTarget;

/** PREPARED ONLY. Never run without separate persistent-test authorization. */
beforeEach(function () {
    // Tests\TestCase has already attested and dropped its exclusive E1 test DB.
    E1TestTarget::assertApplication();
    $database = DB::connection('mongodb')->getDatabase();
    $database->createCollection(DirectoryQuotaStore::COLLECTION);
    $this->quotaCollection = $database->selectCollection(DirectoryQuotaStore::COLLECTION);
    $this->quotaCollection->insertOne(DirectoryQuotaStore::initialState('isolated-quota-test', 'generation-1', 'synthetic-client', 100));
    $this->quotaStore = new DirectoryQuotaStore;
});

test('Mongo quota evaluates limits and returns acknowledged postimages', function () {
    for ($i = 0; $i < 30; $i++) {
        expect($this->quotaStore->consume('isolated-quota-test', 'generation-1', 'synthetic-client', 'batch', 1, 100)['status'])
            ->toBe(DirectoryQuotaStore::ADMITTED);
    }
    expect($this->quotaStore->consume('isolated-quota-test', 'generation-1', 'synthetic-client', 'batch', 1, 100)['status'])
        ->toBe(DirectoryQuotaStore::EXHAUSTED);
    $state = $this->quotaCollection->findOne();
    expect($state->windows->references_day->used)->toBe(30);
    expect($state->windows->requests_batch->used)->toBe(30);
});

test('Mongo quota shares persisted state between store instances and separates deployments and clients', function () {
    expect($this->quotaStore->consume('isolated-quota-test', 'generation-1', 'synthetic-client', 'batch', 2, 100)['status'])
        ->toBe(DirectoryQuotaStore::ADMITTED);
    $anotherInstance = new DirectoryQuotaStore;
    expect($anotherInstance->consume('isolated-quota-test', 'generation-1', 'synthetic-client', 'resolve', 1, 100)['status'])
        ->toBe(DirectoryQuotaStore::ADMITTED);
    expect($anotherInstance->consume('other-deployment', 'generation-1', 'synthetic-client', 'batch', 1, 100)['status'])
        ->toBe(DirectoryQuotaStore::UNAVAILABLE);
    expect($anotherInstance->consume('isolated-quota-test', 'generation-1', 'other-client', 'batch', 1, 100)['status'])
        ->toBe(DirectoryQuotaStore::UNAVAILABLE);
    expect($this->quotaCollection->countDocuments())->toBe(1);
    expect($this->quotaCollection->findOne()->windows->references_day->used)->toBe(3);
});

test('Mongo quota refuses missing restored or corrupt state without creating a replacement', function (string $case) {
    E1TestTarget::assertApplication();
    if ($case === 'missing') { $this->quotaCollection->deleteMany([]); }
    if ($case === 'generation') { $this->quotaCollection->updateOne([], ['$set' => ['generation' => 'old-restored-generation']]); }
    if ($case === 'corrupt') { $this->quotaCollection->updateOne([], ['$set' => ['windows.references_day.used' => '0']]); }
    expect($this->quotaStore->consume('isolated-quota-test', 'generation-1', 'synthetic-client', 'batch', 1, 100)['status'])
        ->toBe(DirectoryQuotaStore::UNAVAILABLE);
    expect($this->quotaCollection->countDocuments())->toBe($case === 'missing' ? 0 : 1);
})->with(['missing', 'generation', 'corrupt']);

test('Mongo quota resets expired minute windows without resetting a live daily window', function () {
    E1TestTarget::assertApplication();
    $database = DB::connection('mongodb')->getDatabase();
    $serverNow = $database->command(['hello' => 1])->toArray()[0]->localTime;
    $oldEnd = 1600000000000;
    $this->quotaCollection->updateOne([], ['$set' => [
        'windows.requests_batch' => ['used' => 30, 'starts_at' => new UTCDateTime($oldEnd - 60000), 'ends_at' => new UTCDateTime($oldEnd)],
        'windows.references_minute' => ['used' => 3000, 'starts_at' => new UTCDateTime($oldEnd - 60000), 'ends_at' => new UTCDateTime($oldEnd)],
        'windows.references_day' => ['used' => 10, 'starts_at' => $serverNow, 'ends_at' => new UTCDateTime((int) (string) $serverNow + 86400000)],
    ]]);
    expect($this->quotaStore->consume('isolated-quota-test', 'generation-1', 'synthetic-client', 'batch', 1, 100)['status'])
        ->toBe(DirectoryQuotaStore::ADMITTED);
    $windows = $this->quotaCollection->findOne()->windows;
    expect($windows->requests_batch->used)->toBe(1);
    expect($windows->references_minute->used)->toBe(1);
    expect($windows->references_day->used)->toBe(11);
    expect((string) $windows->references_day->starts_at)->toBe((string) $serverNow);
});

// Multiprocess races, actual application/server restarts, write-concern failures and
// lost server responses require an independently authorized fault/concurrency harness.
// No subprocess, service restart, role change or network fault is triggered by this file.
