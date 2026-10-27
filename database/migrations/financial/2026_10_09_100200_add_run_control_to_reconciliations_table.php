<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2.10 Control de ejecución de conciliaciones: origen, intento,
 * idempotencia de la solicitud manual, estado de caja y conteo de
 * diferencias nuevas vs. ya conocidas.
 */
return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->table(
            'reconciliations',
            function (Blueprint $table) {
                // API | INTERFAZ | COMANDO | PROGRAMADA
                $table->string('trigger_source', 20)->nullable();
                $table->integer('attempt')->default(1);
                $table->string('idempotency_key', 255)->nullable();
                // NO_INTEGRADA | COMPARADA
                $table->string('cash_status', 20)->nullable();
                $table->integer('new_differences_count')->default(0);
                $table->integer('recurring_differences_count')->default(0);
            }
        );

        $this->createNullableUnique(
            'reconciliations',
            'idempotency_key',
            'reconciliations_idempotency_key_unique'
        );
    }

    public function down(): void
    {
        $this->dropIndex('reconciliations', 'reconciliations_idempotency_key_unique');

        Schema::connection('sqlsrv')->table(
            'reconciliations',
            function (Blueprint $table) {
                $table->dropColumn([
                    'trigger_source',
                    'attempt',
                    'idempotency_key',
                    'cash_status',
                    'new_differences_count',
                    'recurring_differences_count',
                ]);
            }
        );
    }

    /**
     * SQL Server no admite varios NULL en un índice único normal; se usa
     * un índice filtrado (mismo criterio que topups.folio).
     */
    private function createNullableUnique(string $table, string $column, string $name): void
    {
        $connection = DB::connection('sqlsrv');

        if ($connection->getDriverName() === 'sqlsrv') {
            $connection->statement(
                "CREATE UNIQUE INDEX {$name} ON {$table} ({$column}) WHERE {$column} IS NOT NULL"
            );

            return;
        }

        Schema::connection('sqlsrv')->table($table, fn (Blueprint $t) => $t->unique($column, $name));
    }

    private function dropIndex(string $table, string $name): void
    {
        $connection = DB::connection('sqlsrv');

        if ($connection->getDriverName() === 'sqlsrv') {
            $connection->statement("DROP INDEX {$name} ON {$table}");

            return;
        }

        Schema::connection('sqlsrv')->table($table, fn (Blueprint $t) => $t->dropUnique($name));
    }
};
