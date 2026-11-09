<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        DB::connection('mongodb')->getCollection('role_assignments')->createIndex(
            ['source_key' => 1],
            ['name' => 'legacy_source_unique', 'unique' => true,
                'partialFilterExpression' => ['origin' => 'legacy_backfill']]
        );
    }

    public function down(): void
    {
        // Keep provenance and history; rollback only removes this index.
        DB::connection('mongodb')->getCollection('role_assignments')->dropIndex('legacy_source_unique');
    }
};
