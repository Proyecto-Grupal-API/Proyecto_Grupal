<?php

use App\Domains\Financial\Contracts\{InstitutionalBudgetAuthorization, FinancialWebAuthorizer, BonusAuthorizationProvider};
use App\Domains\Financial\Enums\{BonusType, BonusRestrictionType};
use App\Domains\Financial\Models\{InstitutionalBonusBudget, InstitutionalBudgetOperation, BonusBudgetCommitment, Bonus,
    BonusLedgerEntry, BonusAdministrativeOperation, Wallet};
use App\Domains\Financial\Services\{InstitutionalBudgetService, InstitutionalBecaIssuanceService, BonusService, BonusAdministrationService};
use App\Domains\Financial\Exceptions\IdentityFinancialAuthorizationUnavailable;
use App\Models\User;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

beforeEach(function () {
    if (!app()->environment('testing') || config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing'
        || config('database.connections.mongodb.database') !== 'campus_virtual_testing') throw new RuntimeException('Solo bases de pruebas.');
    DB::connection('sqlsrv')->beginTransaction(); Http::preventStrayRequests(); $this->freezeTime();
    // Whole seconds avoid DATETIME rounding relative to the frozen PHP clock.
    $this->travelTo(now()->startOfSecond());
    $this->ibcOperator = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->ibcRecipient = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->ibcActor = (string) $this->ibcOperator->getKey();
    $this->ibcAuthority = new class implements InstitutionalBudgetAuthorization {
        public bool $commit = true;
        public function allows(string $actorId, string $action, ?string $budgetId = null): bool { return $action !== 'budget.commit' || $this->commit; }
    };
    $this->ibcAccess = new class implements FinancialWebAuthorizer {
        public bool $issue = true;
        public function allows(string $userId, string $action, ?string $walletId = null): bool { return in_array($action,['bonus.issue','bonus.cancel'],true) && $this->issue; }
    };
    $this->ibcIssuer = new class implements BonusAuthorizationProvider {
        public bool $allowed = true; public bool $unavailable = false;
        public function canIssueBonus(string $issuerType, string $issuerId, BonusType $type): bool {
            if ($this->unavailable) throw new IdentityFinancialAuthorizationUnavailable('FIXTURE_UNAVAILABLE');
            return $this->allowed && $issuerType === 'USER' && $type === BonusType::BECA;
        }
    };
    app()->instance(InstitutionalBudgetAuthorization::class, $this->ibcAuthority);
    app()->instance(FinancialWebAuthorizer::class, $this->ibcAccess);
    app()->instance(BonusAuthorizationProvider::class, $this->ibcIssuer);
    $this->ibcService = app(InstitutionalBecaIssuanceService::class);
    $data = ['institution_reference' => 'fixture-institution', 'approval_reference' => 'approval-'.Str::uuid(),
        'payer_reference' => 'fixture-approved-payer', 'purpose' => 'Programa BECA', 'approved_amount_cents' => 10000,
        'currency' => 'MXN', 'reason' => 'Aprobacion de prueba', 'valid_from' => now()->subDay()->toISOString(), 'expires_at' => now()->addMonth()->toISOString()];
    $r = app(InstitutionalBudgetService::class)->approve($this->ibcActor, $data, 'approve-'.Str::uuid());
    $this->ibcBudget = InstitutionalBonusBudget::where('public_id', $r['operation']->budget_id)->firstOrFail();
    $this->ibcBuyer = (string)$this->ibcRecipient->getKey();
    $this->ibcWallet = app(\App\Domains\Financial\Services\WalletService::class)->create('USER',$this->ibcBuyer,\App\Domains\Financial\Enums\WalletType::USUARIO);
    $this->ibcConsent = new class implements \App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization {
        public bool $pay = true; public bool $refund = true; public bool $unavailable = false;
        public function allows(string $actorId,string $action,array $context): bool {
            if ($this->unavailable) throw new RuntimeException('fixture-consent-unavailable');
            return $action === 'purchase.pay' ? $this->pay && ($context['authorization_reference'] ?? '') === 'fixture-consent' : $this->refund;
        }
    };
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization::class,$this->ibcConsent);
    $this->ibcIssued = ibcIssue($this);
    $this->ibcMarket = ['business_id'=>'fixture-approved-business','order_id'=>'fixture-order-'.Str::uuid(),'items'=>[]];

    $this->ibcPurchase=ibcPay($this);
    // External payment follows the purchase; never weaken the production date check.
    $this->travel(1)->seconds();
    $this->ibcSettlementAuthority=new class implements \App\Domains\Financial\Contracts\InstitutionalSettlementAuthorization {
        public bool $allowed=true; public bool $unavailable=false;
        public function allows(string $actorId,string $action,array $context): bool {
            if ($this->unavailable) throw new RuntimeException('fixture-payment-verifier-unavailable');
            return $this->allowed && $action==='settlement.record' && ($context['recipient_reference'] ?? '')==='fixture-approved-recipient';
        }
    };
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalSettlementAuthorization::class,$this->ibcSettlementAuthority);

    $this->withoutVite();$this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
    $this->withoutMiddleware(\App\Http\Middleware\TrackDeviceSession::class);
    $this->ibcRecipient->forceFill(['two_factor_secret'=>encrypt('fixture-beca-checkout'),'two_factor_confirmed_at'=>now()])->save();
    $merchant = app(\App\Domains\Financial\Services\WalletService::class)
        ->create('BUSINESS', 'fixture-approved-business', \App\Domains\Financial\Enums\WalletType::NEGOCIO);
    config(['financial_marketplace.enabled'=>true,'financial_marketplace.institutional_beca_enabled'=>true,
        'financial_marketplace.clients' => ['svc_beca_checkout' => ['name' => 'Marketplace fixture',
            'businesses' => ['fixture-approved-business' => 'Negocio fixture'],
            'recipient_wallets' => ['fixture-approved-business' => strtolower($merchant->public_id)]]],
        'financial_marketplace.ttl_seconds'=>600,'financial_marketplace.password_max_age_seconds'=>300,'financial_team4.enabled'=>false]);
    \App\Models\ServiceClient::create(['client_id'=>'svc_beca_checkout','name'=>'Beca checkout','active'=>true,'secret_hash'=>'fixture-unused',
        'scopes'=>['financial:purchase:prepare','financial:purchase:read']]);
    // Production native authorization, not the permissive purchase fixture used to seed historical evidence.
    app()->bind(\App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization::class,\App\Domains\Financial\Adapters\CheckoutInstitutionalPurchaseAuthorization::class);


});
afterEach(function () {
    InstitutionalBudgetOperation::flushEventListeners(); BonusBudgetCommitment::flushEventListeners(); BonusLedgerEntry::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusObligation::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusRefund::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusSettlement::flushEventListeners();
    while (DB::connection('sqlsrv')->transactionLevel() > 0) DB::connection('sqlsrv')->rollBack();
});
function ibcIssuePayload($test): array
{
    return ['award_reference' => 'fixture-award-'.Str::uuid(), 'beneficiary_id' => (string) $test->ibcRecipient->getKey(), 'type' => 'BECA', 'amount_cents' => 3000,
        'valid_from' => now()->subMinute()->toISOString(), 'expires_at' => now()->addDay()->toISOString(),
        'combinable' => true, 'allows_partial_use' => true, 'reason' => 'Beca de prueba con presupuesto',
        'external_reference' => 'test-budget-issue-'.Str::uuid(), 'restrictions' => []];
}
function ibcIssue($test, ?array $data = null, ?string $key = null, int $version = 1): array
{
    return $test->ibcService->issue($test->ibcActor, $test->ibcBudget->public_id, $version,
        $data ?? ibcIssuePayload($test), $key ?? 'issue-'.Str::uuid());
}
function ibcPay($test, mixed $amount = 1000, ?string $key = null, ?array $market = null, string $proof = 'fixture-consent'): array
{
    return app(\App\Domains\Financial\Services\PurchasePaymentService::class)->payWithInstitutionalBeca($test->ibcWallet,
        $test->ibcIssued['bonus_id'],$amount,$key ?? 'pay-'.Str::uuid(),$test->ibcBuyer,$proof,$market ?? $test->ibcMarket);
}
function ibcPrepare($t,int $amount=1000):string {
    $row=app(\App\Domains\Financial\Services\MarketplaceCheckoutService::class)->prepare('svc_beca_checkout','prepare-'.Str::uuid(),
        ['buyer_id'=>$t->ibcBuyer,'order_id'=>'checkout-order-'.Str::uuid(),'business_id'=>'fixture-approved-business',
            'amount_cents'=>$amount,'currency'=>'MXN','items'=>[],'description'=>'Compra con BECA']);
    return strtolower($row->public_id);
}
function ibcBrowser($t,string $id,bool $recent=true,?User $user=null):array {
    $user??=$t->ibcRecipient;
    $session=\App\Models\UserSession::create(['user_id'=>(string)$user->getKey(),'started_at'=>now()]);
    $native=app('session')->driver();$native->invalidate();$native->put([
        \Illuminate\Support\Facades\Auth::guard('web')->getName()=>(string)$user->getKey(),
        'cd_session_id'=>(string)$session->getKey(),'auth.password_confirmed_at'=>now()->timestamp-($recent?0:1000)]);$native->save();
    $t->actingAs($user)->withCookie(config('session.cookie'),$native->getId());
    return [ibcView($t,$id),$session];
}
function ibcView($t,string $id):array {
    $data=[];$t->get('/finanzas/compras/solicitudes/'.$id)->assertOk()->assertInertia(function(\Inertia\Testing\AssertableInertia $p)use(&$data){
        $p->component('Financial/MarketplaceCheckout')->where('reviewToken',function($v)use(&$data){$data['review']=$v;return is_string($v);})
            ->where('becaOptions',function($v)use(&$data){$data['options']=collect($v)->toArray();return true;})->etc();
    });
    $t->withCookie(config('session.cookie'),app('session')->getId());return $data;
}
function ibcBody($t,array $view):array {
    $option=collect($view['options'])->firstWhere('bonus_id',$t->ibcIssued['bonus_id']);
    return ['consent'=>true,'review_token'=>$view['review'],'payment_method'=>'BECA','bonus_id'=>$option['bonus_id'],
        'funding_review_token'=>$option['funding_review_token']];
}
function ibcConfirm($t,string $id,array $body){return $t->post('/finanzas/compras/solicitudes/'.$id.'/confirmar',$body);}
function ibcState():array {
    $out=[];foreach(['marketplace_payment_requests','institutional_bonus_budgets','institutional_budget_operations','bonus_budget_commitments',
        'bonuses','bonus_ledger_entries','bonus_administrative_operations','wallets','ledger_entries','financial_transactions',
        'purchase_payments','purchase_marketplace_links','institutional_bonus_obligations','institutional_bonus_refunds','institutional_bonus_settlements',
        'financial_team4_outbox','financial_team4_streams']as$table)$out[$table]=DB::connection('sqlsrv')->table($table)->orderBy('id')->get()->toArray();
    return $out;
}

test('institutional checkout offers only own eligible funded bonus and never moves money on review',function(){
    $id=ibcPrepare($this);$before=ibcState();[$view]=ibcBrowser($this,$id);
    expect($view['options'])->toHaveCount(1)->and($view['options'][0]['bonus_id'])->toBe($this->ibcIssued['bonus_id'])
        ->and($view['options'][0]['remaining_amount_cents'])->toBe('2000')->and(ibcState())->toEqual($before);
});

test('institutional checkout native consent creates purchase obligation and event without a wallet debit',function(){
    $id=ibcPrepare($this);[$view]=ibcBrowser($this,$id);$wallet=$this->ibcWallet->fresh()->toArray();$entries=\App\Domains\Financial\Models\LedgerEntry::count();
    ibcConfirm($this,$id,ibcBody($this,$view))->assertRedirect()->assertSessionHasNoErrors();
    $row=\App\Domains\Financial\Models\MarketplacePaymentRequest::where('public_id',$id)->firstOrFail();
    $p=\App\Domains\Financial\Models\PurchasePayment::where('public_id',$row->purchase_payment_id)->firstOrFail();
    expect($row->status)->toBe('PAID')->and($row->audit_context['payment_method'])->toBe('BECA')
        ->and($p->wallet_amount_cents)->toBe(0)->and($p->bonus_amount_cents)->toBe(1000)
        ->and($this->ibcWallet->fresh()->toArray())->toEqual($wallet)->and(\App\Domains\Financial\Models\LedgerEntry::count())->toBe($entries)
        ->and(\App\Domains\Financial\Models\InstitutionalBonusObligation::where('purchase_payment_id',$p->public_id)->count())->toBe(1);
    $stream=DB::connection('sqlsrv')->table('financial_team4_streams')->where('payment_intent_id',strtolower($p->public_id))->first();
    expect($stream)->not->toBeNull();
    $this->get(route('financial.receipts.show',['source'=>'purchase','operationId'=>strtolower($p->public_id)]))->assertOk();
    Http::assertNothingSent();
});

test('institutional checkout repeated confirmation returns the original payment without another obligation or event',function(){
    $id=ibcPrepare($this);[$view]=ibcBrowser($this,$id);$body=ibcBody($this,$view);
    ibcConfirm($this,$id,$body)->assertRedirect()->assertSessionHasNoErrors();$before=ibcState();
    ibcConfirm($this,$id,$body)->assertRedirect()->assertSessionHasNoErrors();expect(ibcState())->toEqual($before);
    ibcConfirm($this,$id,['consent'=>true,'review_token'=>$view['review']])->assertSessionHasErrors('payment');expect(ibcState())->toEqual($before);
});

test('institutional checkout rejects altered bonus token amount selectors or missing consent',function(){
    $id=ibcPrepare($this);[$view]=ibcBrowser($this,$id);$body=ibcBody($this,$view);$before=ibcState();
    ibcConfirm($this,$id,[...$body,'funding_review_token'=>str_repeat('0',64)])->assertSessionHasErrors('payment');
    ibcConfirm($this,$id,[...$body,'bonus_id'=>(string)Str::uuid()])->assertSessionHasErrors('payment');
    ibcConfirm($this,$id,[...$body,'consent'=>false])->assertSessionHasErrors('consent');
    $this->postJson('/finanzas/compras/solicitudes/'.$id.'/confirmar',[...$body,'amount_cents'=>1])->assertUnprocessable();
    expect(ibcState())->toEqual($before);
});

test('institutional checkout token is bound to the exact order and session',function(){
    $id=ibcPrepare($this);$other=ibcPrepare($this);[$view]=ibcBrowser($this,$id);$body=ibcBody($this,$view);
    $otherView=ibcView($this,$other);$before=ibcState();
    ibcConfirm($this,$other,[...$body,'review_token'=>$otherView['review']])->assertSessionHasErrors('payment');
    [$newView]=ibcBrowser($this,$id);ibcConfirm($this,$id,$body)->assertSessionHasErrors('payment');expect(ibcState())->toEqual($before);
});

test('institutional checkout disabled mode hides choices and refuses a previously reviewed bonus',function(){
    $id=ibcPrepare($this);[$view]=ibcBrowser($this,$id);$body=ibcBody($this,$view);config(['financial_marketplace.institutional_beca_enabled'=>false]);
    $review=ibcView($this,$id);expect($review['options'])->toBe([]);$before=ibcState();
    ibcConfirm($this,$id,$body)->assertStatus(503);expect(ibcState())->toEqual($before);
});

test('institutional checkout direct calls without an active native confirmation remain denied',function(){
    $id=ibcPrepare($this);[$view]=ibcBrowser($this,$id);$body=ibcBody($this,$view);
    expect(fn()=>ibcPay($this,1000,'outside-'.Str::uuid(),[...$this->ibcMarket,'order_id'=>'outside-'.Str::uuid()],
        'checkout-beca:'.$body['funding_review_token']))->toThrow(AuthorizationException::class);
    expect(app(\App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization::class)->allows($this->ibcBuyer,'purchase.refund',[]))->toBeFalse();
});

test('institutional checkout recent password and confirmed second factor are required without affecting wallet choice',function(){
    $id=ibcPrepare($this);[$view]=ibcBrowser($this,$id,false);$body=ibcBody($this,$view);$before=ibcState();
    ibcConfirm($this,$id,$body)->assertSessionHasErrors('payment');
    [$view]=ibcBrowser($this,$id);$body=ibcBody($this,$view);$this->ibcRecipient->forceFill(['two_factor_confirmed_at'=>null])->save();
    $missing=ibcView($this,$id);expect($missing['options'])->toBe([]);ibcConfirm($this,$id,$body)->assertForbidden();expect(ibcState())->toEqual($before);
});

test('institutional checkout revoked session and merchant permission prevent consumption',function(){
    $id=ibcPrepare($this);[$view,$session]=ibcBrowser($this,$id);$body=ibcBody($this,$view);$before=ibcState();
    $session->update(['revoked_at'=>now()]);assertRevokedSessionDenied(ibcConfirm($this,$id,$body));
    [$view]=ibcBrowser($this,$id);$body=ibcBody($this,$view);
    \App\Models\ServiceClient::where('client_id','svc_beca_checkout')->update(['active'=>false]);ibcConfirm($this,$id,$body)->assertForbidden();expect(ibcState())->toEqual($before);
});

test('institutional checkout missing registered session cannot be silently recreated before payment',function(){
    $id=ibcPrepare($this);[$view]=ibcBrowser($this,$id);$body=ibcBody($this,$view);$before=ibcState();
    $native=app('session')->driver();$native->forget('cd_session_id');$native->save();$this->withCookie(config('session.cookie'),$native->getId());
    ibcConfirm($this,$id,$body)->assertForbidden();expect(ibcState())->toEqual($before);
});

test('institutional checkout insufficient remaining amount never falls back to wallet',function(){
    $id=ibcPrepare($this,1500);[$view]=ibcBrowser($this,$id);$body=ibcBody($this,$view);
    $second=ibcPrepare($this,1000);$secondView=ibcView($this,$second);ibcConfirm($this,$second,ibcBody($this,$secondView))->assertSessionHasNoErrors();
    $before=ibcState();ibcConfirm($this,$id,$body)->assertSessionHasErrors('payment');expect(ibcState())->toEqual($before);
});

test('institutional checkout suspended or inconsistent budget refuses payment after review',function(){
    $id=ibcPrepare($this);[$view]=ibcBrowser($this,$id);$body=ibcBody($this,$view);
    app(InstitutionalBudgetService::class)->setSuspended($this->ibcActor,$this->ibcBudget->public_id,$this->ibcBudget->fresh()->version,true,'Revision','suspend-'.Str::uuid());
    $before=ibcState();ibcConfirm($this,$id,$body)->assertSessionHasErrors('payment');expect(ibcState())->toEqual($before);
    app(InstitutionalBudgetService::class)->setSuspended($this->ibcActor,$this->ibcBudget->public_id,$this->ibcBudget->fresh()->version,false,'Reactivar','resume-'.Str::uuid());
    DB::connection('sqlsrv')->table('institutional_bonus_budgets')->where('public_id',$this->ibcBudget->public_id)->increment('committed_amount_cents');
    $before=ibcState();ibcConfirm($this,$id,$body)->assertSessionHasErrors('payment');expect(ibcState())->toEqual($before);
});

test('institutional checkout request persistence failure rolls back bonus budget debt and outbox and clears consent',function(){
    $id=ibcPrepare($this);[$view]=ibcBrowser($this,$id);$body=ibcBody($this,$view);$before=ibcState();$this->withoutExceptionHandling();
    $class=\App\Domains\Financial\Models\MarketplacePaymentRequest::class;$dispatcher=$class::getEventDispatcher();$class::setEventDispatcher(clone $dispatcher);
    $class::saving(function($r){if($r->status==='PAID')throw new RuntimeException('fixture-final-checkout-failure');});
    try{expect(fn()=>ibcConfirm($this,$id,$body))->toThrow(RuntimeException::class,'fixture-final-checkout-failure');}
    finally{$class::setEventDispatcher($dispatcher);}
    expect(ibcState())->toEqual($before)->and(app(\App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization::class)->allows($this->ibcBuyer,'purchase.pay',[]))->toBeFalse();
    ibcConfirm($this,$id,$body)->assertSessionHasNoErrors();
});

test('institutional checkout paid replay refuses later budget corruption without repeating any money',function(){
    $id=ibcPrepare($this);[$view]=ibcBrowser($this,$id);$body=ibcBody($this,$view);ibcConfirm($this,$id,$body)->assertSessionHasNoErrors();
    DB::connection('sqlsrv')->table('institutional_bonus_budgets')->where('public_id',$this->ibcBudget->public_id)->increment('committed_amount_cents');$before=ibcState();
    ibcConfirm($this,$id,$body)->assertSessionHasErrors('payment');expect(ibcState())->toEqual($before);
});

test('institutional checkout excludes foreign legacy expired restricted nonpartial and insufficient bonuses',function(){
    $foreign=User::factory()->create(['email_verified_at'=>now(),'account_activation_pending'=>false,'must_change_password'=>false]);
    foreach(['foreign','business','category','full','insufficient','expired']as$kind){
        $payload=[...ibcIssuePayload($this),'amount_cents'=>$kind==='insufficient'?200:1000];
        if($kind==='foreign')$payload['beneficiary_id']=(string)$foreign->getKey();
        if($kind==='business')$payload['restrictions']=[['type'=>'NEGOCIO','target_id'=>'other-business']];
        if($kind==='category')$payload['restrictions']=[['type'=>'CATEGORIA','target_id'=>'unverified-category']];
        if($kind==='full')$payload['allows_partial_use']=false;
        if($kind==='expired')$payload['expires_at']=now()->addMinute()->toISOString();
        ibcIssue($this,$payload,null,$this->ibcBudget->fresh()->version);
    }
    app(BonusService::class)->issue('USER',$this->ibcBuyer,'USER',$this->ibcActor,BonusType::BECA,1000,now()->subMinute(),now()->addDay());
    $id=ibcPrepare($this,500);$this->travel(2)->minutes();[$view]=ibcBrowser($this,$id);
    expect(array_column($view['options'],'bonus_id'))->toBe([$this->ibcIssued['bonus_id']]);
});

test('institutional checkout cannot spend a foreign bonus even with a valid leaked funding token',function(){
    $foreign=User::factory()->create(['email_verified_at'=>now(),'account_activation_pending'=>false,'must_change_password'=>false]);
    $grant=ibcIssue($this,[...ibcIssuePayload($this),'beneficiary_id'=>(string)$foreign->getKey(),'amount_cents'=>1000],null,$this->ibcBudget->fresh()->version);
    $id=ibcPrepare($this);[$view]=ibcBrowser($this,$id);$body=ibcBody($this,$view);$body['bonus_id']=$grant['bonus_id'];
    // A server-minted token alone must never substitute ownership.
    $r=\Illuminate\Http\Request::create('/','POST');$r->setLaravelSession(app('session')->driver());$r->setUserResolver(fn()=>$this->ibcRecipient);
    $row=\App\Domains\Financial\Models\MarketplacePaymentRequest::where('public_id',$id)->firstOrFail();
    $body['funding_review_token']=app(\App\Domains\Financial\Services\MarketplaceCheckoutService::class)->becaReviewToken($r,$row,$grant['bonus_id'],$this->ibcBudget->public_id);
    $before=ibcState();ibcConfirm($this,$id,$body)->assertSessionHasErrors('payment');expect(ibcState())->toEqual($before);
});

test('institutional checkout revocation after waiting for wallet lock rolls back the complete payment',function(){
    $id=ibcPrepare($this);[$view,$session]=ibcBrowser($this,$id);$body=ibcBody($this,$view);$before=ibcState();$revoked=false;
    DB::connection('sqlsrv')->beforeExecuting(function($sql)use($session,&$revoked){
        if(!$revoked&&str_contains(strtolower($sql),'wallets')&&str_contains(strtolower($sql),'updlock')){$revoked=true;$session->update(['revoked_at'=>now()]);}
    });
    ibcConfirm($this,$id,$body)->assertForbidden();expect($revoked)->toBeTrue()->and(ibcState())->toEqual($before);
});

test('institutional checkout expiry preserves pending stored request without consuming a bonus',function(){
    $id=ibcPrepare($this);[$view]=ibcBrowser($this,$id);$body=ibcBody($this,$view);$before=ibcState();
    $this->travel(601)->seconds();$native=app('session')->driver();$native->put('auth.password_confirmed_at',now()->timestamp);$native->save();$this->withCookie(config('session.cookie'),$native->getId());
    ibcConfirm($this,$id,$body)->assertSessionHasErrors('payment');expect(ibcState())->toEqual($before);
});

test('institutional checkout active consent cannot authorize a changed amount action or reference',function(){
    $id=ibcPrepare($this);[$view]=ibcBrowser($this,$id);$body=ibcBody($this,$view);
    $r=\Illuminate\Http\Request::create('/','POST',$body);$r->setLaravelSession(app('session')->driver());$r->setUserResolver(fn()=>$this->ibcRecipient);
    $row=\App\Domains\Financial\Models\MarketplacePaymentRequest::where('public_id',$id)->firstOrFail();
    $context=['wallet_id'=>strtolower($this->ibcWallet->public_id),'buyer_id'=>$this->ibcBuyer,'bonus_id'=>$body['bonus_id'],'amount_cents'=>1000,
        'currency'=>'MXN','category_id'=>null,'authorization_reference'=>'checkout-beca:'.$body['funding_review_token'],
        'marketplace'=>['order_id'=>$row->order_id,'business_id'=>$row->business_id,'items'=>[]],'budget_id'=>strtolower($this->ibcBudget->public_id)];
    $cap=app(\App\Domains\Financial\Services\InstitutionalCheckoutConsent::class);$authority=app(\App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization::class);
    $cap->within($r,$row,$context,function()use($authority,$context){
        expect($authority->allows($this->ibcBuyer,'purchase.pay',$context))->toBeTrue()
            ->and($authority->allows($this->ibcBuyer,'purchase.pay',[...$context,'amount_cents'=>1]))->toBeFalse()
            ->and($authority->allows($this->ibcBuyer,'purchase.refund',$context))->toBeFalse();
    });
    $altered=[...$context,'amount_cents'=>1];
    expect($cap->within($r,$row,$altered,fn()=>$authority->allows($this->ibcBuyer,'purchase.pay',$altered)))->toBeFalse();
    $altered=[...$context,'authorization_reference'=>'unbound-reference'];
    expect($cap->within($r,$row,$altered,fn()=>$authority->allows($this->ibcBuyer,'purchase.pay',$altered)))->toBeFalse()
        ->and($authority->allows($this->ibcBuyer,'purchase.pay',$context))->toBeFalse();
});


test('institutional checkout wallet selection with insufficient cash never consumes an available beca',function(){
    $id=ibcPrepare($this);[$view]=ibcBrowser($this,$id);$before=ibcState();
    ibcConfirm($this,$id,['consent'=>true,'review_token'=>$view['review']])->assertSessionHasErrors('payment');
    expect(ibcState())->toEqual($before);
});
