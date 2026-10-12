<?php
use App\Models\User;
use App\Domains\Financial\Enums\{WalletType,MovementType};
use App\Domains\Financial\Models\{PurchasePayment,Wallet};
use App\Domains\Financial\Services\{WalletService,LedgerService};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use CampusDigital\Authorization\{Kernel,Store,Directory};

beforeEach(function(){
    if(!app()->environment('testing')||config('database.connections.sqlsrv.database')!=='campus_digital_financial_testing'
       ||config('database.connections.mongodb.database')!=='campus_virtual_testing')throw new RuntimeException('Solo bases de pruebas.');
    DB::connection('sqlsrv')->beginTransaction();$this->cc29Users=[];$this->withoutVite();$this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
    $store=new class implements Store {
        public array $rows=[];
        public function transaction(callable $work):mixed {$old=$this->rows;try{return $work();}catch(Throwable $e){$this->rows=$old;throw $e;}}
        public function get(string $key):?array{return $this->rows[$key]??null;}
        public function put(string $key,array $value):void{$this->rows[$key]=$value;}
    };
    $directory=new class implements Directory {
        public array $inactive=[];
        public function account(string $identity):?array{return ['identity'=>$identity,'active'=>!in_array($identity,$this->inactive,true),'verified'=>true];}
        public function scopeExists(string $type,string $id):bool{return ($type==='business'&&in_array($id,['cc29-business-a','cc29-business-b'],true))||($type==='association'&&$id==='cc29-association');}
    };
    $this->cc29Directory=$directory;
    config(['campus_authorization.enabled'=>true,'financial_cash_authorization.provider'=>'campus']);
    $this->cc29Kernel=new Kernel($store,$directory,config('campus_authorization.catalog'),true);
    app()->instance(Kernel::class,$this->cc29Kernel);
});
afterEach(function(){
    while(DB::connection('sqlsrv')->transactionLevel()>0)DB::connection('sqlsrv')->rollBack();
    foreach($this->cc29Users??[]as$id)User::where('_id',$id)->delete();
});
function cc29User($test,bool $confirmed=true):array {
    $u=User::factory()->create();$u->forceFill(['email_verified_at'=>now(),'account_activation_pending'=>false,
        'two_factor_secret'=>$confirmed?encrypt('fixture-secret'):null,'two_factor_confirmed_at'=>$confirmed?now():null])->save();
    $test->cc29Users[]=(string)$u->getKey();$principal=$test->cc29Kernel->register((string)$u->getKey());
    $w=app(WalletService::class)->create('USER',(string)$u->getKey(),WalletType::USUARIO);
    return [$u,$w,$principal];
}
function cc29Payment(Wallet $w,string $business='cc29-business-a'):PurchasePayment {
    return PurchasePayment::create(['public_id'=>(string)Str::uuid(),'idempotency_key'=>'cc29-'.Str::uuid(),
        'wallet_id'=>$w->public_id,'bonus_id'=>(string)Str::uuid(),'business_id'=>$business,'currency'=>'MXN',
        'total_amount_cents'=>1000,'wallet_amount_cents'=>0,'bonus_amount_cents'=>1000,'status'=>'COMPLETADO']);
}
function cc29Lookup(string $source,string $id,Wallet $w,?array $scope=null,array $extra=[]):string {
    return '/finanzas/comprobantes-administracion/lookup?'.http_build_query(array_merge(['source'=>$source,'operation_id'=>$id,'wallet_id'=>$w->public_id],
        $scope?['scope_type'=>$scope['type'],'scope_id'=>$scope['id']]:[],$extra));
}
function cc29Bootstrap($test,string $principal):void {$test->cc29Kernel->seat(null,2,$principal,'Administrador de pruebas',true);}

function cc29Provider(){return app(\App\Domains\Financial\Contracts\CashAuthorizationProvider::class);}
function cc29Actor(User $u):string{return 'user:'.$u->getKey();}

test('cash dedicated read action does not authorize operating adjusting closing or reviewing',function(){
    [$admin,$aw,$global]=cc29User($this);cc29Bootstrap($this,$global);[$operator,$ow,$principal]=cc29User($this);
    $scope=['type'=>'association','id'=>'cc29-association'];
    $this->cc29Kernel->grant($global,$principal,2,['financial.cash.read'],$scope,false,null,'Solo consulta');
    $provider=cc29Provider();
    foreach(\App\Domains\Financial\Adapters\CampusCashAuthorizationProvider::ACTIONS as $action)
        expect($provider->allows(cc29Actor($operator),'cc29-association',$action))->toBe($action==='read');
    expect($provider->allows(cc29Actor($operator),'another-association','read'))->toBeFalse();
});
test('cash global seat authorizes dedicated actions only in verified associations and never service identities',function(){
    [$admin,$w,$global]=cc29User($this);cc29Bootstrap($this,$global);$p=cc29Provider();
    foreach(\App\Domains\Financial\Adapters\CampusCashAuthorizationProvider::ACTIONS as $action)
        expect($p->allows(cc29Actor($admin),'cc29-association',$action))->toBeTrue();
    foreach(['service:'.$admin->getKey(),(string)$admin->getKey(),'user:invalid'] as $actor)
        expect($p->allows($actor,'cc29-association','read'))->toBeFalse();
    expect($p->allows(cc29Actor($admin),'invented','read'))->toBeFalse()
        ->and($p->allows(cc29Actor($admin),'cc29-association','unknown'))->toBeFalse();
});
test('cash mutations require confirmed second factor while a read remains available',function(){
    [$admin,$w,$global]=cc29User($this,false);cc29Bootstrap($this,$global);$p=cc29Provider();
    expect($p->allows(cc29Actor($admin),'cc29-association','read'))->toBeTrue()
        ->and($p->allows(cc29Actor($admin),'cc29-association','operate'))->toBeFalse()
        ->and($p->allows(cc29Actor($admin),'cc29-association','manage'))->toBeFalse();
    $admin->forceFill(['two_factor_secret'=>encrypt('fixture-secret'),'two_factor_confirmed_at'=>now()])->save();
    expect($p->allows(cc29Actor($admin),'cc29-association','operate'))->toBeTrue();
});
test('cash generic old grants do not expand and revocation is checked on every request',function(){
    [$admin,$aw,$global]=cc29User($this);cc29Bootstrap($this,$global);[$operator,$ow,$principal]=cc29User($this);
    $scope=['type'=>'association','id'=>'cc29-association'];
    $this->cc29Kernel->grant($global,$principal,2,['module.2.8.execute','module.2.8.read'],$scope,false,null,'Permiso anterior');
    $p=cc29Provider();expect($p->allows(cc29Actor($operator),'cc29-association','operate'))->toBeFalse();
    $grant=$this->cc29Kernel->grant($global,$principal,2,['financial.cash.operate'],$scope,false,null,'Operación explícita');
    expect($p->allows(cc29Actor($operator),'cc29-association','operate'))->toBeTrue();
    $this->cc29Kernel->revoke($global,$grant,'Revocar');expect($p->allows(cc29Actor($operator),'cc29-association','operate'))->toBeFalse();
});
test('cash pending disabled future or inactive authorization cannot permit operations',function(){
    [$admin,$aw,$global]=cc29User($this);cc29Bootstrap($this,$global);[$operator,$ow,$principal]=cc29User($this);
    $this->cc29Kernel->grant($global,$principal,2,['financial.cash.operate'],['type'=>'association','id'=>'cc29-association'],false,time()+7200,'Inicio futuro',time()+3600);
    expect(cc29Provider()->allows(cc29Actor($operator),'cc29-association','operate'))->toBeFalse();
    $this->cc29Directory->inactive[]=(string)$admin->getKey();expect(cc29Provider()->allows(cc29Actor($admin),'cc29-association','read'))->toBeFalse();
    config(['financial_cash_authorization.provider'=>'pending']);expect(cc29Provider()->allows(cc29Actor($admin),'cc29-association','read'))->toBeFalse();
    config(['financial_cash_authorization.provider'=>'campus','campus_authorization.enabled'=>false]);expect(cc29Provider()->allows(cc29Actor($operator),'cc29-association','read'))->toBeFalse();
});
test('cash web context derives session actor and exposes independent permissions',function(){
    [$admin,$aw,$global]=cc29User($this);cc29Bootstrap($this,$global);[$operator,$ow,$principal]=cc29User($this);
    $this->cc29Kernel->grant($global,$principal,2,['financial.cash.read'],['type'=>'association','id'=>'cc29-association'],false,null,'Consulta');
    $this->actingAs($operator)->getJson('/finanzas/caja/context?association_id=cc29-association&actor_id='.urlencode(cc29Actor($admin)))
        ->assertOk()->assertJsonPath('data.permissions.read',true)->assertJsonPath('data.permissions.operate',false)
        ->assertJsonPath('data.permissions.close',false)->assertJsonPath('data.permissions.approval_review',false);
});
test('cash development context registration is explicit idempotent and does not assign roles',function(){
    [$admin,$aw,$global]=cc29User($this);cc29Bootstrap($this,$global);$before=$this->cc29Kernel->snapshot();
    $args=['--identity'=>(string)$admin->getKey(),'--reason'=>'Contexto sintético de pruebas','--confirm'=>true];
    $this->artisan('financial:cash-dev-context',$args)->assertSuccessful();$this->artisan('financial:cash-dev-context',$args)->assertSuccessful();
    $rows=DB::connection('mongodb')->table('campus_authorization_contexts')->where('reference','dev-financial-cash')->get();
    expect($rows)->toHaveCount(1)->and($this->cc29Kernel->snapshot())->toBe($before);
    $env=app()->environment();app()->instance('env','production');
    try{$this->artisan('financial:cash-dev-context',$args)->assertFailed();}finally{app()->instance('env',$env);}
});
test('development associations are rejected outside development even when a registry row exists',function(){
    DB::connection('mongodb')->table('campus_authorization_contexts')->insert(['type'=>'association','reference'=>'dev-financial-cash','active'=>true,'source'=>'development_fixture']);
    $directory=new \App\Domains\CampusAuthorization\Module1Directory();
    expect($directory->scopeExists('association','dev-financial-cash'))->toBeTrue();
    $env=app()->environment();app()->instance('env','production');
    try{expect($directory->scopeExists('association','dev-financial-cash'))->toBeFalse();}finally{app()->instance('env',$env);}
});
