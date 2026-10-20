<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('points_policies', function (Blueprint $collection) {
            $collection->index('policy_type');
            $collection->index('status');
        });

        Schema::create('fraud_flags', function (Blueprint $collection) {
            $collection->index(['student_id' => 1, 'created_at' => -1]);
            $collection->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('points_policies');
        Schema::dropIfExists('fraud_flags');
    }
};
