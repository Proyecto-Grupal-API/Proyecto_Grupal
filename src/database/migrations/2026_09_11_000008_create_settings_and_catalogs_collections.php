<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $collection) {
            $collection->unique('key');
        });

        Schema::create('catalogs', function (Blueprint $collection) {
            $collection->index('catalog_type');
            $collection->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('catalogs');
    }
};
