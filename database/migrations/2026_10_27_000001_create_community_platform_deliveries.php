<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('mongodb')->getCollection('community_platform_deliveries')->createIndex(['audit_id' => 1], ['unique' => true]);
    }

    public function down(): void
    {
        DB::connection('mongodb')->getCollection('community_platform_deliveries')->drop();
    }
};
