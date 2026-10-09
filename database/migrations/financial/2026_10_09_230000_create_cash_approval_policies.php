<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    protected $connection = 'sqlsrv';
    public function up(): void {
        Schema::connection('sqlsrv')->create('cash_approval_policies', function (Blueprint $t) {
            $t->id(); $t->string('association_id', 255); $t->string('operation', 20); $t->string('currency', 3);
            $t->boolean('enabled'); $t->bigInteger('threshold_cents'); $t->integer('version'); $t->timestamps();
            $t->unique(['association_id', 'operation', 'currency'], 'cash_approval_policy_subject_unique');
        });
        Schema::connection('sqlsrv')->create('cash_approval_policy_changes', function (Blueprint $t) {
            $t->id(); $t->foreignId('cash_approval_policy_id')->constrained('cash_approval_policies');
            $t->string('idempotency_key', 255)->unique(); $t->string('request_hash', 64);
            $t->string('actor_id', 255); $t->text('reason'); $t->text('before_snapshot')->nullable(); $t->text('after_snapshot'); $t->timestamp('created_at');
        });
        Schema::connection('sqlsrv')->table('cash_operation_confirmations', function (Blueprint $t) {
            $t->boolean('supervisor_required')->default(false); $t->text('approval_policy_snapshot')->nullable();
            $t->string('supervisor_status', 20)->nullable(); $t->string('supervisor_id', 255)->nullable();
            $t->text('supervisor_reason')->nullable(); $t->timestamp('supervisor_reviewed_at')->nullable();
        });
    }
    public function down(): void {
        Schema::connection('sqlsrv')->table('cash_operation_confirmations', function (Blueprint $t) {
            $t->dropColumn(['supervisor_required', 'approval_policy_snapshot', 'supervisor_status', 'supervisor_id', 'supervisor_reason', 'supervisor_reviewed_at']);
        });
        Schema::connection('sqlsrv')->dropIfExists('cash_approval_policy_changes');
        Schema::connection('sqlsrv')->dropIfExists('cash_approval_policies');
    }
};
