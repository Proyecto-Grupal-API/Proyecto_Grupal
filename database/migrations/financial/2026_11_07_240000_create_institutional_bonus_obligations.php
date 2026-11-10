<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema, DB};

return new class extends Migration {
    protected $connection = 'sqlsrv';
    public function up(): void {
        Schema::connection('sqlsrv')->create('institutional_bonus_obligations', function (Blueprint $t) {
            $t->id(); $t->uuid('public_id')->unique();
            $t->uuid('purchase_payment_id')->unique(); $t->uuid('bonus_id'); $t->uuid('budget_id');
            $t->uuid('operation_id')->unique(); $t->bigInteger('amount_cents');
            $t->bigInteger('refunded_amount_cents')->default(0); $t->string('currency',3);
            $t->string('business_id',100); $t->string('payer_reference',150); $t->string('status',16)->default('OPEN'); $t->timestamps();
            $t->foreign('purchase_payment_id')->references('public_id')->on('purchase_payments');
            $t->foreign('bonus_id')->references('public_id')->on('bonuses');
            $t->foreign('budget_id')->references('public_id')->on('institutional_bonus_budgets');
            $t->foreign('operation_id')->references('public_id')->on('institutional_budget_operations');
            $t->index(['bonus_id','id']); $t->index(['budget_id','id']);
        });
        DB::connection('sqlsrv')->statement("ALTER TABLE institutional_bonus_obligations ADD CONSTRAINT ck_inst_obligation_amount CHECK (amount_cents > 0 AND refunded_amount_cents >= 0 AND refunded_amount_cents <= amount_cents AND status = 'OPEN')");
        Schema::connection('sqlsrv')->create('institutional_bonus_refunds', function (Blueprint $t) {
            $t->id(); $t->uuid('public_id')->unique(); $t->uuid('obligation_id');
            $t->uuid('refund_request_id')->unique(); $t->uuid('operation_id')->unique();
            $t->bigInteger('amount_cents'); $t->timestamp('created_at'); $t->index(['obligation_id','id']);
            $t->foreign('obligation_id')->references('public_id')->on('institutional_bonus_obligations');
            $t->foreign('refund_request_id')->references('public_id')->on('purchase_refund_requests');
            $t->foreign('operation_id')->references('public_id')->on('institutional_budget_operations');
        });
        DB::connection('sqlsrv')->statement('ALTER TABLE institutional_bonus_refunds ADD CONSTRAINT ck_inst_refund_amount CHECK (amount_cents > 0)');
    }
    public function down(): void {
        if (DB::connection('sqlsrv')->table('institutional_bonus_obligations')->exists()
            || DB::connection('sqlsrv')->table('institutional_bonus_refunds')->exists()) throw new RuntimeException('No retirar evidencia institucional.');
        Schema::connection('sqlsrv')->dropIfExists('institutional_bonus_refunds');
        Schema::connection('sqlsrv')->dropIfExists('institutional_bonus_obligations');
    }
};
