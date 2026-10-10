<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
return new class extends Migration {
    protected $connection = 'sqlsrv';
    public function up(): void
    {
        Schema::connection('sqlsrv')->table('student_transfer_policies', function (Blueprint $t) { $t->integer('confirmation_seconds')->default(300); });
        Schema::connection('sqlsrv')->create('student_transfer_confirmations', function (Blueprint $t) {
            $t->id(); $t->uuid('public_id')->unique(); $t->string('request_key', 255)->unique(); $t->string('request_hash', 64);
            $t->string('sender_id', 255); $t->string('recipient_id', 255); $t->uuid('source_wallet_id'); $t->uuid('destination_wallet_id');
            $t->string('kind', 30); $t->bigInteger('amount_cents'); $t->string('concept', 255)->nullable(); $t->string('identity_method', 30);
            $t->text('recipient_snapshot'); $t->text('policy_snapshot'); $t->string('status', 30);
            $t->integer('duration_seconds'); $t->timestamp('expires_at'); $t->timestamp('confirmed_at')->nullable(); $t->timestamp('cancelled_at')->nullable();
            $t->string('requested_ip', 45)->nullable(); $t->string('confirmed_ip', 45)->nullable(); $t->string('correlation_id', 100)->nullable();
            $t->string('execution_key', 255)->nullable(); $t->uuid('student_transfer_id')->nullable(); $t->timestamps();
            $t->index(['sender_id', 'created_at']);
            foreach (['source_wallet_id', 'destination_wallet_id'] as $f) { $t->foreign($f)->references('public_id')->on('wallets'); }
            $t->foreign('student_transfer_id')->references('public_id')->on('student_transfers');
        });
        DB::connection('sqlsrv')->transaction(function () {
            $p = DB::connection('sqlsrv')->table('student_transfer_policies')->where('policy_key', 'STUDENT_MXN')->lockForUpdate()->first();
            if (!$p) { throw new RuntimeException('Falta la política inicial del 2.6.'); }
            $before = (array) $p; unset($before['id'], $before['created_at'], $before['updated_at']); unset($before['confirmation_seconds']);
            $after = $before; $after['confirmation_seconds'] = 300; $after['version'] = $p->version + 1; $after['updated_by'] = 'SYSTEM_MIGRATION';
            DB::connection('sqlsrv')->table('student_transfer_policies')->where('policy_key', 'STUDENT_MXN')->update(['version' => $after['version'], 'updated_by' => 'SYSTEM_MIGRATION', 'updated_at' => now()]);
            DB::connection('sqlsrv')->table('student_transfer_policy_changes')->insert(['public_id' => (string) Str::uuid(), 'policy_key' => 'STUDENT_MXN',
                'version' => $after['version'], 'actor_id' => 'SYSTEM_MIGRATION', 'reason' => 'Plazo inicial configurable de confirmación: 300 segundos.',
                'before_data' => json_encode($before, JSON_THROW_ON_ERROR), 'after_data' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        });
    }
    public function down(): void
    {
        Schema::connection('sqlsrv')->dropIfExists('student_transfer_confirmations');
        // Keep audit versions; removing this column does not erase historical snapshots.
        Schema::connection('sqlsrv')->table('student_transfer_policies', function (Blueprint $t) { $t->dropColumn('confirmation_seconds'); });
    }
};
