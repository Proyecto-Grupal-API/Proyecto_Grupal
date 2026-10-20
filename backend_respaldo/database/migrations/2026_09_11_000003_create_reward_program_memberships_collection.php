<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_program_memberships', function (Blueprint $collection) {
            $collection->unique('business_id');
            $collection->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_program_memberships');
    }
};
