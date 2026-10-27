<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->create('marketplace_payment_requests', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->string('client_id', 100);
            $t->string('buyer_id', 24);
            $t->string('business_id', 100);
            $t->string('order_id', 100);
            $t->char('origin_key', 64)->unique();
            $t->char('key_hash', 64)->unique();
            $t->char('request_hash', 64);
            $t->text('payload');
            $t->bigInteger('amount_cents');
            $t->string('currency', 3);
            $t->string('status', 20)->default('PENDING');
            $t->timestamp('expires_at');
            $t->uuid('purchase_payment_id')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->text('audit_context')->nullable();
            $t->timestamps();
            $t->index(['buyer_id', 'created_at']);
            $t->index('purchase_payment_id');
            $t->foreign('purchase_payment_id')->references('public_id')->on('purchase_payments');
        });
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')->dropIfExists('marketplace_payment_requests');
    }
};
