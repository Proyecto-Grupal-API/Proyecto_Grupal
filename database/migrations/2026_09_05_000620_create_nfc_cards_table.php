<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('nfc_cards', function (Blueprint $collection) {
            $collection->unique('uid');
            $collection->index('user_id');
            $collection->index('registered_by');
            $collection->index('status');
            $collection->index('registered_at');
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('nfc_cards');
    }
};
