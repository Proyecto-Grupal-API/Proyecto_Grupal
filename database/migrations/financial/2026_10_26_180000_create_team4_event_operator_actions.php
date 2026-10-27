<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->create('financial_team4_operator_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('action_id')->unique();
            $table->uuid('event_id');
            $table->string('action', 32);
            $table->string('request_hash', 64);
            $table->string('operator_label', 100);
            $table->string('reason', 1000);
            $table->string('body_hash', 64);
            $table->string('from_state', 16);
            $table->string('to_state', 16);
            $table->unsignedInteger('attempts');
            $table->string('previous_code', 64)->nullable();
            $table->string('application_environment', 32);
            $table->timestamp('created_at');
            $table->foreign('event_id')->references('event_id')->on('financial_team4_outbox');
            $table->index(['event_id', 'id']);
        });
    }

    public function down(): void
    {
        if (DB::connection('sqlsrv')->table('financial_team4_operator_actions')->exists()) {
            throw new RuntimeException('La tabla contiene historial operativo. No se elimina mediante rollback.');
        }
        Schema::connection('sqlsrv')->dropIfExists('financial_team4_operator_actions');
    }
};
