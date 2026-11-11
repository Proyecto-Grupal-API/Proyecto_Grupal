<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $c=DB::connection('mongodb')->getCollection('community_analytics_deliveries');
        $c->createIndex(['event_id'=>1],['unique'=>true]);
        $c->createIndex(['status'=>1,'next_attempt_at'=>1]);
    }
    public function down(): void { DB::connection('mongodb')->getDatabase()->dropCollection('community_analytics_deliveries'); }
};
