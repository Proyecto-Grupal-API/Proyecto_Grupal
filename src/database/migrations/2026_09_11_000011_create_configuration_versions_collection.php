<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuration_versions', function (Blueprint $collection) {
            $collection->index(['config_key' => 1, 'status' => 1]);
            $collection->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_versions');
    }
};
