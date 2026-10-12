<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    protected $connection='sqlsrv';
    public function up():void {Schema::connection('sqlsrv')->create('legacy_cash_receipt_reviews',function(Blueprint $t){
        $t->id();$t->uuid('public_id')->unique();$t->uuid('transaction_id')->unique();$t->uuid('wallet_id');
        $t->string('classification',50);$t->string('reviewed_by',255);$t->string('reason',1000);
        $t->string('evidence_hash',64);$t->text('snapshot');$t->timestamp('reviewed_at');$t->timestamps();
        $t->index('wallet_id');
    });}
    // Rollback removes schema only if empty; reviewed evidence must not disappear silently.
    public function down():void {
        if(\Illuminate\Support\Facades\DB::connection('sqlsrv')->table('legacy_cash_receipt_reviews')->exists())
            throw new \LogicException('Hay revisiones históricas: conserva la tabla y su auditoría.');
        Schema::connection('sqlsrv')->dropIfExists('legacy_cash_receipt_reviews');
    }
};
