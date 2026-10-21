<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    protected $connection = 'sqlsrv';
    public function up(): void {
        Schema::connection('sqlsrv')->create('purchase_marketplace_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('purchase_payment_id')->unique();
            $table->string('payment_intent_id',100)->unique();
            $table->string('order_id',100);
            $table->string('business_id',100);
            $table->char('order_key',64)->unique();
            $table->char('context_hash',64);
            $table->text('items');
            $table->timestamps();
            $table->foreign('purchase_payment_id')->references('public_id')->on('purchase_payments');
        });
    }
    public function down(): void { Schema::connection('sqlsrv')->dropIfExists('purchase_marketplace_links'); }
};
