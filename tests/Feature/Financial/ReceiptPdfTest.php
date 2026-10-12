<?php
use App\Models\User;
use App\Domains\Financial\Enums\{WalletType,MovementType};
use App\Domains\Financial\Models\{PurchasePayment,Wallet,TopUp,LegacyCashReceiptReview,LedgerEntry,FinancialTransaction};
use App\Domains\Financial\Services\{WalletService,LedgerService};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use CampusDigital\Authorization\{Kernel,Store,Directory};

beforeEach(function(){
    if(!app()->environment('testing')||config('database.connections.sqlsrv.database')!=='campus_digital_financial_testing'
       ||config('database.connections.mongodb.database')!=='campus_virtual_testing')throw new RuntimeException('Solo bases de pruebas.');
    DB::connection('sqlsrv')->beginTransaction();$this->dp29Users=[];$this->withoutVite();$this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
    $store=new class implements Store {
        public array $rows=[];
        public function transaction(callable $work):mixed {$old=$this->rows;try{return $work();}catch(Throwable $e){$this->rows=$old;throw $e;}}
        public function get(string $key):?array{return $this->rows[$key]??null;}
        public function put(string $key,array $value):void{$this->rows[$key]=$value;}
    };
    $directory=new class implements Directory {
        public array $inactive=[];
        public function account(string $identity):?array{return ['identity'=>$identity,'active'=>!in_array($identity,$this->inactive,true),'verified'=>true];}
        public function scopeExists(string $type,string $id):bool{return ($type==='business'&&in_array($id,['dp29-business-a','dp29-business-b'],true))||($type==='association'&&$id==='dp29-association');}
    };
    $this->dp29Directory=$directory;
    config(['campus_authorization.enabled'=>true,'financial_receipts.administration_provider'=>'campus']);
    $this->dp29Kernel=new Kernel($store,$directory,config('campus_authorization.catalog'),true);
    app()->instance(Kernel::class,$this->dp29Kernel);
});
afterEach(function(){
    while(DB::connection('sqlsrv')->transactionLevel()>0)DB::connection('sqlsrv')->rollBack();
    foreach($this->dp29Users??[]as$id)User::where('_id',$id)->delete();
});
function dp29User($test,bool $confirmed=true):array {
    $u=User::factory()->create();$u->forceFill(['email_verified_at'=>now(),'account_activation_pending'=>false,
        'two_factor_secret'=>$confirmed?encrypt('fixture-secret'):null,'two_factor_confirmed_at'=>$confirmed?now():null])->save();
    $test->dp29Users[]=(string)$u->getKey();$principal=$test->dp29Kernel->register((string)$u->getKey());
    $w=app(WalletService::class)->create('USER',(string)$u->getKey(),WalletType::USUARIO);
    return [$u,$w,$principal];
}
function dp29Payment(Wallet $w,string $business='dp29-business-a'):PurchasePayment {
    return PurchasePayment::create(['public_id'=>(string)Str::uuid(),'idempotency_key'=>'dp29-'.Str::uuid(),
        'wallet_id'=>$w->public_id,'bonus_id'=>(string)Str::uuid(),'business_id'=>$business,'currency'=>'MXN',
        'total_amount_cents'=>1000,'wallet_amount_cents'=>0,'bonus_amount_cents'=>1000,'status'=>'COMPLETADO']);
}
function dp29Lookup(string $source,string $id,Wallet $w,?array $scope=null,array $extra=[]):string {
    return '/finanzas/comprobantes-administracion/lookup?'.http_build_query(array_merge(['source'=>$source,'operation_id'=>$id,'wallet_id'=>$w->public_id],
        $scope?['scope_type'=>$scope['type'],'scope_id'=>$scope['id']]:[],$extra));
}
function dp29Bootstrap($test,string $principal):void {$test->dp29Kernel->seat(null,2,$principal,'Administrador de pruebas',true);}

function dp29Cash($test,Wallet $w):array {
    $r=TopUp::create(['public_id'=>(string)Str::uuid(),'wallet_id'=>$w->public_id,'amount_cents'=>1000,
        'currency'=>'MXN','method'=>\App\Domains\Financial\Enums\TopUpMethod::EFECTIVO,
        'status'=>\App\Domains\Financial\Enums\TopUpStatus::COMPLETADA,'agent_id'=>'demo-fixture','cash_shift_id'=>null,'folio'=>null]);
    $t=app(LedgerService::class)->credit($w,1000,MovementType::RECARGA,'dp29-'.Str::uuid(),'TOPUP',$r->public_id);
    config(['financial_receipts.legacy_candidates'=>[strtolower($t->public_id)=>['wallet_id'=>strtolower($w->public_id),'reference_id'=>strtolower($r->public_id),'amount_cents'=>1000]]]);
    return [$r,$t];
}
function dp29Review($test,User $actor,Wallet $w,FinancialTransaction $t,string $reason='Revisión de demostración') {
    return $test->actingAs($actor)->postJson('/finanzas/comprobantes-administracion/legacy-reviews',[
        'transaction_id'=>$t->public_id,'wallet_id'=>$w->public_id,'reason'=>$reason]);
}
function dp29Record($test,User $admin,Wallet $wallet):array {
    [$topup,$transaction]=dp29Cash($test,$wallet);
    $id=dp29Review($test,$admin,$wallet,$transaction)->assertOk()->json('data.id');
    return [$id,$transaction];
}
function dp29History(string $suffix=''):string {return '/finanzas/comprobantes-administracion/history'.$suffix;}

function dp29Pdf(string $source,string $id):string{return '/finanzas/comprobantes/'.$source.'/'.$id.'/pdf';}
function dp29AssertPdf($response):void {
    $response->assertOk()->assertHeader('Content-Type','application/pdf');
    expect(str_starts_with($response->getContent(),'%PDF-'))->toBeTrue()
        ->and(str_contains($response->getContent(),'%%EOF'))->toBeTrue()
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment; filename="Campus-Digital-comprobante-');
}
test('pdf receipt requires authentication and hides foreign wallet operations',function(){
    [$owner,$w]=dp29User($this);[$other,$ow]=dp29User($this);
    $t=app(LedgerService::class)->credit($w,1000,MovementType::RECARGA,'dp29-'.Str::uuid());
    $this->getJson(dp29Pdf('transaction',$t->public_id))->assertUnauthorized();
    $this->actingAs($other)->getJson(dp29Pdf('transaction',$t->public_id))->assertNotFound();
});
test('personal pdf downloads a real file without changing money or issuing another receipt',function(){
    [$owner,$w]=dp29User($this);$t=app(LedgerService::class)->credit($w,1000,MovementType::RECARGA,'dp29-'.Str::uuid());
    $before=[FinancialTransaction::count(),LedgerEntry::count(),$w->fresh()->available_balance_cents];
    dp29AssertPdf($this->actingAs($owner)->get(dp29Pdf('transaction',$t->public_id)));
    expect([FinancialTransaction::count(),LedgerEntry::count(),$w->fresh()->available_balance_cents])->toBe($before);
});
test('fully bonus funded purchase can download pdf without a wallet transaction',function(){
    [$owner,$w]=dp29User($this);$p=dp29Payment($w);
    dp29AssertPdf($this->actingAs($owner)->get(dp29Pdf('purchase',$p->public_id)));
});
test('pdf template escapes values and preserves historical notice',function(){
    $html=view('financial.receipt-pdf',['receipt'=>['id'=>'fixture','source'=>'TRANSACTION','status'=>'COMPLETADA','currency'=>'MXN','created_at'=>now()->toISOString(),
        'historical_notice'=>['message'=>'Demostración sin comprobante de caja <script>alert(1)</script>'],
        'entries'=>[],'purchase'=>null,'cash_receipts'=>[]],
        'money'=>fn($n,$currency)=>$currency.' '.$n,'date'=>fn($v)=>$v,'generated_at'=>now()->toISOString()])->render();
    expect($html)->not->toContain('<script>alert(1)</script>')->and($html)->toContain('&lt;script&gt;');
});
test('administrative pdf preserves global historical authorization and second factor',function(){
    [$admin,$aw,$global]=dp29User($this);dp29Bootstrap($this,$global);[$owner,$w]=dp29User($this);[$id,$t]=dp29Record($this,$admin,$w);
    $url=str_replace('/lookup?','/pdf?',dp29Lookup('transaction',$t->public_id,$w));
    dp29AssertPdf($this->actingAs($admin)->get($url));
    $this->actingAs($owner)->getJson($url)->assertForbidden();
    $admin->forceFill(['two_factor_confirmed_at'=>null])->save();$this->actingAs($admin)->getJson($url)->assertForbidden();
});
test('cash pdf requires association read and reuses the existing folio snapshot',function(){
    [$operator,$w]=dp29User($this);
    $cash=app(\App\Domains\Financial\Services\CashShiftService::class);$actor='user:'.$operator->getKey();
    $register=$cash->createRegister('dp29-association','Caja PDF','MXN',$actor);$shift=$cash->open($register->public_id,$actor,0,'dp29-'.Str::uuid());
    $movement=$cash->addMovement($shift->public_id,\App\Domains\Financial\Enums\CashMovementType::CASH_IN,1000,'dp29-'.Str::uuid(),$actor,'Prueba PDF');
    $receipt=$movement->receipt;$count=\App\Domains\Financial\Models\CashReceipt::count();
    app()->instance(\App\Domains\Financial\Contracts\CashAuthorizationProvider::class,new class($actor) implements \App\Domains\Financial\Contracts\CashAuthorizationProvider {
        public function __construct(private string $actor){}
        public function allows(string $actorId,string $associationId,string $action):bool{return $actorId===$this->actor&&$associationId==='dp29-association'&&$action==='read';}
    });
    $url='/finanzas/caja/asociaciones/dp29-association/receipts/'.$receipt->public_id.'/pdf';
    dp29AssertPdf($this->actingAs($operator)->get($url));
    $this->getJson(str_replace('dp29-association','foreign-association',$url))->assertForbidden();
    expect(\App\Domains\Financial\Models\CashReceipt::count())->toBe($count)->and($receipt->fresh()->folio)->toBe($receipt->folio);
});
