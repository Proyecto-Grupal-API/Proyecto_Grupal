<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        // Non-unique indexes only; preserve the effective collection and its documents.
        $sessions = DB::connection('mongodb')->getCollection('user_sessions');
        foreach (['user_id', 'device_id', 'last_activity_at'] as $field) {
            $sessions->createIndex([$field => 1]);
        }
    }

    public function down(): void
    {
        // Keep these additive indexes: an equivalent index may predate this migration.
        // Never drop a session collection or remove historical documents on rollback.
    }
};
