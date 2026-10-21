<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    protected $connection = 'sqlsrv';
    public function up(): void {
        Schema::connection('sqlsrv')->create('financial_team4_streams', function(Blueprint $t) {
            $t->id();$t->string('payment_intent_id',100)->unique();$t->string('order_id',100);$t->string('business_id',100);
            $t->unsignedBigInteger('last_version')->default(0);$t->timestamps();
        });
        Schema::connection('sqlsrv')->create('financial_team4_outbox', function(Blueprint $t) {
            $t->id();$t->uuid('event_id')->unique();$t->unsignedBigInteger('stream_id');$t->unsignedBigInteger('event_version');
            $t->string('source_key',64)->unique();$t->string('request_hash',64);$t->text('body');$t->string('body_hash',64);
            $t->string('state',16)->default('PENDING');$t->unsignedInteger('attempts')->default(0);$t->timestamp('available_at');
            $t->uuid('lease_token')->nullable();$t->timestamp('lease_until')->nullable();$t->timestamp('delivered_at')->nullable();
            $t->string('last_code',64)->nullable();$t->timestamps();
            $t->unique(['stream_id','event_version']);$t->index(['state','available_at']);
            $t->foreign('stream_id')->references('id')->on('financial_team4_streams');
        });
        Schema::connection('sqlsrv')->create('financial_team4_attempts', function(Blueprint $t) {
            $t->id();$t->uuid('event_id');$t->unsignedInteger('attempt_no');$t->uuid('lease_token');
            $t->timestamp('started_at');$t->timestamp('finished_at')->nullable();$t->unsignedSmallInteger('http_status')->nullable();
            $t->string('result_code',64)->nullable();$t->unique(['event_id','attempt_no']);
        });
    }
    public function down(): void {
        Schema::connection('sqlsrv')->dropIfExists('financial_team4_attempts');
        Schema::connection('sqlsrv')->dropIfExists('financial_team4_outbox');
        Schema::connection('sqlsrv')->dropIfExists('financial_team4_streams');
    }
};
