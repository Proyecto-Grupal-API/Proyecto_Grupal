<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// FIN-04 (auditoría E2): una confirmación de caja rechazada conserva quién la rechazó (el alumno o el control de límites)
// y, si fue un límite financiero, el motivo y la alerta del intento bloqueado. Columnas nuevas y opcionales: no cambia
// ningún registro existente ni el significado de los estados.
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sqlsrv')->table('cash_operation_confirmations', function (Blueprint $t) {
            $t->string('rejected_by', 255)->nullable();
            $t->string('rejection_reason', 500)->nullable();
            $t->uuid('rejection_alert_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')->table('cash_operation_confirmations', function (Blueprint $t) {
            $t->dropColumn(['rejected_by', 'rejection_reason', 'rejection_alert_id']);
        });
    }
};
