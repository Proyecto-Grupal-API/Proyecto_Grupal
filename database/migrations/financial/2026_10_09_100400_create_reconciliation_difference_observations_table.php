<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2.10 Qué diferencias observó cada corrida de conciliación. Permite
 * conservar el historial de corridas sin duplicar diferencias.
 */
return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->create(
            'reconciliation_difference_observations',
            function (Blueprint $table) {
                $table->id();
                $table->uuid('reconciliation_id');
                $table->uuid('difference_id');
                $table->boolean('is_new');
                $table->timestamps();

                $table->foreign('reconciliation_id')
                    ->references('public_id')
                    ->on('reconciliations');

                $table->foreign('difference_id')
                    ->references('public_id')
                    ->on('reconciliation_differences');

                $table->unique(
                    ['reconciliation_id', 'difference_id'],
                    'rdo_reconciliation_difference_unique'
                );
                $table->index('difference_id');
            }
        );
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')
            ->dropIfExists('reconciliation_difference_observations');
    }
};
