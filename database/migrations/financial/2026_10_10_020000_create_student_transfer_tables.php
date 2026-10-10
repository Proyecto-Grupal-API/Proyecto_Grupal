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
        Schema::connection('sqlsrv')->create('student_transfer_policies', function (Blueprint $t) {
            $t->id(); $t->string('policy_key', 40)->unique(); $t->boolean('enabled');
            foreach (['minimum_cents', 'maximum_cents', 'daily_cents', 'monthly_cents'] as $f) { $t->bigInteger($f); }
            $t->string('business_timezone', 100); $t->integer('version'); $t->string('updated_by', 255); $t->timestamps();
        });
        Schema::connection('sqlsrv')->create('student_transfer_policy_changes', function (Blueprint $t) {
            $t->id(); $t->uuid('public_id')->unique(); $t->string('policy_key', 40);
            $t->integer('version'); $t->string('actor_id', 255); $t->text('reason');
            $t->text('before_data')->nullable(); $t->text('after_data'); $t->timestamps();
            $t->unique(['policy_key', 'version']);
            $t->foreign('policy_key')->references('policy_key')->on('student_transfer_policies');
        });
        Schema::connection('sqlsrv')->create('student_transfers', function (Blueprint $t) {
            $t->id(); $t->uuid('public_id')->unique(); $t->string('idempotency_key', 255)->unique();
            $t->string('request_hash', 64); $t->uuid('source_wallet_id'); $t->uuid('destination_wallet_id');
            $t->string('sender_id', 255); $t->string('recipient_id', 255); $t->string('actor_id', 255);
            $t->string('kind', 30); $t->bigInteger('amount_cents'); $t->string('currency', 3);
            $t->string('concept', 255)->nullable(); $t->string('status', 30); $t->uuid('financial_transaction_id')->unique();
            $t->integer('policy_version'); $t->text('policy_snapshot'); $t->timestamp('completed_at'); $t->timestamps();
            $t->index(['sender_id', 'completed_at']); $t->index(['recipient_id', 'completed_at']);
            foreach (['source_wallet_id', 'destination_wallet_id'] as $f) { $t->foreign($f)->references('public_id')->on('wallets'); }
            $t->foreign('financial_transaction_id')->references('public_id')->on('financial_transactions');
        });
        // Approved DEVELOPMENT starting values, not an administrative role grant.
        $data = ['policy_key' => 'STUDENT_MXN', 'enabled' => true, 'minimum_cents' => 100,
            'maximum_cents' => 200000, 'daily_cents' => 500000, 'monthly_cents' => 2000000,
            'business_timezone' => 'America/Mexico_City', 'version' => 1, 'updated_by' => 'SYSTEM_MIGRATION'];
        DB::connection('sqlsrv')->transaction(function () use ($data) {
            DB::connection('sqlsrv')->table('student_transfer_policies')->insert($data + ['created_at' => now(), 'updated_at' => now()]);
            DB::connection('sqlsrv')->table('student_transfer_policy_changes')->insert([
                'public_id' => (string) Str::uuid(), 'policy_key' => 'STUDENT_MXN', 'version' => 1,
                'actor_id' => 'SYSTEM_MIGRATION', 'reason' => 'Valores iniciales de desarrollo del documento 2.6.',
                'before_data' => null, 'after_data' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }
    public function down(): void
    {
        foreach (['student_transfers', 'student_transfer_policy_changes', 'student_transfer_policies'] as $t) { Schema::connection('sqlsrv')->dropIfExists($t); }
    }
};
