<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection('pgsql')->create(
            'ledger_entries',
            function (Blueprint $table) {
                $table->id();

                $table->uuid('public_id')->unique();

                $table->uuid('transaction_id');
                $table->uuid('wallet_id');

                $table->string('movement_type', 50);

                $table->bigInteger('amount_cents');
                $table->bigInteger('balance_after_cents');

                $table->timestamps();

                $table->foreign('transaction_id')
                    ->references('public_id')
                    ->on('financial_transactions')
                    ->restrictOnDelete();

                $table->foreign('wallet_id')
                    ->references('public_id')
                    ->on('wallets')
                    ->restrictOnDelete();

                $table->index('transaction_id');
                $table->index('wallet_id');
                $table->index('movement_type');
            }
        );
    }

    public function down(): void
    {
        Schema::connection('pgsql')
            ->dropIfExists('ledger_entries');
    }
};