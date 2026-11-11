<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->create('financial_community_notices', function (Blueprint $t) {
            $t->id();
            $t->string('tipo', 40);
            $t->uuid('operation_id');
            $t->string('idempotency_key', 128)->unique();
            $t->text('payload');
            $t->string('status', 16)->default('PENDING');
            $t->unsignedInteger('attempts')->default(0);
            $t->dateTime('next_attempt_at');
            $t->uuid('lease_token')->nullable();
            $t->dateTime('locked_until')->nullable();
            $t->string('last_error', 80)->nullable();
            $t->string('notification_id', 128)->nullable();
            $t->dateTime('delivered_at')->nullable();
            $t->timestamps();
            $t->unique(['tipo', 'operation_id'], 'financial_community_notice_operation_unique');
            $t->index(['status', 'next_attempt_at'], 'financial_community_notice_due_index');
        });
        // Fixed-size checkpoints: one row per type, no changes to financial fact tables.
        Schema::connection('sqlsrv')->create('financial_community_notice_scans', function (Blueprint $t) {
            $t->string('tipo', 40)->primary();
            $t->string('since', 32);
            $t->unsignedBigInteger('after_id')->default(0);
            $t->unsignedBigInteger('through_id')->default(0);
        });
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')->dropIfExists('financial_community_notice_scans');
        Schema::connection('sqlsrv')->dropIfExists('financial_community_notices');
    }
};
