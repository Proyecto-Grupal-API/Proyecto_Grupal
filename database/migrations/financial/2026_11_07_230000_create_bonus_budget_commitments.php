<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    protected $connection = 'sqlsrv';
    public function up(): void
    {
        Schema::connection('sqlsrv')->table('bonuses', function (Blueprint $t) {
            $t->uuid('institutional_budget_id')->nullable();
            $t->foreign('institutional_budget_id')->references('public_id')->on('institutional_bonus_budgets');
        });
        Schema::connection('sqlsrv')->create('bonus_budget_commitments', function (Blueprint $t) {
            $t->id(); $t->uuid('public_id')->unique(); $t->uuid('budget_id');
            $t->string('award_reference', 150)->unique(); $t->uuid('bonus_id')->unique(); $t->uuid('issue_operation_id')->unique();
            $t->bigInteger('amount_cents'); $t->string('currency', 3); $t->string('status', 20);
            $t->timestamps();
            $t->foreign('budget_id')->references('public_id')->on('institutional_bonus_budgets');
            $t->foreign('bonus_id')->references('public_id')->on('bonuses');
            $t->foreign('issue_operation_id')->references('public_id')->on('institutional_budget_operations');
            $t->index(['budget_id', 'id']);
        });
        DB::connection('sqlsrv')->statement("ALTER TABLE bonus_budget_commitments ADD CONSTRAINT bonus_budget_commitment_amount CHECK (amount_cents > 0 AND status = 'COMMITTED')");
    }
    public function down(): void
    {
        if (DB::connection('sqlsrv')->table('bonus_budget_commitments')->exists()
            || DB::connection('sqlsrv')->table('bonuses')->whereNotNull('institutional_budget_id')->exists()) {
            throw new RuntimeException('No se elimina evidencia de compromisos presupuestarios.');
        }
        Schema::connection('sqlsrv')->dropIfExists('bonus_budget_commitments');
        Schema::connection('sqlsrv')->table('bonuses', function (Blueprint $t) {
            $t->dropForeign(['institutional_budget_id']); $t->dropColumn('institutional_budget_id');
        });
    }
};
