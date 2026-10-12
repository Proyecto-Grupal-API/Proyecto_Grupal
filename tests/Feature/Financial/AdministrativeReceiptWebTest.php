<?php
use App\Models\User;
use App\Domains\Financial\Enums\{WalletType,MovementType};
use App\Domains\Financial\Models\{PurchasePayment,Wallet};
use App\Domains\Financial\Services\{WalletService,LedgerService};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
require_once __DIR__.'/Support/ReceiptAuthorizationFixture.php';

beforeEach(function(){
    if(!app()->environment('testing')||config('database.connections.sqlsrv.database')!=='campus_digital_financial_testing'
       ||config('database.connections.mongodb.database')!=='campus_virtual_testing')throw new RuntimeException('Solo bases de pruebas.');
    DB::connection('sqlsrv')->beginTransaction();$this->ar29Users=[];$this->withoutVite();
    $this->ar29Access = new \Tests\ReceiptAuthorizationFixture('ar29');
    $this->ar29Directory = $this->ar29Access;
    app()->instance(\App\Domains\Financial\Contracts\ReceiptAdministrativeAuthorization::class,$this->ar29Access);
    app()->instance(\App\Domains\Financial\Contracts\LegacyCashReceiptAuthorization::class,$this->ar29Access);
});
afterEach(function(){
    while(DB::connection('sqlsrv')->transactionLevel()>0)DB::connection('sqlsrv')->rollBack();
    foreach($this->ar29Users??[]as$id)User::where('_id',$id)->delete();
});
function ar29User($test,bool $confirmed=true):array {
    $u=User::factory()->create();$u->forceFill(['email_verified_at'=>now(),'account_activation_pending'=>false,
        'two_factor_secret'=>$confirmed?encrypt('fixture-secret'):null,'two_factor_confirmed_at'=>$confirmed?now():null])->save();
    $test->ar29Users[]=(string)$u->getKey();$principal=(string)$u->getKey();
    $w=app(WalletService::class)->create('USER',(string)$u->getKey(),WalletType::USUARIO);
    return [$u,$w,$principal];
}
function ar29Payment(Wallet $w,string $business='ar29-business-a'):PurchasePayment {
    return PurchasePayment::create(['public_id'=>(string)Str::uuid(),'idempotency_key'=>'ar29-'.Str::uuid(),
        'wallet_id'=>$w->public_id,'bonus_id'=>(string)Str::uuid(),'business_id'=>$business,'currency'=>'MXN',
        'total_amount_cents'=>1000,'wallet_amount_cents'=>0,'bonus_amount_cents'=>1000,'status'=>'COMPLETADO']);
}
function ar29Lookup(string $source,string $id,Wallet $w,?array $scope=null,array $extra=[]):string {
    return '/finanzas/comprobantes-administracion/lookup?'.http_build_query(array_merge(['source'=>$source,'operation_id'=>$id,'wallet_id'=>$w->public_id],
        $scope?['scope_type'=>$scope['type'],'scope_id'=>$scope['id']]:[],$extra));
}
function ar29Bootstrap($test,string $principal):void {$test->ar29Access->allowGlobal($principal);}

test('administrative receipts require session and confirmed second factor',function(){
    $this->getJson('/finanzas/comprobantes-administracion')->assertUnauthorized();
    [$u,$w,$principal]=ar29User($this,false);ar29Bootstrap($this,$principal);
    $t=app(LedgerService::class)->credit($w,1000,MovementType::RECARGA,'ar29-'.Str::uuid());
    $this->actingAs($u)->getJson(ar29Lookup('transaction',$t->public_id,$w))->assertForbidden();
});
test('trusted administrator can read a foreign receipt but only the chosen wallet participation',function(){
    [$admin,$aw,$principal]=ar29User($this);ar29Bootstrap($this,$principal);[$owner,$w]=ar29User($this);[$recipient,$rw]=ar29User($this);
    app(LedgerService::class)->credit($w,3000,MovementType::RECARGA,'ar29-'.Str::uuid());
    $t=app(LedgerService::class)->transfer($w,$rw,1000,'ar29-'.Str::uuid());
    $this->actingAs($admin)->getJson(ar29Lookup('transaction',$t->public_id,$w))->assertOk()
        ->assertJsonCount(1,'data.entries')->assertJsonPath('data.entries.0.amount_cents',-1000)->assertJsonMissingPath('data.entries.0.wallet_id');
    expect($w->fresh()->available_balance_cents)->toBe(2000)->and($rw->fresh()->available_balance_cents)->toBe(1000);
});
test('contextual read grant cannot consult other business or forge a context',function(){
    [$admin,$aw,$global]=ar29User($this);ar29Bootstrap($this,$global);[$operator,$ow,$principal]=ar29User($this);[$owner,$w]=ar29User($this);
    $scope=['type'=>'business','id'=>'ar29-business-a'];
    $this->ar29Access->allowScope($principal,['module.2.9.read'],$scope,false,null,'Lectura negocio');
    $a=ar29Payment($w);$b=ar29Payment($w,'ar29-business-b');
    $this->actingAs($operator)->getJson(ar29Lookup('purchase',$a->public_id,$w,$scope))->assertOk();
    $this->getJson(ar29Lookup('purchase',$b->public_id,$w,$scope))->assertNotFound();
    $this->getJson(ar29Lookup('purchase',$b->public_id,$w,['type'=>'business','id'=>'ar29-business-b']))->assertForbidden();
    $this->getJson(ar29Lookup('purchase',$a->public_id,$w))->assertForbidden();
});
test('revoked read grant stops access immediately and other actions do not replace read',function(){
    [$admin,$aw,$global]=ar29User($this);ar29Bootstrap($this,$global);[$operator,$ow,$principal]=ar29User($this);[$owner,$w]=ar29User($this);
    $scope=['type'=>'business','id'=>'ar29-business-a'];$p=ar29Payment($w);
    $this->ar29Access->allowScope($principal,['module.2.9.create'],$scope,false,null,'Crear no consultar');
    $url=ar29Lookup('purchase',$p->public_id,$w,$scope);$this->actingAs($operator)->getJson($url)->assertForbidden();
    $grant=$this->ar29Access->allowScope($principal,['module.2.9.read'],$scope,false,null,'Lectura');
    $this->getJson($url)->assertOk();$this->ar29Access->revoke($grant);$this->getJson($url)->assertForbidden();
});
test('unregistered contexts inactive accounts and forged actor selectors cannot authorize reads',function(){
    [$admin,$aw,$global]=ar29User($this);ar29Bootstrap($this,$global);[$owner,$w]=ar29User($this);$p=ar29Payment($w);
    $this->actingAs($admin)->getJson(ar29Lookup('purchase',$p->public_id,$w,['type'=>'business','id'=>'invented']))->assertForbidden();
    $this->getJson(ar29Lookup('purchase',$p->public_id,$w,null,['actor_id'=>(string)$owner->getKey()]))->assertUnprocessable();
    $this->ar29Directory->inactive[]=(string)$admin->getKey();
    $this->getJson(ar29Lookup('purchase',$p->public_id,$w,['type'=>'business','id'=>'ar29-business-a']))->assertForbidden();
});
test('pending disabled and invalid administrative providers fail closed',function(){
    [$admin,$aw,$global]=ar29User($this);ar29Bootstrap($this,$global);$p=ar29Payment($aw);
    $url=ar29Lookup('purchase',$p->public_id,$aw,['type'=>'business','id'=>'ar29-business-a']);
    $this->ar29Access->available=false;$this->actingAs($admin)->getJson($url)->assertStatus(503);
    $this->ar29Access->available=false;$this->getJson($url)->assertStatus(503);
    $this->ar29Access->available=false;$this->getJson($url)->assertStatus(503);
});
test('scheduled read grants do not activate early',function(){
    [$admin,$aw,$global]=ar29User($this);ar29Bootstrap($this,$global);[$operator,$ow,$principal]=ar29User($this);
    $scope=['type'=>'business','id'=>'ar29-business-a'];$p=ar29Payment($ow);
    $this->ar29Access->allowScope($principal,['module.2.9.read'],$scope,false,time()+7200,'Lectura futura',time()+3600);
    $this->actingAs($operator)->getJson(ar29Lookup('purchase',$p->public_id,$ow,$scope))->assertForbidden();
});
test('association receipt context is derived from its cash register and cannot be queried as unscoped',function(){
    [$admin,$aw,$global]=ar29User($this);ar29Bootstrap($this,$global);[$operator,$ow,$principal]=ar29User($this);[$owner,$w]=ar29User($this);
    $scope=['type'=>'association','id'=>'ar29-association'];
    $this->ar29Access->allowScope($principal,['module.2.9.read'],$scope,false,null,'Lectura de caja');
    $t=app(LedgerService::class)->credit($w,1000,MovementType::RECARGA,'ar29-'.Str::uuid(),'TEST',(string)Str::uuid());
    $cash=app(\App\Domains\Financial\Services\CashShiftService::class);
    $register=$cash->createRegister('ar29-association','Caja contexto','MXN',(string)$admin->getKey());
    $shift=$cash->open($register->public_id,(string)$admin->getKey(),0,'ar29-'.Str::uuid());
    \App\Domains\Financial\Models\CashMovement::create(['public_id'=>(string)Str::uuid(),'cash_shift_id'=>$shift->id,
        'type'=>\App\Domains\Financial\Enums\CashMovementType::TOPUP,'amount_cents'=>1000,'reference_type'=>$t->reference_type,
        'reference_id'=>$t->reference_id,'idempotency_key'=>$t->idempotency_key,'wallet_id'=>$w->public_id,
        'actor_id'=>(string)$admin->getKey(),'reason'=>'Fixture','request_hash'=>hash('sha256','fixture')]);
    $this->actingAs($operator)->getJson(ar29Lookup('transaction',$t->public_id,$w,$scope))->assertOk();
    $this->getJson(ar29Lookup('transaction',$t->public_id,$w))->assertForbidden();
    $this->actingAs($admin)->getJson(ar29Lookup('transaction',$t->public_id,$w))->assertNotFound();
});
