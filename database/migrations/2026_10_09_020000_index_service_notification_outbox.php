<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;
return new class extends Migration
{
    protected $connection = 'mongodb';
    public function up(): void
    {
        if (! Schema::hasTable('service_notification_outbox')) { Schema::create('service_notification_outbox'); }
        Schema::table('service_notification_outbox', function (Blueprint $c) {
            $c->unique('key', 'service_notice_key');
            $c->index(['status', 'next_attempt_at'], 'service_notice_pending');
            $c->index(['source_class', 'source_id'], 'service_notice_source');
        });
    }
    public function down(): void
    {
        Schema::table('service_notification_outbox', function (Blueprint $c) {
            foreach (['service_notice_key', 'service_notice_pending', 'service_notice_source'] as $name) { $c->dropIndex($name); }
        });
    }
};
