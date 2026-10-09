<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlsrv';
    public function up(): void
    {
        Schema::connection('sqlsrv')->table('financial_withdrawal_recoveries', function (Blueprint $table) {
            $table->uuid('cash_movement_id')->nullable();
            $table->uuid('cash_shift_id')->nullable();
            $table->foreign('cash_movement_id')->references('public_id')->on('cash_movements');
            $table->foreign('cash_shift_id')->references('public_id')->on('cash_shifts');
        });
        DB::connection('sqlsrv')->statement('CREATE UNIQUE INDEX withdrawal_recoveries_cash_movement_unique ON financial_withdrawal_recoveries(cash_movement_id) WHERE cash_movement_id IS NOT NULL');
    }
    public function down(): void
    {
        DB::connection('sqlsrv')->statement('DROP INDEX withdrawal_recoveries_cash_movement_unique ON financial_withdrawal_recoveries');
        Schema::connection('sqlsrv')->table('financial_withdrawal_recoveries', function (Blueprint $table) {
            $table->dropForeign(['cash_movement_id']); $table->dropForeign(['cash_shift_id']);
            $table->dropColumn(['cash_movement_id', 'cash_shift_id']);
        });
    }
};
