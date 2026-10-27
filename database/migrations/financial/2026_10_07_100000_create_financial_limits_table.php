<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2.10 Límites: políticas configurables. No se insertan valores
 * predeterminados; cada límite lo da de alta un actor autorizado.
 */
return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->create(
            'financial_limits',
            function (Blueprint $table) {
                $table->id();
                $table->uuid('public_id')->unique();

                $table->string('name', 150);

                // GLOBAL | WALLET_TYPE | OWNER | ROLE
                $table->string('subject_type', 30);
                $table->string('subject_id', 255)->nullable();

                // Valor de MovementType (RECARGA, RETIRO, PAGO...)
                $table->string('operation', 50);

                // OPERACION | DIARIO | MENSUAL
                $table->string('period', 20);

                // MONTO | CONTEO
                $table->string('metric', 20);

                $table->bigInteger('max_amount_cents')->nullable();
                $table->integer('max_count')->nullable();

                // BLOQUEAR | ALERTAR
                $table->string('action', 20);

                $table->string('currency', 3)->default('MXN');
                $table->boolean('active')->default(true);

                $table->dateTime('valid_from')->nullable();
                $table->dateTime('valid_until')->nullable();

                $table->string('created_by', 255);
                $table->string('updated_by', 255)->nullable();
                $table->string('reason', 500)->nullable();

                $table->timestamps();

                $table->index(['operation', 'active']);
                $table->index(['subject_type', 'subject_id']);
            }
        );
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')->dropIfExists('financial_limits');
    }
};
