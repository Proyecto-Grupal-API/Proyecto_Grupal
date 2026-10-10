<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    protected $connection = 'sqlsrv';
    public function up(): void
    {
        Schema::connection('sqlsrv')->table('student_transfers', fn (Blueprint $t) => $t->text('audit_context')->nullable());
        Schema::connection('sqlsrv')->table('student_transfer_confirmations', function (Blueprint $t) {
            $t->text('preparation_audit')->nullable(); $t->text('confirmation_audit')->nullable(); $t->text('cancellation_audit')->nullable();
        });
        Schema::connection('sqlsrv')->table('student_transfer_policy_changes', fn (Blueprint $t) => $t->text('audit_context')->nullable());
    }
    public function down(): void
    {
        Schema::connection('sqlsrv')->table('student_transfer_policy_changes', fn (Blueprint $t) => $t->dropColumn('audit_context'));
        Schema::connection('sqlsrv')->table('student_transfer_confirmations', fn (Blueprint $t) => $t->dropColumn(['preparation_audit', 'confirmation_audit', 'cancellation_audit']));
        Schema::connection('sqlsrv')->table('student_transfers', fn (Blueprint $t) => $t->dropColumn('audit_context'));
    }
};
