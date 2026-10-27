<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('platform')->getCollection('redemptions')->createIndex(['idempotency_key' => 1], ['unique' => true, 'partialFilterExpression' => ['idempotency_key' => ['$type' => 'string']]]);
    }

    public function down(): void
    {
        DB::connection('platform')->getCollection('redemptions')->dropIndex('idempotency_key_1');
    }
};
