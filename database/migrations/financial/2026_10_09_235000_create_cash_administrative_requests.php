<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    protected $connection = 'sqlsrv';
    public function up(): void {
        Schema::connection('sqlsrv')->create('cash_administrative_requests', function (Blueprint $t) {
            $t->id(); $t->uuid('public_id')->unique(); $t->foreignId('cash_shift_id')->constrained('cash_shifts');
            $t->string('operation', 30); $t->bigInteger('amount_cents'); $t->string('currency', 3);
            $t->uuid('refund_request_id')->nullable(); $t->string('operator_id', 255); $t->string('student_id', 255)->nullable();
            $t->text('reason'); $t->string('request_key', 255)->unique(); $t->string('request_hash', 64);
            $t->boolean('supervisor_required'); $t->text('approval_policy_snapshot'); $t->string('status', 20);
            $t->string('supervisor_id', 255)->nullable(); $t->text('supervisor_reason')->nullable(); $t->timestamp('supervisor_reviewed_at')->nullable();
            $t->timestamp('expires_at'); $t->timestamp('cancelled_at')->nullable(); $t->timestamp('consumed_at')->nullable();
            $t->string('settlement_key', 255)->nullable(); $t->uuid('cash_movement_id')->nullable(); $t->timestamps();
            $t->foreign('refund_request_id')->references('public_id')->on('financial_refund_requests');
            $t->foreign('cash_movement_id')->references('public_id')->on('cash_movements');
            $t->index(['cash_shift_id', 'status']);
        });
        DB::connection('sqlsrv')->statement('CREATE UNIQUE INDEX cash_admin_settlement_key_unique ON cash_administrative_requests(settlement_key) WHERE settlement_key IS NOT NULL');
        DB::connection('sqlsrv')->statement('CREATE UNIQUE INDEX cash_admin_movement_unique ON cash_administrative_requests(cash_movement_id) WHERE cash_movement_id IS NOT NULL');
    }
    public function down(): void { Schema::connection('sqlsrv')->dropIfExists('cash_administrative_requests'); }
};
