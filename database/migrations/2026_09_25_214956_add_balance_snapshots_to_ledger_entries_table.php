<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->table(
            'ledger_entries',
            function (Blueprint $table) {
                $table->bigInteger(
                    'available_balance_after_cents'
                )->nullable();

                $table->bigInteger(
                    'held_balance_after_cents'
                )->nullable();
            }
        );
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')->table(
            'ledger_entries',
            function (Blueprint $table) {
                $table->dropColumn([
                    'available_balance_after_cents',
                    'held_balance_after_cents',
                ]);
            }
        );
    }
};