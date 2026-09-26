<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->create(
            'topups',
            function (Blueprint $table) {
                $table->id();

                $table->uuid('public_id')->unique();

                $table->string('folio', 50)->unique();

                $table->uuid('wallet_id');

                $table->bigInteger('amount_cents');

                $table->string('currency', 3)
                    ->default('MXN');

                $table->string('method', 30);

                $table->string('status', 30);

                $table->string('agent_id', 255)
                    ->nullable();

                $table->uuid('cash_shift_id')
                    ->nullable();

                $table->string('external_reference', 255)
                    ->nullable();

                $table->timestamps();

                $table->foreign('wallet_id')
                    ->references('public_id')
                    ->on('wallets');

                $table->index('wallet_id');
                $table->index('status');
                $table->index('agent_id');
                $table->index('cash_shift_id');
            }
        );
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')
            ->dropIfExists('topups');
    }
};