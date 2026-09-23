<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        $collection = DB::connection('mongodb')->getCollection('qr_tokens');

        // Keep uniqueness for legacy plaintext codes while allowing any
        // number of new documents without a code field.
        $collection->dropIndex('code_1');
        $collection->createIndex(['code' => 1], [
            'name' => 'code_1',
            'unique' => true,
            'partialFilterExpression' => ['code' => ['$type' => 'string']],
        ]);
    }

    public function down(): void
    {
        $collection = DB::connection('mongodb')->getCollection('qr_tokens');

        if ($collection->countDocuments(['code' => null]) > 1) {
            throw new RuntimeException('Cannot restore the original code index while multiple QR tokens have no plaintext code.');
        }

        $collection->dropIndex('code_1');
        $collection->createIndex(['code' => 1], [
            'name' => 'code_1',
            'unique' => true,
        ]);
    }
};
