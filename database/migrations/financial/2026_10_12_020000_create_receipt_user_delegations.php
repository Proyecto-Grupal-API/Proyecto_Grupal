<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    protected $connection='sqlsrv';
    public function up():void {Schema::connection('sqlsrv')->create('receipt_user_delegations',function(Blueprint $t){
        $t->id();$t->uuid('public_id')->unique();$t->string('proof_hash',64)->unique();$t->string('user_id',24);
        $t->string('client_id',255);$t->string('client_name',255);$t->string('identity_session_id',24);$t->text('native_session_id');
        $t->string('action',32);$t->string('request_path',255);$t->text('request_query');$t->string('request_digest',64);
        $t->timestamp('expires_at');$t->timestamp('revoked_at')->nullable();$t->timestamps();
        $t->index(['user_id','created_at']);$t->index('expires_at');
    });}
    public function down():void {Schema::connection('sqlsrv')->dropIfExists('receipt_user_delegations');}
};
