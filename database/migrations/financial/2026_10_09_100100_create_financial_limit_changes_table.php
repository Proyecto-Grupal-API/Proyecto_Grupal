<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2.10 Historial de cambios de políticas de límite: actor, motivo, fecha
 * y snapshot antes/después. Solo se inserta; nunca se actualiza.
 */
return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->create(
            'financial_limit_changes',
            function (Blueprint $table) {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->uuid('limit_id');

                // CREACION | MODIFICACION
                $table->string('change_type', 20);
                $table->string('actor_id', 255);
                $table->string('reason', 500)->nullable();
                $table->string('correlation_id', 100)->nullable();

                $table->json('before')->nullable();
                $table->json('after');

                $table->timestamps();

                $table->foreign('limit_id')
                    ->references('public_id')
                    ->on('financial_limits');

                $table->index('limit_id');
            }
        );
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')->dropIfExists('financial_limit_changes');
    }
};
