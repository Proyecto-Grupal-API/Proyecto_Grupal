<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlsrv';
    public function up(): void
    {
        Schema::connection('sqlsrv')->table('ledger_entries', function (Blueprint $table) {
            // NULL retains compatibility with historical entries: available delta = amount.
            $table->bigInteger('available_delta_cents')->nullable();
            $table->bigInteger('held_delta_cents')->default(0);
        });
        Schema::connection('sqlsrv')->create('wallet_holds', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->uuid('wallet_id');
            $table->bigInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('operation_type', 100);
            $table->string('reference_type', 100);
            $table->string('reference_id', 255);
            $table->string('status', 20);
            $table->dateTime('expires_at');
            $table->string('requested_by', 255);
            $table->string('reason', 1000);
            $table->uuid('hold_transaction_id')->unique();
            $table->uuid('closing_transaction_id')->nullable();
            $table->string('closed_by', 255)->nullable();
            $table->string('closing_reason', 1000)->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->foreign('wallet_id')->references('public_id')->on('wallets');
            $table->foreign('hold_transaction_id')->references('public_id')->on('financial_transactions');
            $table->foreign('closing_transaction_id')->references('public_id')->on('financial_transactions');
            $table->index(['status', 'expires_at']);
            $table->index(['wallet_id', 'status']);
        });
    }
    public function down(): void
    {
        Schema::connection('sqlsrv')->dropIfExists('wallet_holds');
        Schema::connection('sqlsrv')->table('ledger_entries', function (Blueprint $table) {
            $table->dropColumn(['available_delta_cents', 'held_delta_cents']);
        });
    }
};
