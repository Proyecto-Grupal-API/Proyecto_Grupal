<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Tres modelos (PointsLedger, ServiceClient, AcademicStatusHistory) declaraban su colección con `$collection`, que
 * mongodb/laravel-mongodb 5 ignora. Los datos siempre se escribieron en `points_ledgers`, `service_clients` y
 * `academic_status_histories`, mientras los índices se crearon en `points_ledger`, `oauth_service_clients` y
 * `academic_status_history`, que quedaron vacías. Consecuencia: la idempotencia del ledger de puntos y la unicidad
 * de `client_id` dependían sólo de una comprobación previa en la aplicación.
 *
 * Esta migración va sólo hacia delante y no mueve, renombra ni borra ningún documento ni colección:
 *  - crea en las colecciones que contienen los datos los índices que les correspondían;
 *  - antes de cada índice único cuenta las claves repetidas; si hay alguna, se detiene sin crear ese índice y sin
 *    tocar los datos, para que una persona decida qué hacer con los repetidos;
 *  - es repetible: los índices que ya existen se dejan como están.
 * Las colecciones vacías con el nombre antiguo se conservan.
 */
return new class extends Migration
{
    /** @var array<int, array{connection: string, collection: string, key: string, name: string}> */
    private const UNIQUE = [
        ['connection' => 'platform', 'collection' => 'points_ledgers', 'key' => 'idempotency_key', 'name' => 'points_ledgers_idempotency_key_unique'],
        ['connection' => 'mongodb', 'collection' => 'service_clients', 'key' => 'client_id', 'name' => 'service_clients_client_id_unique'],
    ];

    /** @var array<int, array{connection: string, collection: string, keys: array<string, int>}> */
    private const PLAIN = [
        ['connection' => 'platform', 'collection' => 'points_ledgers', 'keys' => ['student_id' => 1, 'created_at' => -1]],
        ['connection' => 'platform', 'collection' => 'points_ledgers', 'keys' => ['source_domain' => 1, 'source_reference' => 1]],
        ['connection' => 'mongodb', 'collection' => 'academic_status_histories', 'keys' => ['student_profile_id' => 1, 'changed_at' => 1]],
        ['connection' => 'mongodb', 'collection' => 'academic_status_histories', 'keys' => ['changed_by' => 1]],
    ];

    public function up(): void
    {
        // Primero se comprueban todos los únicos: si alguno no puede crearse, no se crea ninguno.
        foreach (self::UNIQUE as $index) {
            $repeated = $this->repeatedKeys($index['connection'], $index['collection'], $index['key']);
            if ($repeated > 0) {
                throw new RuntimeException("No se creó el índice único de `{$index['key']}` en `{$index['collection']}`: hay {$repeated} clave(s) repetida(s). "
                    .'No se modificó ningún documento. Revise los repetidos y vuelva a ejecutar la migración.');
            }
        }
        foreach (self::UNIQUE as $index) {
            // Parcial: sólo compiten los documentos que traen la clave como texto; los que no la traen no chocan entre sí.
            DB::connection($index['connection'])->getCollection($index['collection'])->createIndex([$index['key'] => 1],
                ['name' => $index['name'], 'unique' => true, 'partialFilterExpression' => [$index['key'] => ['$type' => 'string']]]);
        }
        foreach (self::PLAIN as $index) {
            DB::connection($index['connection'])->getCollection($index['collection'])->createIndex($index['keys']);
        }
    }

    /** Sólo hacia delante: retirar los índices devolvería el ledger a depender únicamente de la comprobación previa. */
    public function down(): void {}

    private function repeatedKeys(string $connection, string $collection, string $key): int
    {
        $groups = DB::connection($connection)->getCollection($collection)->aggregate([
            ['$match' => [$key => ['$type' => 'string']]],
            ['$group' => ['_id' => '$'.$key, 'n' => ['$sum' => 1]]],
            ['$match' => ['n' => ['$gt' => 1]]],
            ['$count' => 'groups'],
        ])->toArray();

        return (int) ($groups[0]['groups'] ?? 0);
    }
};
