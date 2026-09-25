<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('points_accounts', function (Blueprint $collection) {
            $collection->unique('student_id');
            $collection->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('points_accounts');
    }
};
