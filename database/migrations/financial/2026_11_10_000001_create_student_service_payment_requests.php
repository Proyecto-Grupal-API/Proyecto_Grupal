<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobros de Servicios al Estudiante (E5) que el alumno autoriza en Finanzas:
 * pago de locker (locker_rental) y de impresiones (print_order).
 * Una fila por concepto y registro de origen; el origen es inmutable.
 */
return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->create('student_service_payment_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('concept', 40);
            $table->string('source_reference', 24);
            $table->string('client_id', 100);
            $table->string('status', 20);
            $table->string('student_id', 24)->nullable();
            $table->bigInteger('amount_cents')->nullable();
            $table->uuid('recipient_wallet_id')->nullable();
            $table->uuid('transaction_id')->nullable();
            $table->timestamps();

            $table->unique(['concept', 'source_reference'], 'student_service_payment_origin_unique');
            $table->foreign('recipient_wallet_id')->references('public_id')->on('wallets');
            $table->foreign('transaction_id')->references('public_id')->on('financial_transactions');
        });
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')->dropIfExists('student_service_payment_requests');
    }
};
