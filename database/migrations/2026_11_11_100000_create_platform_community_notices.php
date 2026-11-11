<?php

use App\Models\PlatformModule\CommunityNotice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $notices = DB::connection('platform')->getCollection(CommunityNotice::COLLECTION);
        $notices->createIndex(['idempotency_key' => 1], ['unique' => true]);
        $notices->createIndex(['status' => 1, 'next_attempt_at' => 1, 'locked_until' => 1]);
        // Scan rounds use Mongo's built-in unique _id index, one document per source.
    }

    public function down(): void
    {
        DB::connection('platform')->getCollection(CommunityNotice::SCANS)->drop();
        DB::connection('platform')->getCollection(CommunityNotice::COLLECTION)->drop();
    }
};
