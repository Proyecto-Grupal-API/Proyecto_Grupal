<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('credential_events', function (Blueprint $collection) {
            $collection->index('nfc_card_id');
            $collection->index('performed_by');
            $collection->index('event_type');
            $collection->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('credential_events');
    }
};
