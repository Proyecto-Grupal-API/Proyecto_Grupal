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
            'bonus_ledger_entries',
            function (Blueprint $table) {
                $table->id();

                $table->uuid('public_id')->unique();

                $table->uuid('bonus_id');

                $table->string(
                    'movement_type',
                    30
                );

                $table->bigInteger('amount_cents');

                $table->bigInteger(
                    'remaining_after_cents'
                );

                $table->string(
                    'reference_type',
                    100
                )->nullable();

                $table->string(
                    'reference_id',
                    255
                )->nullable();

                $table->string(
                    'actor_id',
                    255
                )->nullable();

                $table->string(
                    'reason',
                    500
                )->nullable();

                $table->timestamps();

                $table->foreign('bonus_id')
                    ->references('public_id')
                    ->on('bonuses');

                $table->index('bonus_id');
                $table->index('movement_type');

                $table->index([
                    'reference_type',
                    'reference_id',
                ]);

                $table->index('actor_id');
            }
        );
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')
            ->dropIfExists(
                'bonus_ledger_entries'
            );
    }
};