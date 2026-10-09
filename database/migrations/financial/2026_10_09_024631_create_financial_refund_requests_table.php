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
            'financial_refund_requests',
            function (Blueprint $table) {

                $table->id();

                $table->uuid('public_id')->unique();

                // Transaccion REFUND_REQUEST existente
                $table->uuid('request_transaction_id')->unique();

                // Pago original
                $table->uuid('original_transaction_id');

                // Wallet relacionada
                $table->uuid('wallet_id');

                // Importe solicitado en centavos
                $table->bigInteger('amount_cents');

                // Estado de autorizacion
                $table->string('status', 30)
                    ->default('PENDIENTE');

                // Identificadores externos del Equipo 1
                $table->string('requested_by', 255);

                $table->string('reviewed_by', 255)
                    ->nullable();

                // Motivos y observaciones
                $table->text('reason')->nullable();

                $table->text('review_reason')->nullable();

                // Fechas del proceso
                $table->timestamp('reviewed_at')->nullable();

                $table->timestamp('completed_at')->nullable();

                // Transaccion de devolucion ejecutada
                $table->uuid('financial_transaction_id')
                    ->nullable();

                $table->timestamps();

                // Relaciones con el sistema financiero
                $table->foreign('request_transaction_id')
                    ->references('public_id')
                    ->on('financial_transactions');

                $table->foreign('original_transaction_id')
                    ->references('public_id')
                    ->on('financial_transactions');

                $table->foreign('wallet_id')
                    ->references('public_id')
                    ->on('wallets');

                $table->foreign('financial_transaction_id')
                    ->references('public_id')
                    ->on('financial_transactions');

                // Indices para consultas
                $table->index('original_transaction_id');

                $table->index('wallet_id');

                $table->index('status');

                $table->index('requested_by');
            }
        );
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')
            ->dropIfExists('financial_refund_requests');
    }
};