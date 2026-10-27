<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('platform')->create('reward_program_memberships', function (Blueprint $collection) {
            $collection->unique('business_id');
            $collection->index('status');
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('reward_program_memberships');
    }
};
