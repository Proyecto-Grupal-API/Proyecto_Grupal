<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->table('cash_registers', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
        });
        Schema::connection('sqlsrv')->create('cash_register_changes', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('cash_register_id')->constrained('cash_registers');
            $table->unsignedInteger('version');
            $table->string('action', 30);
            $table->text('before_state')->nullable();
            $table->text('after_state');
            $table->string('actor_id', 255);
            $table->text('reason');
            $table->string('idempotency_key', 255)->unique();
            $table->string('request_hash', 64);
            $table->timestamps();
            $table->unique(['cash_register_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')->dropIfExists('cash_register_changes');
        Schema::connection('sqlsrv')->table('cash_registers', function (Blueprint $table) { $table->dropColumn('version'); });
    }
};
