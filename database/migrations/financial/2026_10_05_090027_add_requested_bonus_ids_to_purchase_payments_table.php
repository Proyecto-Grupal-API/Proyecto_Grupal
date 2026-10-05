<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')
            ->table('purchase_payments', function (Blueprint $table) {
                $table->json('requested_bonus_ids')
                    ->nullable();
            });
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')
            ->table('purchase_payments', function (Blueprint $table) {
                $table->dropColumn('requested_bonus_ids');
            });
    }
};