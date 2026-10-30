<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('inventory')->getCollection('integration_inbox')->createIndex(
            ['business_id' => 1, 'source_system' => 1, 'event_id' => 1], ['unique' => true, 'name' => 'inventory_inbox_identity']);
    }

    public function down(): void
    {
        DB::connection('inventory')->getCollection('integration_inbox')->dropIndex('inventory_inbox_identity');
    }
};
