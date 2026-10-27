<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('platform')->create('points_accounts', function (Blueprint $collection) {
            $collection->unique('student_id');
            $collection->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('points_accounts');
    }
};
