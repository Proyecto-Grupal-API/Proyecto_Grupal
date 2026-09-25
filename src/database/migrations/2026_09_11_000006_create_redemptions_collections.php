<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redemptions', function (Blueprint $collection) {
            $collection->index(['student_id' => 1, 'status' => 1]);
            $collection->unique('redemption_code');
            $collection->index('reward_id');
        });

        Schema::create('redemption_events', function (Blueprint $collection) {
            $collection->index(['redemption_id' => 1, 'created_at' => 1]);
            $collection->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redemptions');
        Schema::dropIfExists('redemption_events');
    }
};
