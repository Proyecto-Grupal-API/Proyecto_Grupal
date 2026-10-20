<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('points_ledger', function (Blueprint $collection) {
            $collection->index(['student_id' => 1, 'created_at' => -1]);
            $collection->unique('idempotency_key');
            $collection->index('reference_type');
            $collection->index('source_team');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('points_ledger');
    }
};
