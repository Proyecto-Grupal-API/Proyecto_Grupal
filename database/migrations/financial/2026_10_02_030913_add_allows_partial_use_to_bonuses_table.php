<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->table('bonuses', function (Blueprint $table) {
            $table->boolean('allows_partial_use')
                ->default(false);
        });
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')->table('bonuses', function (Blueprint $table) {
            $table->dropColumn('allows_partial_use');
        });
    }
};