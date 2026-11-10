<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
    protected $connection = 'sqlsrv';
    public function up(): void {
        Schema::connection('sqlsrv')->table('institutional_bonus_obligations',function (Blueprint $t) {
            $t->bigInteger('settled_amount_cents')->default(0);
        });
        DB::connection('sqlsrv')->statement('ALTER TABLE institutional_bonus_obligations ADD CONSTRAINT ck_inst_obligation_settled CHECK (settled_amount_cents >= 0 AND settled_amount_cents <= amount_cents - refunded_amount_cents)');
        Schema::connection('sqlsrv')->create('institutional_bonus_settlements',function (Blueprint $t) {
            $t->id(); $t->uuid('public_id')->unique(); $t->uuid('obligation_id'); $t->uuid('budget_id'); $t->uuid('operation_id')->unique();
            $t->bigInteger('amount_cents'); $t->string('currency',3); $t->string('payment_provider',50);
            $t->string('payment_reference',150); $t->string('payment_key',64)->unique(); $t->string('recipient_reference',150);
            $t->string('paid_at_utc',27); $t->timestamp('created_at');
            $t->foreign('obligation_id')->references('public_id')->on('institutional_bonus_obligations');
            $t->foreign('budget_id')->references('public_id')->on('institutional_bonus_budgets');
            $t->foreign('operation_id')->references('public_id')->on('institutional_budget_operations');
            $t->index(['obligation_id','id']); $t->index(['budget_id','id']);
        });
        DB::connection('sqlsrv')->statement("ALTER TABLE institutional_bonus_settlements ADD CONSTRAINT ck_inst_settlement_amount CHECK (amount_cents > 0 AND currency = 'MXN')");
    }
    public function down(): void {
        if (DB::connection('sqlsrv')->table('institutional_bonus_settlements')->exists()
            || DB::connection('sqlsrv')->table('institutional_bonus_obligations')->where('settled_amount_cents','<>',0)->exists()) {
            throw new RuntimeException('No retirar liquidaciones existentes.');
        }
        Schema::connection('sqlsrv')->dropIfExists('institutional_bonus_settlements');
        DB::connection('sqlsrv')->statement('ALTER TABLE institutional_bonus_obligations DROP CONSTRAINT ck_inst_obligation_settled');
        Schema::connection('sqlsrv')->table('institutional_bonus_obligations',function (Blueprint $t) { $t->dropColumn('settled_amount_cents'); });
    }
};
