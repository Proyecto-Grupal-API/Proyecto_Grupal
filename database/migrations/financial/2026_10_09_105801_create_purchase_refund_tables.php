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
            'purchase_refund_requests',
            function (Blueprint $table) {
                $table->id();
                $table->uuid('public_id')->unique();

                // Compra original, incluso si se pagó solo con bonos.
                $table->uuid('purchase_payment_id');

                // Operación administrativa de solicitud.
                $table->uuid('request_transaction_id')->unique();

                $table->uuid('wallet_id');
                $table->string('currency', 3);

                // Total solicitado y reparto entre los dos medios.
                $table->bigInteger('total_amount_cents');
                $table->bigInteger('wallet_amount_cents')->default(0);
                $table->bigInteger('bonus_amount_cents')->default(0);

                $table->string('status', 30)->default('PENDIENTE');

                $table->string('requested_by', 255);
                $table->string('reviewed_by', 255)->nullable();

                $table->text('reason')->nullable();
                $table->text('review_reason')->nullable();

                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('completed_at')->nullable();

                // Operación que ejecutó la devolución.
                $table->uuid('financial_transaction_id')->nullable();

                $table->timestamps();

                $table->foreign('purchase_payment_id')
                    ->references('public_id')
                    ->on('purchase_payments');

                $table->foreign('request_transaction_id')
                    ->references('public_id')
                    ->on('financial_transactions');

                $table->foreign('wallet_id')
                    ->references('public_id')
                    ->on('wallets');

                $table->foreign('financial_transaction_id')
                    ->references('public_id')
                    ->on('financial_transactions');

                $table->index('purchase_payment_id');
                $table->index('wallet_id');
                $table->index('status');
            }
        );

        Schema::connection('sqlsrv')->create(
            'purchase_refund_bonuses',
            function (Blueprint $table) {
                $table->id();

                $table->uuid('refund_request_id');
                $table->uuid('bonus_id');

                // Importe que esta solicitud devolverá a este bono.
                $table->bigInteger('amount_cents');

                $table->timestamps();

                $table->foreign('refund_request_id')
                    ->references('public_id')
                    ->on('purchase_refund_requests');

                $table->foreign('bonus_id')
                    ->references('public_id')
                    ->on('bonuses');

                // Un bono solo aparece una vez dentro de cada solicitud.
                $table->unique(
                    ['refund_request_id', 'bonus_id'],
                    'purchase_refund_bonus_pair_unique'
                );

                $table->index('bonus_id');
            }
        );
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')->dropIfExists(
            'purchase_refund_bonuses'
        );

        Schema::connection('sqlsrv')->dropIfExists(
            'purchase_refund_requests'
        );
    }
};