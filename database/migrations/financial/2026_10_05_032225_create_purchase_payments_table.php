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
            'purchase_payments',
            function (Blueprint $table) {
                $table->id();

                $table->uuid('public_id')->unique();

                $table->string(
                    'idempotency_key',
                    255
                )->unique();

                $table->uuid('wallet_id');

                $table->uuid('bonus_id');

                $table->string(
                    'business_id',
                    255
                )->nullable();

                $table->string(
                    'category_id',
                    255
                )->nullable();

                $table->string('currency', 3);

                $table->unsignedBigInteger(
                    'total_amount_cents'
                );

                $table->unsignedBigInteger(
                    'bonus_amount_cents'
                );

                $table->unsignedBigInteger(
                    'wallet_amount_cents'
                );

                $table->string(
                    'status',
                    30
                );

                $table->timestamps();

                $table->index('wallet_id');
                $table->index('bonus_id');
                $table->index('status');
            }
        );
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')
            ->dropIfExists('purchase_payments');
    }
};