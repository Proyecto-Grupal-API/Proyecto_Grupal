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
    DB::connection('sqlsrv')->beginTransaction();$this->hr29Users=[];$this->withoutVite();$this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
    $store=new class implements Store {
        public array $rows=[];
        public function transaction(callable $work):mixed {$old=$this->rows;try{return $work();}catch(Throwable $e){$this->rows=$old;throw $e;}}
        public function get(string $key):?array{return $this->rows[$key]??null;}
        public function put(string $key,array $value):void{$this->rows[$key]=$value;}
    };
    $directory=new class implements Directory {
        public array $inactive=[];
        public function account(string $identity):?array{return ['identity'=>$identity,'active'=>!in_array($identity,$this->inactive,true),'verified'=>true];}
        public function scopeExists(string $type,string $id):bool{return ($type==='business'&&in_array($id,['hr29-business-a','hr29-business-b'],true))||($type==='association'&&$id==='hr29-association');}
    };
    $this->hr29Directory=$directory;
    config(['campus_authorization.enabled'=>true,'financial_receipts.administration_provider'=>'campus']);
    $this->hr29Kernel=new Kernel($store,$directory,config('campus_authorization.catalog'),true);
    app()->instance(Kernel::class,$this->hr29Kernel);
});
afterEach(function(){
    while(DB::connection('sqlsrv')->transactionLevel()>0)DB::connection('sqlsrv')->rollBack();
    foreach($this->hr29Users??[]as$id)User::where('_id',$id)->delete();
});
function hr29User($test,bool $confirmed=true):array {
    $u=User::factory()->create();$u->forceFill(['email_verified_at'=>now(),'account_activation_pending'=>false,
        'two_factor_secret'=>$confirmed?encrypt('fixture-secret'):null,'two_factor_confirmed_at'=>$confirmed?now():null])->save();
    $test->hr29Users[]=(string)$u->getKey();$principal=$test->hr29Kernel->register((string)$u->getKey());
    $w=app(WalletService::class)->create('USER',(string)$u->getKey(),WalletType::USUARIO);
    return [$u,$w,$principal];
}
function hr29Payment(Wallet $w,string $business='hr29-business-a'):PurchasePayment {
    return PurchasePayment::create(['public_id'=>(string)Str::uuid(),'idempotency_key'=>'hr29-'.Str::uuid(),
        'wallet_id'=>$w->public_id,'bonus_id'=>(string)Str::uuid(),'business_id'=>$business,'currency'=>'MXN',
        'total_amount_cents'=>1000,'wallet_amount_cents'=>0,'bonus_amount_cents'=>1000,'status'=>'COMPLETADO']);
}
function hr29Lookup(string $source,string $id,Wallet $w,?array $scope=null,array $extra=[]):string {
    return '/finanzas/comprobantes-administracion/lookup?'.http_build_query(array_merge(['source'=>$source,'operation_id'=>$id,'wallet_id'=>$w->public_id],
        $scope?['scope_type'=>$scope['type'],'scope_id'=>$scope['id']]:[],$extra));
}
function hr29Bootstrap($test,string $principal):void {$test->hr29Kernel->seat(null,2,$principal,'Administrador de pruebas',true);}

function hr29Cash($test,Wallet $w):array {
    $r=TopUp::create(['public_id'=>(string)Str::uuid(),'wallet_id'=>$w->public_id,'amount_cents'=>1000,
        'currency'=>'MXN','method'=>\App\Domains\Financial\Enums\TopUpMethod::EFECTIVO,
        'status'=>\App\Domains\Financial\Enums\TopUpStatus::COMPLETADA,'agent_id'=>'demo-fixture','cash_shift_id'=>null,'folio'=>null]);
    $t=app(LedgerService::class)->credit($w,1000,MovementType::RECARGA,'hr29-'.Str::uuid(),'TOPUP',$r->public_id);
    config(['financial_receipts.legacy_candidates'=>[strtolower($t->public_id)=>['wallet_id'=>strtolower($w->public_id),'reference_id'=>strtolower($r->public_id),'amount_cents'=>1000]]]);
    return [$r,$t];
}
function hr29Review($test,User $actor,Wallet $w,FinancialTransaction $t,string $reason='Revisión de demostración') {
    return $test->actingAs($actor)->postJson('/finanzas/comprobantes-administracion/legacy-reviews',[
        'transaction_id'=>$t->public_id,'wallet_id'=>$w->public_id,'reason'=>$reason]);
}
function hr29Record($test,User $admin,Wallet $wallet):array {
    [$topup,$transaction]=hr29Cash($test,$wallet);
    $id=hr29Review($test,$admin,$wallet,$transaction)->assertOk()->json('data.id');
    return [$id,$transaction];
}
function hr29History(string $suffix=''):string {return '/finanzas/comprobantes-administracion/history'.$suffix;}

test('review history requires authentication global seat and confirmed second factor',function(){
    $this->getJson(hr29History())->assertUnauthorized();$this->getJson(hr29History('/records'))->assertUnauthorized();
    [$admin,$aw,$global]=hr29User($this);hr29Bootstrap($this,$global);[$operator,$ow,$principal]=hr29User($this);
    $this->hr29Kernel->grant($global,$principal,2,['module.2.9.read','module.2.9.configure'],null,true,null,'Permiso modular');
    $this->actingAs($operator)->getJson(hr29History('/records'))->assertForbidden();
    $admin->forceFill(['two_factor_confirmed_at'=>null])->save();$this->actingAs($admin)->getJson(hr29History())->assertForbidden();
});
test('review history pagination and filters expose summaries without full evidence snapshots',function(){
    [$admin,$aw,$global]=hr29User($this);hr29Bootstrap($this,$global);[$owner,$w]=hr29User($this);[$other,$ow]=hr29User($this);
    [$id,$t]=hr29Record($this,$admin,$w);hr29Record($this,$admin,$ow);
    $this->actingAs($admin)->getJson(hr29History('/records?per_page=1'))->assertOk()->assertJsonCount(1,'data')->assertJsonPath('meta.total',2)
        ->assertJsonMissingPath('data.0.snapshot')->assertJsonMissingPath('data.0.evidence_hash');
    $this->getJson(hr29History('/records?wallet_id='.$w->public_id))->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$id);
    $this->getJson(hr29History('/records?transaction_id='.$t->public_id.'&reviewed_by='.$admin->getKey()))->assertOk()->assertJsonCount(1,'data');
    $this->getJson(hr29History('/records?per_page=51'))->assertUnprocessable();
    $this->getJson(hr29History('/records?actor_id=forged'))->assertUnprocessable();
});
test('review detail retains its approval evidence without changing financial records',function(){
    [$admin,$aw,$global]=hr29User($this);hr29Bootstrap($this,$global);[$owner,$w]=hr29User($this);[$id,$t]=hr29Record($this,$admin,$w);
    $before=[LegacyCashReceiptReview::count(),FinancialTransaction::count(),LedgerEntry::count(),$w->fresh()->available_balance_cents];
    $this->actingAs($admin)->get(hr29History('/'.$id))->assertOk()->assertInertia(fn(\Inertia\Testing\AssertableInertia $page)=>$page
        ->component('Financial/LegacyCashReceiptReviewDetail')->where('review.evidence_status','CONSISTENT')
        ->where('review.snapshot.amount_cents',1000)->where('review.reviewed_by',(string)$admin->getKey()));
    expect([LegacyCashReceiptReview::count(),FinancialTransaction::count(),LedgerEntry::count(),$w->fresh()->available_balance_cents])->toBe($before);
});
test('changed ledger blocks receipt but leaves the historical audit detail readable',function(){
    [$admin,$w,$global]=hr29User($this);hr29Bootstrap($this,$global);[$id,$t]=hr29Record($this,$admin,$w);
    LedgerEntry::where('transaction_id',$t->public_id)->update(['amount_cents'=>999]);
    $this->actingAs($admin)->getJson(hr29Lookup('transaction',$t->public_id,$w))->assertConflict();
    $this->get(hr29History('/'.$id))->assertOk()->assertInertia(fn(\Inertia\Testing\AssertableInertia $page)=>$page
        ->where('review.evidence_status','SOURCE_CHANGED')->where('review.snapshot.amount_cents',1000));
});
test('missing original records do not erase the review and unknown review remains not found',function(){
    [$admin,$w,$global]=hr29User($this);hr29Bootstrap($this,$global);[$id,$t]=hr29Record($this,$admin,$w);
    LedgerEntry::where('transaction_id',$t->public_id)->delete();FinancialTransaction::where('public_id',$t->public_id)->delete();
    $this->actingAs($admin)->get(hr29History('/'.$id))->assertOk()->assertInertia(fn(\Inertia\Testing\AssertableInertia $page)=>$page->where('review.evidence_status','SOURCE_MISSING'));
    $this->getJson(hr29History('/'.Str::uuid()))->assertNotFound();
});
test('an altered review snapshot is reported while model updates and deletes remain forbidden',function(){
    [$admin,$w,$global]=hr29User($this);hr29Bootstrap($this,$global);[$id,$t]=hr29Record($this,$admin,$w);
    $review=LegacyCashReceiptReview::where('public_id',$id)->firstOrFail();
    expect(fn()=>$review->update(['reason'=>'Overwrite']))->toThrow(LogicException::class);
    expect(fn()=>$review->delete())->toThrow(LogicException::class);
    $snapshot=$review->snapshot;$snapshot['amount_cents']=1;
    DB::connection('sqlsrv')->table('legacy_cash_receipt_reviews')->where('public_id',$id)->update(['snapshot'=>json_encode($snapshot)]);
    $this->actingAs($admin)->get(hr29History('/'.$id))->assertOk()->assertInertia(fn(\Inertia\Testing\AssertableInertia $page)=>$page->where('review.evidence_status','REVIEW_RECORD_INCONSISTENT'));
    $this->getJson(hr29Lookup('transaction',$t->public_id,$w))->assertConflict();
});
