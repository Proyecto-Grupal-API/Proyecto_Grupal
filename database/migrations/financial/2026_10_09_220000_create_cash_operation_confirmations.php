<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'sqlsrv';
    public function up(): void
    {
        Schema::connection('sqlsrv')->create('cash_operation_confirmations', function (Blueprint $table) {
            $table->id(); $table->uuid('public_id')->unique();
            $table->foreignId('cash_shift_id')->constrained('cash_shifts');
            $table->uuid('wallet_id'); $table->foreign('wallet_id')->references('public_id')->on('wallets');
            $table->string('operator_id', 255); $table->string('student_id', 255);
            $table->string('operation', 20); $table->bigInteger('amount_cents'); $table->string('currency', 3);
            $table->text('reason'); $table->string('status', 20)->default('PENDING');
            $table->string('request_key', 255)->unique(); $table->string('request_hash', 64);
            $table->timestamp('expires_at'); $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('rejected_at')->nullable(); $table->timestamp('cancelled_at')->nullable();
            $table->string('confirmed_by', 255)->nullable(); $table->string('confirmation_ip', 45)->nullable();
            $table->string('settlement_key', 255)->nullable(); $table->uuid('cash_movement_id')->nullable();
            $table->foreign('cash_movement_id')->references('public_id')->on('cash_movements');
            $table->timestamp('consumed_at')->nullable(); $table->timestamps();
            $table->index(['student_id', 'status']);
        });
        DB::connection('sqlsrv')->statement('CREATE UNIQUE INDEX cash_confirmation_settlement_key_unique ON cash_operation_confirmations(settlement_key) WHERE settlement_key IS NOT NULL');
        DB::connection('sqlsrv')->statement('CREATE UNIQUE INDEX cash_confirmation_movement_unique ON cash_operation_confirmations(cash_movement_id) WHERE cash_movement_id IS NOT NULL');
    }
    public function down(): void { Schema::connection('sqlsrv')->dropIfExists('cash_operation_confirmations'); }
};
