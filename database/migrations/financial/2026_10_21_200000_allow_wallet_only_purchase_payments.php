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
        Schema::connection('sqlsrv')->table('purchase_payments', function (Blueprint $table) {
            $table->uuid('bonus_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::connection('sqlsrv')->table('purchase_payments')->whereNull('bonus_id')->exists()) {
            throw new RuntimeException('Existen compras sin bono. No se puede retirar esta migracion sin preservar sus datos.');
        }
        Schema::connection('sqlsrv')->table('purchase_payments', function (Blueprint $table) {
            $table->uuid('bonus_id')->nullable(false)->change();
        });
    }
};
