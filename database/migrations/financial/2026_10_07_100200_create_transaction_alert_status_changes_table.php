<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2.10 Trazabilidad: historial de cada cambio de estado de una alerta.
 */
return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->create(
            'transaction_alert_status_changes',
            function (Blueprint $table) {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->uuid('alert_id');

                $table->string('from_status', 20)->nullable();
                $table->string('to_status', 20);
                $table->string('actor_id', 255);
                $table->string('note', 1000)->nullable();

                $table->timestamps();

                $table->foreign('alert_id')
                    ->references('public_id')
                    ->on('transaction_alerts');

                $table->index('alert_id');
            }
        );
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')
            ->dropIfExists('transaction_alert_status_changes');
    }
};
