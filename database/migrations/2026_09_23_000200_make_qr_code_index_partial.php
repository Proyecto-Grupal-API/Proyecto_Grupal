<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\Collection;
use MongoDB\Model\IndexInfo;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        $collection = $this->collection();
        $state = $this->indexState($collection);

        if ($state === 'partial') {
            return;
        }

        // Keep uniqueness for legacy plaintext codes while allowing any
        // number of new documents without a code field.
        // DDL is not atomic: an interrupted run may leave the index absent.
        if ($state === 'legacy') {
            $collection->dropIndex('code_1');
        }

        $collection->createIndex(['code' => 1], [
            'name' => 'code_1',
            'unique' => true,
            'partialFilterExpression' => ['code' => ['$type' => 'string']],
        ]);
    }

    public function down(): void
    {
        $collection = $this->collection();
        $state = $this->indexState($collection);

        if ($state === 'legacy') {
            return;
        }

        if ($state === 'partial') {
            // Let MongoDB apply its own BSON/index-key uniqueness semantics
            // while the partial index is still intact. A temporary full
            // unique index rejects missing/null and non-string collisions.
            $probeName = 'qr_code_legacy_rollback_probe';
            foreach ($collection->listIndexes() as $index) {
                if ($index->getName() === $probeName) {
                    throw new RuntimeException('Cannot restore code_1: unexpected rollback probe index already exists.');
                }
            }

            try {
                $collection->createIndex(['code' => 1], [
                    'name' => $probeName,
                    'unique' => true,
                ]);
            } catch (Throwable $exception) {
                throw new RuntimeException('Cannot restore code_1: MongoDB rejected the full unique index preflight.', 0, $exception);
            }

            // MongoDB cannot keep two equivalent full indexes with distinct
            // names. Writes must remain paused through the DDL window.
            $collection->dropIndex($probeName);
            $collection->dropIndex('code_1');
        }

        $collection->createIndex(['code' => 1], [
            'name' => 'code_1',
            'unique' => true,
        ]);
    }

    private function collection(): Collection
    {
        $connection = DB::connection('mongodb');
        $database = $connection->getMongoClient()
            ->selectDatabase($connection->getDatabaseName());

        foreach ($database->listCollectionNames() as $name) {
            if ($name === 'qr_tokens') {
                return $connection->getCollection('qr_tokens');
            }
        }

        throw new RuntimeException('Cannot update code_1: prerequisite qr_tokens collection is missing.');
    }

    private function indexState(Collection $collection): string
    {
        $found = false;

        foreach ($collection->listIndexes() as $index) {
            if ($index->getName() !== 'code_1') {
                continue;
            }

            $found = true;

            if (! $this->hasExpectedBaseDefinition($index)) {
                break;
            }

            if (! $index->offsetExists('partialFilterExpression')) {
                return 'legacy';
            }

            $filter = (array) $index['partialFilterExpression'];
            if (count($filter) === 1 && array_key_exists('code', $filter)) {
                $codeFilter = (array) $filter['code'];
                if (count($codeFilter) === 1 && ($codeFilter['$type'] ?? null) === 'string') {
                    return 'partial';
                }
            }

            break;
        }

        if (! $found) {
            return 'absent';
        }

        throw new RuntimeException('Unexpected definition for qr_tokens.code_1; inspect the index before changing it.');
    }

    private function hasExpectedBaseDefinition(IndexInfo $index): bool
    {
        $key = $index->getKey();

        return count($key) === 1
            && ($key['code'] ?? null) === 1
            && $index->offsetExists('unique')
            && $index['unique'] === true
            && ! $index->isSparse()
            && ! $index->offsetExists('collation')
            && ! $index->offsetExists('expireAfterSeconds');
    }
};
