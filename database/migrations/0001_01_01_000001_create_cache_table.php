<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        if (! Schema::hasTable('cache')) {
            Schema::create('cache');
        }
        Schema::table('cache', function (Blueprint $collection) {
            $collection->unique('key');
        });
        if (! Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks');
        }
        Schema::table('cache_locks', function (Blueprint $collection) {
            $collection->unique('key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
    }
};
