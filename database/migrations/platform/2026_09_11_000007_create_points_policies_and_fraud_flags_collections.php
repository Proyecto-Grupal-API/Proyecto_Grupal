<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('platform')->create('points_policies', function (Blueprint $collection) {
            $collection->index('policy_type');
            $collection->index('status');
        });

        Schema::connection('platform')->create('fraud_flags', function (Blueprint $collection) {
            $collection->index(['student_id' => 1, 'created_at' => -1]);
            $collection->index('status');
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('points_policies');
        Schema::connection('platform')->dropIfExists('fraud_flags');
    }
};
