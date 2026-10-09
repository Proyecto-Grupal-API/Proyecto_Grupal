<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlsrv';
    public function up(): void
    {
        Schema::connection('sqlsrv')->create('bonus_administrative_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->uuid('bonus_id');
            $table->string('action', 30);
            $table->string('actor_id', 255);
            $table->string('idempotency_key', 255)->unique();
            $table->string('request_hash', 64);
            $table->text('reason');
            $table->longText('request_data');
            $table->longText('before_data')->nullable();
            $table->longText('after_data');
            $table->timestamp('created_at');
            $table->foreign('bonus_id')->references('public_id')->on('bonuses');
            $table->index(['bonus_id', 'id']);
            $table->index('actor_id');
        });
    }
    public function down(): void
    {
        Schema::connection('sqlsrv')->dropIfExists('bonus_administrative_operations');
    }
};
