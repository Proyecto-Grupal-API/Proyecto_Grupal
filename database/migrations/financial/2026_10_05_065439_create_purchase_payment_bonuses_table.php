<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('sqlsrv')
            ->create('purchase_payment_bonuses', function (Blueprint $table) {
                $table->id();

                $table->uuid('purchase_payment_id');
                $table->uuid('bonus_id');

                $table->unsignedBigInteger('amount_cents');

                $table->timestamps();

                $table->index('purchase_payment_id');
                $table->index('bonus_id');

                $table->unique([
                    'purchase_payment_id',
                    'bonus_id',
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sqlsrv')
            ->dropIfExists('purchase_payment_bonuses');
    }
};