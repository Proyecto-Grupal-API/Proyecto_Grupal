<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rewards', function (Blueprint $collection) {
            $collection->index('business_id');
            $collection->index('status');
            $collection->index('stock_source');
        });

        Schema::create('reward_items', function (Blueprint $collection) {
            $collection->index('reward_id');
            $collection->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rewards');
        Schema::dropIfExists('reward_items');
    }
};
