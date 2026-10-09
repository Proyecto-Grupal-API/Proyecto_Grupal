<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2.10 Conciliación: cada ejecución queda registrada. Se permiten
 * varias ejecuciones para la misma fecha (por ejemplo, después de
 * corregir una diferencia) para conservar el historial completo.
 */
return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->create(
            'reconciliations',
            function (Blueprint $table) {
                $table->id();
                $table->uuid('public_id')->unique();

                $table->date('business_date');
                $table->string('timezone', 64);

                // GLOBAL | WALLETS
                $table->string('scope', 20);
                $table->json('scope_wallet_ids')->nullable();

                // EN_PROCESO | CUADRADA | CON_DIFERENCIAS | FALLIDA
                $table->string('status', 20);

                $table->dateTime('started_at');
                $table->dateTime('finished_at')->nullable();
                $table->string('executed_by', 255);

                $table->integer('checks_executed')->default(0);
                $table->integer('differences_count')->default(0);
                $table->json('summary')->nullable();
                $table->string('error_message', 2000)->nullable();

                $table->timestamps();

                $table->index('business_date');
                $table->index('status');
            }
        );
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')->dropIfExists('reconciliations');
    }
};
