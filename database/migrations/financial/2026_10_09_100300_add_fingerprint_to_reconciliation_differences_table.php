<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2.10 Una diferencia detectada de nuevo (mismo día, comprobación,
 * entidad y valores) no se duplica: se registra como una observación más
 * de la diferencia existente. Las filas anteriores conservan NULL.
 */
return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->table(
            'reconciliation_differences',
            function (Blueprint $table) {
                $table->string('fingerprint', 64)->nullable();
                $table->date('business_date')->nullable();
                $table->uuid('last_seen_reconciliation_id')->nullable();
                $table->dateTime('last_seen_at')->nullable();
                $table->integer('occurrences')->default(1);
            }
        );

        $connection = DB::connection('sqlsrv');

        if ($connection->getDriverName() === 'sqlsrv') {
            $connection->statement(
                'CREATE UNIQUE INDEX reconciliation_differences_fingerprint_unique
                 ON reconciliation_differences (fingerprint)
                 WHERE fingerprint IS NOT NULL'
            );
        } else {
            Schema::connection('sqlsrv')->table(
                'reconciliation_differences',
                fn (Blueprint $t) => $t->unique('fingerprint', 'reconciliation_differences_fingerprint_unique')
            );
        }
    }

    public function down(): void
    {
        $connection = DB::connection('sqlsrv');

        if ($connection->getDriverName() === 'sqlsrv') {
            $connection->statement(
                'DROP INDEX reconciliation_differences_fingerprint_unique ON reconciliation_differences'
            );
        } else {
            Schema::connection('sqlsrv')->table(
                'reconciliation_differences',
                fn (Blueprint $t) => $t->dropUnique('reconciliation_differences_fingerprint_unique')
            );
        }

        Schema::connection('sqlsrv')->table(
            'reconciliation_differences',
            function (Blueprint $table) {
                $table->dropColumn([
                    'fingerprint',
                    'business_date',
                    'last_seen_reconciliation_id',
                    'last_seen_at',
                    'occurrences',
                ]);
            }
        );
    }
};
