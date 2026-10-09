<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2.10 Diferencias detectadas en una conciliación. Resolver una
 * diferencia solo la documenta: la conciliación nunca modifica saldos
 * ni ledger. Una corrección contable debe hacerse con un movimiento
 * propio (por ejemplo AJUSTE_CREDITO / AJUSTE_DEBITO).
 */
return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->create(
            'reconciliation_differences',
            function (Blueprint $table) {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->uuid('reconciliation_id');

                $table->string('check_code', 60);
                $table->string('entity_type', 50);
                $table->string('entity_id', 255);
                $table->uuid('wallet_id')->nullable();

                $table->bigInteger('expected_cents')->nullable();
                $table->bigInteger('actual_cents')->nullable();
                $table->bigInteger('difference_cents')->nullable();

                $table->json('details')->nullable();

                // ABIERTA | RESUELTA
                $table->string('status', 20);
                $table->string('resolved_by', 255)->nullable();
                $table->dateTime('resolved_at')->nullable();
                $table->string('resolution_note', 1000)->nullable();

                $table->timestamps();

                $table->foreign('reconciliation_id')
                    ->references('public_id')
                    ->on('reconciliations');

                $table->index('reconciliation_id');
                $table->index('check_code');
                $table->index('status');
            }
        );
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')
            ->dropIfExists('reconciliation_differences');
    }
};
