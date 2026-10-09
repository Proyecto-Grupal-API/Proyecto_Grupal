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
            'financial_withdrawal_recoveries',
            function (Blueprint $table) {
                $table->id();
                $table->uuid('public_id')->unique();

                // Una confirmación por solicitud de devolución.
                $table->uuid('refund_request_id')->unique();

                // Retiro cuyo dinero fue recuperado.
                $table->uuid('withdrawal_id');

                $table->bigInteger('amount_cents');
                $table->string('currency', 3);

                // Referencia única para impedir reutilizar el comprobante.
                $table->string('recovery_reference', 255)->unique();

                // Responsable que confirmó la recuperación.
                $table->string('confirmed_by', 255);
                $table->timestamp('confirmed_at');

                $table->text('notes')->nullable();
                $table->timestamps();

                $table->foreign('refund_request_id')
                    ->references('public_id')
                    ->on('financial_refund_requests');

                $table->foreign('withdrawal_id')
                    ->references('public_id')
                    ->on('withdrawals');

                $table->index('withdrawal_id');
            }
        );
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')->dropIfExists(
            'financial_withdrawal_recoveries'
        );
    }
};