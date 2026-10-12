<?php
use App\Models\User;
use App\Domains\Financial\Enums\{WalletType,MovementType};
use App\Domains\Financial\Models\{PurchasePayment,Wallet,TopUp,LegacyCashReceiptReview,LedgerEntry,FinancialTransaction};
use App\Domains\Financial\Services\{WalletService,LedgerService};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
require_once __DIR__.'/Support/ReceiptAuthorizationFixture.php';

beforeEach(function(){
    if(!app()->environment('testing')||config('database.connections.sqlsrv.database')!=='campus_digital_financial_testing'
       ||config('database.connections.mongodb.database')!=='campus_virtual_testing')throw new RuntimeException('Solo bases de pruebas.');
    DB::connection('sqlsrv')->beginTransaction();$this->lr29Users=[];$this->withoutVite();$this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
    $this->lr29Access = new \Tests\ReceiptAuthorizationFixture('lr29');
    $this->lr29Directory = $this->lr29Access;
    app()->instance(\App\Domains\Financial\Contracts\ReceiptAdministrativeAuthorization::class,$this->lr29Access);
    app()->instance(\App\Domains\Financial\Contracts\LegacyCashReceiptAuthorization::class,$this->lr29Access);
});
afterEach(function(){
    while(DB::connection('sqlsrv')->transactionLevel()>0)DB::connection('sqlsrv')->rollBack();
    foreach($this->lr29Users??[]as$id)User::where('_id',$id)->delete();
});
function lr29User($test,bool $confirmed=true):array {
    $u=User::factory()->create();$u->forceFill(['email_verified_at'=>now(),'account_activation_pending'=>false,
        'two_factor_secret'=>$confirmed?encrypt('fixture-secret'):null,'two_factor_confirmed_at'=>$confirmed?now():null])->save();
    $test->lr29Users[]=(string)$u->getKey();$principal=(string)$u->getKey();
    $w=app(WalletService::class)->create('USER',(string)$u->getKey(),WalletType::USUARIO);
    return [$u,$w,$principal];
}
function lr29Payment(Wallet $w,string $business='lr29-business-a'):PurchasePayment {
    return PurchasePayment::create(['public_id'=>(string)Str::uuid(),'idempotency_key'=>'lr29-'.Str::uuid(),
        'wallet_id'=>$w->public_id,'bonus_id'=>(string)Str::uuid(),'business_id'=>$business,'currency'=>'MXN',
        'total_amount_cents'=>1000,'wallet_amount_cents'=>0,'bonus_amount_cents'=>1000,'status'=>'COMPLETADO']);
}
function lr29Lookup(string $source,string $id,Wallet $w,?array $scope=null,array $extra=[]):string {
    return '/finanzas/comprobantes-administracion/lookup?'.http_build_query(array_merge(['source'=>$source,'operation_id'=>$id,'wallet_id'=>$w->public_id],
        $scope?['scope_type'=>$scope['type'],'scope_id'=>$scope['id']]:[],$extra));
}
function lr29Bootstrap($test,string $principal):void {$test->lr29Access->allowGlobal($principal);}

function lr29Cash($test,Wallet $w):array {
    $r=TopUp::create(['public_id'=>(string)Str::uuid(),'wallet_id'=>$w->public_id,'amount_cents'=>1000,
        'currency'=>'MXN','method'=>\App\Domains\Financial\Enums\TopUpMethod::EFECTIVO,
        'status'=>\App\Domains\Financial\Enums\TopUpStatus::COMPLETADA,'agent_id'=>'demo-fixture','cash_shift_id'=>null,'folio'=>null]);
    $t=app(LedgerService::class)->credit($w,1000,MovementType::RECARGA,'lr29-'.Str::uuid(),'TOPUP',$r->public_id);
    config(['financial_receipts.legacy_candidates'=>[strtolower($t->public_id)=>['wallet_id'=>strtolower($w->public_id),'reference_id'=>strtolower($r->public_id),'amount_cents'=>1000]]]);
    return [$r,$t];
}
function lr29Review($test,User $actor,Wallet $w,FinancialTransaction $t,string $reason='Revisión de demostración') {
    return $test->actingAs($actor)->postJson('/finanzas/comprobantes-administracion/legacy-reviews',[
        'transaction_id'=>$t->public_id,'wallet_id'=>$w->public_id,'reason'=>$reason]);
}
test('legacy review records actor reason evidence and retries without changing money or inventing a cash folio',function(){
    [$admin,$aw,$global]=lr29User($this);lr29Bootstrap($this,$global);[$owner,$w]=lr29User($this);[$r,$t]=lr29Cash($this,$w);
    $before=[FinancialTransaction::count(),LedgerEntry::count(),$w->fresh()->available_balance_cents];
    $id=lr29Review($this,$admin,$w,$t)->assertOk()->json('data.id');
    lr29Review($this,$admin,$w,$t)->assertOk()->assertJsonPath('data.id',$id);
    $record=LegacyCashReceiptReview::where('transaction_id',$t->public_id)->firstOrFail();
    expect($record->reviewed_by)->toBe((string)$admin->getKey())->and($record->reason)->toBe('Revisión de demostración')
        ->and(LegacyCashReceiptReview::where('transaction_id',$t->public_id)->count())->toBe(1)
        ->and([FinancialTransaction::count(),LedgerEntry::count(),$w->fresh()->available_balance_cents])->toBe($before)
        ->and($r->fresh()->folio)->toBeNull()->and($r->fresh()->cash_shift_id)->toBeNull();
    lr29Review($this,$admin,$w,$t,'Otro motivo')->assertConflict();
    $this->actingAs($admin)->getJson(lr29Lookup('transaction',$t->public_id,$w))->assertOk()
        ->assertJsonPath('data.historical_notice.classification','DEVELOPMENT_DEMO_WITHOUT_CASH_RECEIPT')->assertJsonCount(0,'data.cash_receipts');
});
test('unreviewed missing cash operation stays blocked and noncandidates cannot be reviewed',function(){
    [$admin,$w,$global]=lr29User($this);lr29Bootstrap($this,$global);[$r,$t]=lr29Cash($this,$w);
    $this->actingAs($admin)->getJson(lr29Lookup('transaction',$t->public_id,$w))->assertConflict();
    config(['financial_receipts.legacy_candidates'=>[]]);lr29Review($this,$admin,$w,$t)->assertConflict();
    expect(LegacyCashReceiptReview::where('transaction_id',$t->public_id)->exists())->toBeFalse();
});
test('modular read permission cannot approve or access reviewed unscoped historical cash',function(){
    [$admin,$aw,$global]=lr29User($this);lr29Bootstrap($this,$global);[$operator,$w,$principal]=lr29User($this);[$r,$t]=lr29Cash($this,$w);
    $this->lr29Access->allowScope($principal,['module.2.9.read'],null,false,null,'Lectura sin contexto');
    lr29Review($this,$operator,$w,$t)->assertForbidden();lr29Review($this,$admin,$w,$t)->assertOk();
    $this->actingAs($operator)->getJson(lr29Lookup('transaction',$t->public_id,$w))->assertForbidden();
});
test('review detects ledger corruption and later evidence changes rather than repairing them',function(){
    [$admin,$w,$global]=lr29User($this);lr29Bootstrap($this,$global);[$r,$t]=lr29Cash($this,$w);
    $entry=LedgerEntry::where('transaction_id',$t->public_id)->firstOrFail();$entry->update(['amount_cents'=>999]);
    lr29Review($this,$admin,$w,$t)->assertConflict();$entry->update(['amount_cents'=>1000]);
    lr29Review($this,$admin,$w,$t)->assertOk();$entry->update(['balance_after_cents'=>123]);
    $this->actingAs($admin)->getJson(lr29Lookup('transaction',$t->public_id,$w))->assertConflict();
});
test('legacy review needs confirmed second factor and rejects forged reviewer fields',function(){
    [$admin,$w,$global]=lr29User($this,false);lr29Bootstrap($this,$global);[$r,$t]=lr29Cash($this,$w);
    lr29Review($this,$admin,$w,$t)->assertForbidden();
    $admin->forceFill(['two_factor_secret'=>encrypt('fixture-secret'),'two_factor_confirmed_at'=>now()])->save();
    $this->actingAs($admin)->postJson('/finanzas/comprobantes-administracion/legacy-reviews',[
        'transaction_id'=>$t->public_id,'wallet_id'=>$w->public_id,'reason'=>'Review','reviewed_by'=>'forged'])->assertUnprocessable();
});
test('review persistence failure rolls back classification without affecting original records',function(){
    [$admin,$w,$global]=lr29User($this);lr29Bootstrap($this,$global);[$r,$t]=lr29Cash($this,$w);
    $before=$w->fresh()->available_balance_cents;
    $booted=new LegacyCashReceiptReview();
    $dispatcher=LegacyCashReceiptReview::getEventDispatcher();
    LegacyCashReceiptReview::setEventDispatcher(clone $dispatcher);
    LegacyCashReceiptReview::creating(function(){throw new RuntimeException('fixture persistence failure');});
    try{expect(fn()=>app(\App\Domains\Financial\Services\LegacyCashReceiptReviewService::class)->classify((string)$admin->getKey(),$t->public_id,$w->public_id,'Review'))->toThrow(RuntimeException::class);}
    finally{LegacyCashReceiptReview::setEventDispatcher($dispatcher);}
    expect(LegacyCashReceiptReview::where('transaction_id',$t->public_id)->exists())->toBeFalse()->and($w->fresh()->available_balance_cents)->toBe($before);
});
