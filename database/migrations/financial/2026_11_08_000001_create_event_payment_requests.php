<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->create('event_payment_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('source_reference', 64)->unique();
            $table->string('client_id', 100);
            $table->string('status', 20);
            $table->string('student_id', 24)->nullable();
            $table->string('event_id', 24)->nullable();
            $table->string('organization_id', 24)->nullable();
            $table->bigInteger('amount_cents')->nullable();
            $table->string('reservation_expires_at', 24)->nullable();
            $table->string('confirmed_at', 24)->nullable();
            $table->uuid('recipient_wallet_id')->nullable();
            $table->uuid('transaction_id')->nullable();
            $table->timestamps();

            $table->foreign('recipient_wallet_id')->references('public_id')->on('wallets');
            $table->foreign('transaction_id')->references('public_id')->on('financial_transactions');
        });
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')->dropIfExists('event_payment_requests');
    }
};
