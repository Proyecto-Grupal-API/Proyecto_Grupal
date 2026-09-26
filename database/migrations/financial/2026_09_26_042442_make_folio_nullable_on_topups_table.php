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
            'topups',
            function (Blueprint $table) {
                $table->string('folio', 50)
                    ->nullable()
                    ->change();
            }
        );
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')->table(
            'topups',
            function (Blueprint $table) {
                $table->string('folio', 50)
                    ->nullable(false)
                    ->change();
            }
        );
    }
};