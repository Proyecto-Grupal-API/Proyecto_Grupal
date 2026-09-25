<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('earning_rules', function (Blueprint $collection) {
            $collection->index('business_id');
            $collection->index('status');
            $collection->index(['valid_from' => 1, 'valid_until' => 1]);
        });

        Schema::create('point_campaigns', function (Blueprint $collection) {
            $collection->index('status');
            $collection->index(['starts_at' => 1, 'ends_at' => 1]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('earning_rules');
        Schema::dropIfExists('point_campaigns');
    }
};
