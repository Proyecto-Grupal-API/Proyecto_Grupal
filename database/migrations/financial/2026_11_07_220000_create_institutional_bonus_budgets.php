<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    protected $connection = 'sqlsrv';
    public function up(): void
    {
        Schema::connection('sqlsrv')->create('institutional_bonus_budgets', function (Blueprint $t) {
            $t->id(); $t->uuid('public_id')->unique();
            $t->string('institution_reference', 100); $t->string('approval_reference', 150);
            $t->string('payer_reference', 150); $t->string('purpose', 500);
            $t->string('bonus_type', 30); $t->string('currency', 3); $t->string('status', 16);
            $t->bigInteger('approved_amount_cents'); $t->bigInteger('committed_amount_cents')->default(0);
            $t->bigInteger('consumed_amount_cents')->default(0); $t->bigInteger('version');
            $t->dateTime('valid_from'); $t->dateTime('expires_at'); $t->timestamps();
            $t->unique(['institution_reference', 'approval_reference'], 'institution_budget_approval_unique');
            $t->index(['bonus_type', 'currency', 'status']);
        });
        DB::connection('sqlsrv')->statement("ALTER TABLE institutional_bonus_budgets ADD CONSTRAINT institutional_budget_amounts CHECK (approved_amount_cents > 0 AND committed_amount_cents >= 0 AND consumed_amount_cents >= 0 AND consumed_amount_cents <= approved_amount_cents AND committed_amount_cents <= approved_amount_cents - consumed_amount_cents AND version > 0)");
        DB::connection('sqlsrv')->statement("ALTER TABLE institutional_bonus_budgets ADD CONSTRAINT institutional_budget_state CHECK (bonus_type = 'BECA' AND status IN ('ACTIVE','SUSPENDED') AND expires_at > valid_from)");
        Schema::connection('sqlsrv')->create('institutional_budget_operations', function (Blueprint $t) {
            $t->id(); $t->uuid('public_id')->unique(); $t->uuid('budget_id');
            $t->string('action', 30); $t->string('actor_id', 100); $t->string('reason', 1000);
            $t->string('idempotency_key', 200)->unique(); $t->string('request_hash', 64);
            $t->text('before_data')->nullable(); $t->text('after_data'); $t->timestamp('created_at');
            $t->foreign('budget_id')->references('public_id')->on('institutional_bonus_budgets');
            $t->index(['budget_id', 'id']);
        });
    }
    public function down(): void
    {
        if (DB::connection('sqlsrv')->table('institutional_budget_operations')->exists()
            || DB::connection('sqlsrv')->table('institutional_bonus_budgets')->exists()) {
            throw new RuntimeException('No se elimina presupuesto ni historial mediante rollback.');
        }
        Schema::connection('sqlsrv')->dropIfExists('institutional_budget_operations');
        Schema::connection('sqlsrv')->dropIfExists('institutional_bonus_budgets');
    }
};
