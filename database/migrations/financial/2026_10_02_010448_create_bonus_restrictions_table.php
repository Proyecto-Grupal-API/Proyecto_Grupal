<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->create(
            'bonus_restrictions',
            function (Blueprint $table) {
                $table->id();

                $table->uuid('public_id')->unique();

                $table->uuid('bonus_id');

                $table->string(
                    'restriction_type',
                    50
                );

                $table->string(
                    'target_id',
                    255
                );

                $table->timestamps();

                $table->foreign('bonus_id')
                    ->references('public_id')
                    ->on('bonuses');

                $table->unique([
                    'bonus_id',
                    'restriction_type',
                    'target_id',
                ]);

                $table->index([
                    'restriction_type',
                    'target_id',
                ]);
            }
        );
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')
            ->dropIfExists('bonus_restrictions');
    }
};