<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $collection) {
            $collection->index(['entity_type' => 1, 'entity_id' => 1, 'created_at' => -1]);
            $collection->index('user_id');
            $collection->index('correlation_id');
            $collection->index('action');
        });

        Schema::create('audit_correlations', function (Blueprint $collection) {
            $collection->unique('correlation_id');
            $collection->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('audit_correlations');
    }
};
