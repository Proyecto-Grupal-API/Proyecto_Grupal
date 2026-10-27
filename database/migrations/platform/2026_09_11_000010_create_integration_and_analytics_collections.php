<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('platform')->create('analytics_events', function (Blueprint $collection) {
            $collection->index(['source_team' => 1, 'event_type' => 1, 'occurred_at' => -1]);
            $collection->unique('event_id');
            $collection->index('processed');
        });

        Schema::connection('platform')->create('integration_logs', function (Blueprint $collection) {
            $collection->index(['source_team' => 1, 'created_at' => -1]);
            $collection->index('status');
        });

        Schema::connection('platform')->create('webhook_events', function (Blueprint $collection) {
            $collection->unique('webhook_id');
            $collection->index('status');
            $collection->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('analytics_events');
        Schema::connection('platform')->dropIfExists('integration_logs');
        Schema::connection('platform')->dropIfExists('webhook_events');
    }
};
