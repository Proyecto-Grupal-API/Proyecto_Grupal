<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlsrv';
    public function up(): void
    {
        Schema::connection('sqlsrv')->create('cash_registers', function (Blueprint $table) {
            $table->id(); $table->uuid('public_id')->unique();
            $table->string('association_id', 255)->index();
            $table->string('name', 100); $table->string('currency', 3)->default('MXN');
            $table->string('status', 30); $table->string('created_by', 255); $table->timestamps();
        });
        Schema::connection('sqlsrv')->create('cash_shifts', function (Blueprint $table) {
            $table->id(); $table->uuid('public_id')->unique();
            $table->foreignId('cash_register_id')->constrained('cash_registers');
            $table->string('agent_id', 255); $table->bigInteger('opening_amount_cents');
            foreach (['counted_amount_cents', 'difference_cents', 'cash_in_cents', 'cash_out_cents', 'adjustment_cents', 'expected_amount_cents'] as $field) $table->bigInteger($field)->nullable();
            $table->string('status', 30); $table->string('opening_key', 255)->unique();
            $table->string('closing_key', 255)->nullable(); $table->string('closed_by', 255)->nullable();
            $table->text('closing_reason')->nullable();
            $table->timestamp('opened_at'); $table->timestamp('closed_at')->nullable()->index(); $table->timestamps();
        });
        DB::connection('sqlsrv')->statement("CREATE UNIQUE INDEX cash_shifts_one_open ON cash_shifts(cash_register_id) WHERE status = 'OPEN'");
        DB::connection('sqlsrv')->statement('CREATE UNIQUE INDEX cash_shifts_closing_key_unique ON cash_shifts(closing_key) WHERE closing_key IS NOT NULL');
        Schema::connection('sqlsrv')->create('cash_movements', function (Blueprint $table) {
            $table->id(); $table->uuid('public_id')->unique();
            $table->foreignId('cash_shift_id')->constrained('cash_shifts');
            $table->string('type', 30); $table->bigInteger('amount_cents');
            $table->string('idempotency_key', 255)->unique(); $table->string('request_hash', 64);
            $table->string('actor_id', 255); $table->text('reason');
            $table->uuid('wallet_id')->nullable();
            $table->foreign('wallet_id')->references('public_id')->on('wallets');
            $table->string('reference_type', 50)->nullable(); $table->string('reference_id', 100)->nullable();
            $table->timestamps();
            $table->index(['reference_type', 'reference_id']);
        });
    }
    public function down(): void
    {
        foreach (['cash_movements', 'cash_shifts', 'cash_registers'] as $table) Schema::connection('sqlsrv')->dropIfExists($table);
    }
};
