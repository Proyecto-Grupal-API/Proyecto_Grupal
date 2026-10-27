<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2.10 (REQ-E2-2.10-01) Evidencia reproducible en alertas:
 * correlación, resultado de la operación y ventana del periodo evaluado.
 *
 * observed_value / threshold_value pasan a ser opcionales porque una
 * alerta CONTROL_NO_EVALUADO no tiene valores medidos (el control no
 * pudo ejecutarse). Se registra NULL en lugar de un valor inventado.
 */
return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->table(
            'transaction_alerts',
            function (Blueprint $table) {
                $table->string('correlation_id', 100)->nullable();
                // BLOQUEADA | EXCEDENTE_NO_PREVENIDO | REGISTRADA_CON_ALERTA | NO_EVALUADA
                $table->string('outcome', 40)->nullable();
                $table->dateTime('period_start')->nullable();
                $table->dateTime('period_end')->nullable();

                $table->index('correlation_id');
                $table->index('alert_type');
            }
        );

        Schema::connection('sqlsrv')->table(
            'transaction_alerts',
            function (Blueprint $table) {
                $table->bigInteger('observed_value')->nullable()->change();
                $table->bigInteger('threshold_value')->nullable()->change();
            }
        );
    }

    public function down(): void
    {
        // Para volver a NOT NULL hay que eliminar los NULL. Solo existen en
        // alertas CONTROL_NO_EVALUADO, que no existían antes de esta versión.
        DB::connection('sqlsrv')->table('transaction_alerts')
            ->whereNull('observed_value')
            ->update(['observed_value' => 0]);

        DB::connection('sqlsrv')->table('transaction_alerts')
            ->whereNull('threshold_value')
            ->update(['threshold_value' => 0]);

        Schema::connection('sqlsrv')->table(
            'transaction_alerts',
            function (Blueprint $table) {
                $table->bigInteger('observed_value')->nullable(false)->change();
                $table->bigInteger('threshold_value')->nullable(false)->change();
            }
        );

        Schema::connection('sqlsrv')->table(
            'transaction_alerts',
            function (Blueprint $table) {
                $table->dropIndex(['correlation_id']);
                $table->dropIndex(['alert_type']);
                $table->dropColumn([
                    'correlation_id',
                    'outcome',
                    'period_start',
                    'period_end',
                ]);
            }
        );
    }
};
