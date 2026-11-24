<?php

use App\Models\AcademicStatusHistory;
use App\Models\PlatformModule\AuditLog;
use App\Models\PlatformModule\EarningRule;
use App\Models\PlatformModule\PointsAccount;
use App\Models\PlatformModule\PointsLedger;
use App\Models\ServiceClient;
use App\Services\PlatformModule\PointsLedger\DTO\EarnPointsRequest;
use App\Services\PlatformModule\PointsLedgerService;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\BulkWriteException;

/*
 * Tres modelos declaraban su colección con `$collection`, propiedad que mongodb/laravel-mongodb 5 ignora: escribían en
 * el plural del nombre del modelo, mientras las migraciones creaban los índices (entre ellos el único de idempotencia
 * del ledger de puntos) en la colección declarada, que quedaba vacía. Los datos no se mueven: el modelo declara la
 * colección que de verdad usa y una migración hacia delante crea los índices donde están los datos.
 */
const ALIGNMENT_MIGRATION = 'migrations/2026_11_15_000200_align_indexes_with_collections_in_use.php';

function alignmentIndexes(string $connection, string $collection): array
{
    $names = [];
    foreach (DB::connection($connection)->getCollection($collection)->listIndexes() as $index) {
        $names[$index->getName()] = $index->isUnique();
    }

    return $names;
}

function alignmentEntry(string $key, int $amount = 10): array
{
    return ['student_id' => 'alumno-1', 'idempotency_key' => $key, 'type' => 'earn', 'amount' => $amount, 'source_domain' => 'marketplace', 'source_reference' => 'REF-'.$key];
}

test('each model declares the collection it really writes to', function () {
    expect((new PointsLedger)->getTable())->toBe('points_ledgers')
        ->and((new ServiceClient)->getTable())->toBe('service_clients')
        ->and((new AcademicStatusHistory)->getTable())->toBe('academic_status_histories');
    foreach ([PointsLedger::class, ServiceClient::class, AcademicStatusHistory::class] as $model) {
        // La propiedad ignorada no debe volver: quien la lea creería que los datos están en otra colección.
        expect(property_exists($model, 'collection'))->toBeFalse();
    }

    PointsLedger::create(alignmentEntry('alineacion-1'));
    expect(DB::connection('platform')->getCollection('points_ledgers')->countDocuments([]))->toBe(1)
        ->and(DB::connection('platform')->getCollection('points_ledger')->countDocuments([]))->toBe(0);
});

test('the migration creates the indexes on the collections that hold the data', function () {
    (require database_path(ALIGNMENT_MIGRATION))->up();
    (require database_path(ALIGNMENT_MIGRATION))->up();   // repetirla no falla ni duplica

    $ledger = alignmentIndexes('platform', 'points_ledgers');
    expect($ledger)->toHaveKey('points_ledgers_idempotency_key_unique')->and($ledger['points_ledgers_idempotency_key_unique'])->toBeTrue()
        ->and($ledger)->toHaveKey('student_id_1_created_at_-1')->toHaveKey('source_domain_1_source_reference_1');
    $clients = alignmentIndexes('mongodb', 'service_clients');
    expect($clients)->toHaveKey('service_clients_client_id_unique')->and($clients['service_clients_client_id_unique'])->toBeTrue();
    expect(alignmentIndexes('mongodb', 'academic_status_histories'))->toHaveKey('student_profile_id_1_changed_at_1')->toHaveKey('changed_by_1');
});

test('once aligned the database itself refuses a second ledger entry with the same idempotency key', function () {
    (require database_path(ALIGNMENT_MIGRATION))->up();
    PointsLedger::create(alignmentEntry('venta-unica'));
    expect(fn () => PointsLedger::create(alignmentEntry('venta-unica', 999)))->toThrow(BulkWriteException::class);
    expect(PointsLedger::count())->toBe(1)->and((int) PointsLedger::sum('amount'))->toBe(10);

    ServiceClient::create(['name' => 'Uno', 'client_id' => 'svc_alineacion', 'secret_hash' => 'x', 'scopes' => [], 'active' => true]);
    expect(fn () => ServiceClient::create(['name' => 'Dos', 'client_id' => 'svc_alineacion', 'secret_hash' => 'y', 'scopes' => [], 'active' => true]))
        ->toThrow(BulkWriteException::class);
});

test('with repeated keys already stored the migration stops without touching any document', function () {
    $raw = DB::connection('platform')->getCollection('points_ledgers');
    $raw->insertMany([alignmentEntry('repetida', 10), alignmentEntry('repetida', 20), alignmentEntry('otra', 5)]);

    expect(fn () => (require database_path(ALIGNMENT_MIGRATION))->up())->toThrow(RuntimeException::class, 'points_ledgers');
    expect($raw->countDocuments([]))->toBe(3)
        ->and((int) collect($raw->find([])->toArray())->sum('amount'))->toBe(35)
        ->and(alignmentIndexes('platform', 'points_ledgers'))->not->toHaveKey('points_ledgers_idempotency_key_unique');
});

test('entries without an idempotency key do not collide with each other', function () {
    (require database_path(ALIGNMENT_MIGRATION))->up();
    $raw = DB::connection('platform')->getCollection('points_ledgers');
    $raw->insertMany([['student_id' => 'a', 'type' => 'adjust', 'amount' => 1], ['student_id' => 'b', 'type' => 'adjust', 'amount' => 2]]);
    expect($raw->countDocuments([]))->toBe(2);
});

test('when another request with the same key wins the race the ledger service answers with that entry and applies nothing twice', function () {
    foreach (glob(database_path('migrations/platform/*.php')) as $file) {
        (require $file)->up();
    }
    (require database_path(ALIGNMENT_MIGRATION))->up();
    EarningRule::create(['business_id' => 'BUS-ALI', 'name' => 'Uno por cada 2', 'type' => 'amount_spent', 'points_per_unit' => 1,
        'unit_amount' => 2, 'status' => 'active', 'active_from' => now()->subDay()]);
    // Entre la comprobación de repetición y la inserción, otra petición idéntica ya dejó su movimiento.
    $raced = false;
    PointsLedger::creating(function () use (&$raced) {
        if (! $raced) {
            $raced = true;
            DB::connection('platform')->getCollection('points_ledgers')->insertOne(['student_id' => 'alumno-2'] + alignmentEntry('venta-en-carrera', 20));
        }
    });
    $request = new EarnPointsRequest(studentId: 'alumno-2', businessId: 'BUS-ALI', sourceDomain: 'marketplace',
        sourceReference: 'CD3-CARRERA', saleAmount: 40.0, idempotencyKey: 'venta-en-carrera');

    $result = app(PointsLedgerService::class)->earn($request);

    expect($raced)->toBeTrue()->and($result->wasIdempotentReplay)->toBeTrue()
        ->and(PointsLedger::where('idempotency_key', 'venta-en-carrera')->count())->toBe(1)
        // Lo que esta petición iba a aplicar no quedó a medias: ni saldo ni auditoría de una segunda acumulación.
        ->and((int) (PointsAccount::where('student_id', 'alumno-2')->first()?->balance ?? 0))->toBe(0)
        ->and(AuditLog::where('action', 'points.earned')->count())->toBe(0);
});
