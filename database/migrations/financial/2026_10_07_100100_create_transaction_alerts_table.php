<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2.10 Alertas: registro trazable de operaciones bloqueadas o que
 * exceden un límite.
 *
 * wallet_id, transaction_id y ledger_entry_id NO tienen llave foránea a
 * propósito: la alerta es un rastro de auditoría que debe sobrevivir
 * aunque cambie el resto del esquema, y una alerta de operación
 * bloqueada no tiene transacción. Los valores del límite se copian
 * (snapshot) para que un cambio posterior del límite no altere la
 * evidencia.
 */
return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->create(
            'transaction_alerts',
            function (Blueprint $table) {
                $table->id();
                $table->uuid('public_id')->unique();

                // Evita alertas duplicadas para el mismo hecho.
                $table->string('dedupe_key', 255)->unique();

                // LIMITE_EXCEDIDO | OPERACION_BLOQUEADA
                $table->string('alert_type', 40);

                // ABIERTA | EN_REVISION | RESUELTA | DESCARTADA
                $table->string('status', 20);

                $table->uuid('limit_id')->nullable();
                $table->uuid('wallet_id')->nullable();
                $table->uuid('transaction_id')->nullable();
                $table->uuid('ledger_entry_id')->nullable();

                $table->string('operation', 50);
                $table->string('reference_type', 100)->nullable();
                $table->string('reference_id', 255)->nullable();

                $table->bigInteger('amount_cents');

                // Snapshot del límite al momento de la detección.
                $table->string('limit_period', 20)->nullable();
                $table->string('limit_metric', 20)->nullable();
                $table->string('limit_action', 20)->nullable();
                $table->bigInteger('observed_value');
                $table->bigInteger('threshold_value');

                $table->json('details')->nullable();

                $table->dateTime('detected_at');

                $table->string('status_changed_by', 255)->nullable();
                $table->dateTime('status_changed_at')->nullable();
                $table->string('resolution_note', 1000)->nullable();

                $table->timestamps();

                $table->foreign('limit_id')
                    ->references('public_id')
                    ->on('financial_limits');

                $table->index('status');
                $table->index('wallet_id');
                $table->index('transaction_id');
                $table->index('detected_at');
            }
        );
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')->dropIfExists('transaction_alerts');
    }
};
