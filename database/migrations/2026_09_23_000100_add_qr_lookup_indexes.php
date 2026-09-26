<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        $collection = DB::connection('mongodb')->getCollection('qr_tokens');

        $collection->createIndex(['code_hash' => 1], [
            'name' => 'qr_code_hash_unique',
            'unique' => true,
            'partialFilterExpression' => ['code_hash' => ['$type' => 'string']],
        ]);

        $collection->createIndex(['short_code_hash' => 1, 'type' => 1, 'expires_at' => 1], [
            'name' => 'qr_short_code_hash_lookup',
            'partialFilterExpression' => ['short_code_hash' => ['$type' => 'string']],
        ]);

        $collection->createIndex(['short_code_hash' => 1], [
            'name' => 'qr_short_code_claim_unique',
            'unique' => true,
            'partialFilterExpression' => [
                'short_code_hash' => ['$type' => 'string'],
                'type' => 'dynamic',
                'short_code_claimed' => true,
            ],
        ]);
    }

    public function down(): void
    {
        $collection = DB::connection('mongodb')->getCollection('qr_tokens');

        $collection->dropIndex('qr_short_code_claim_unique');
        $collection->dropIndex('qr_short_code_hash_lookup');
        $collection->dropIndex('qr_code_hash_unique');
    }
};
