<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection('sqlsrv')->create('wallet_hold_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('operation_type', 100)->unique();
            $table->integer('max_duration_seconds');
            $table->boolean('active')->default(true);
            $table->integer('version')->default(1);
            $table->string('created_by', 255);
            $table->string('updated_by', 255);
            $table->timestamps();
        });
        Schema::connection('sqlsrv')->create('wallet_hold_policy_changes', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->uuid('policy_id');
            $table->integer('version');
            $table->string('change_type', 20);
            $table->string('actor_id', 255);
            $table->text('reason');
            $table->string('correlation_id', 100);
            $table->json('before')->nullable();
            $table->json('after');
            $table->timestamps();
            $table->foreign('policy_id')->references('public_id')->on('wallet_hold_policies');
            $table->unique(['policy_id', 'version']);
        });
        Schema::connection('sqlsrv')->table('wallet_holds', function (Blueprint $table) {
            // Historical holds keep null provenance; their expiry is preserved.
            $table->uuid('policy_id')->nullable();
            $table->integer('policy_version')->nullable();
            $table->integer('max_duration_seconds')->nullable();
            $table->foreign('policy_id')->references('public_id')->on('wallet_hold_policies');
        });
    }

    public function down(): void
    {
        Schema::connection('sqlsrv')->table('wallet_holds', function (Blueprint $table) {
            $table->dropForeign(['policy_id']);
            $table->dropColumn(['policy_id', 'policy_version', 'max_duration_seconds']);
        });
        Schema::connection('sqlsrv')->dropIfExists('wallet_hold_policy_changes');
        Schema::connection('sqlsrv')->dropIfExists('wallet_hold_policies');
    }
};
