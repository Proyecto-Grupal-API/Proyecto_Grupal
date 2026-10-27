<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2.10 Locks de procesos financieros (p. ej. una conciliación por fecha
 * de negocio). Vive en la misma base que los datos financieros para que
 * el lock sea válido entre servidores, sin depender del driver de cache.
 */
return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->create(
            'financial_job_locks',
            function (Blueprint $table) {
                $table->id();
                $table->string('lock_key', 191)->unique();
                $table->string('owner_token', 64);
                $table->dateTime('acquired_at');
                $table->dateTime('expires_at');
            }
        );
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')->dropIfExists('financial_job_locks');
    }
};
