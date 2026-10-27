<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('platform')->create('rewards', function (Blueprint $collection) {
            $collection->index('business_id');
            $collection->index('status');
            $collection->index('stock_source');
        });

        Schema::connection('platform')->create('reward_items', function (Blueprint $collection) {
            $collection->index('reward_id');
            $collection->index('status');
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('rewards');
        Schema::connection('platform')->dropIfExists('reward_items');
    }
};
