<?php
use App\Domains\Financial\Models\{Wallet,MarketplacePaymentRequest,PurchasePayment,FinancialTransaction};
use App\Domains\Financial\Services\MarketplaceCheckoutService;
use App\Models\{User,UserSession,ServiceClient};
use App\Services\OAuthTokenService;
use Illuminate\Support\Facades\{DB,Hash,Http};
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function(){
    if(!app()->environment('testing')||config('database.connections.sqlsrv.database')!=='campus_digital_financial_testing'
        ||config('database.connections.mongodb.database')!=='campus_virtual_testing')throw new RuntimeException('Solo bases de pruebas.');
    DB::connection('sqlsrv')->beginTransaction();$this->withoutVite();Http::preventStrayRequests();
    $this->withoutMiddleware([\App\Http\Middleware\TrackDeviceSession::class,\App\Http\Middleware\EnsureSessionIsActive::class]);
    config(['financial_marketplace.enabled'=>true,'financial_marketplace.clients'=>['svc_checkout_fixture'=>['name'=>'Marketplace de pruebas','businesses'=>['BUS-CHECKOUT'=>'Negocio de pruebas']]],
        'financial_marketplace.ttl_seconds'=>600,'financial_marketplace.password_max_age_seconds'=>300,'financial_team4.enabled'=>false,
        'oauth.signing_key'=>'fixture-checkout-key-not-for-real-use','oauth.issuer'=>'fixture-identity','oauth.audience'=>'fixture-financial']);
    ServiceClient::create(['client_id'=>'svc_checkout_fixture','name'=>'Checkout fixture','secret_hash'=>Hash::make('fixture-password'),
        'active'=>true,'scopes'=>['financial:purchase:prepare','financial:purchase:read']]);
});
afterEach(function(){while(DB::connection('sqlsrv')->transactionLevel()>0)DB::connection('sqlsrv')->rollBack();});
function mckUser():array {
    $u=User::factory()->create(['email'=>'checkout-'.Str::uuid().'@example.test']);
    $u->forceFill(['email_verified_at'=>now(),'account_activation_pending'=>false,'must_change_password'=>false])->save();
    $w=Wallet::create(['public_id'=>(string)Str::uuid(),'owner_type'=>'USER','owner_id'=>(string)$u->getKey(),'type'=>'WALLET_USUARIO',
        'currency'=>'MXN','status'=>'ACTIVA','available_balance_cents'=>10000,'held_balance_cents'=>0]);
    return [$u,$w];
}
function mckHeaders(array $scopes=['financial:purchase:prepare','financial:purchase:read'],?string $key=null):array {
    $token=app(OAuthTokenService::class)->issue('svc_checkout_fixture','fixture-password',$scopes);
    return ['Authorization'=>'Bearer '.$token['access_token'],'Idempotency-Key'=>$key??'checkout-'.Str::uuid()];
}
function mckData(User $u):array {return ['buyer_id'=>(string)$u->getKey(),'business_id'=>'BUS-CHECKOUT','order_id'=>'ORDER-'.Str::uuid(),
    'amount_cents'=>2500,'currency'=>'MXN','description'=>'Compra de prueba','items'=>[]];}
function mckPrepare($test,User $u):string {
    return $test->postJson('/api/v1/financial/marketplace/payment-requests',mckData($u),mckHeaders())->assertCreated()->json('data.payment_request_id');
}
function mckSession($test,User $u,bool $recent=true):UserSession {
    $identitySession=UserSession::create(['user_id'=>(string)$u->getKey(),'device_id'=>'fixture-device','started_at'=>now(),'last_activity_at'=>now()]);
    // Simulate a new browser session and persist it before the next HTTP request.
    // withSession alone can be overwritten by data loaded from a previously saved cookie.
    $native=app('session')->driver();$native->invalidate();
    $native->put([
        \Illuminate\Support\Facades\Auth::guard('web')->getName()=>(string)$u->getKey(),
        'cd_session_id'=>(string)$identitySession->getKey(),
        'auth.password_confirmed_at'=>now()->timestamp-($recent?0:1000),
    ]);
    $native->save();
    $test->actingAs($u)->withCookie(config('session.cookie'),$native->getId());
    return $identitySession;
}
function mckBrowser($test,User $u,string $id,bool $recent=true):array {
    $session=mckSession($test,$u,$recent);$token=null;
    $test->get('/finanzas/compras/solicitudes/'.$id)->assertOk()->assertInertia(function(Assert $page)use(&$token){
        $page->component('Financial/MarketplaceCheckout')->where('paymentRequest.business_name','Negocio de pruebas')
            ->where('paymentRequest.amount_cents',2500)
            ->where('reviewToken',function($value)use(&$token){$token=$value;return is_string($value)&&strlen($value)===64;})->etc();
    });
    $test->withCookie(config('session.cookie'),app('session')->getId());
    return [$token,$session];
}
function mckConfirm($test,string $id,string $token){return $test->post('/finanzas/compras/solicitudes/'.$id.'/confirmar',['consent'=>true,'review_token'=>$token]);}

test('checkout service preparation never debits and rejects missing tokens scopes or unknown businesses',function(){
    [$u,$w]=mckUser();$d=mckData($u);$path='/api/v1/financial/marketplace/payment-requests';
    $this->postJson($path,$d)->assertUnauthorized();
    $this->postJson($path,$d,mckHeaders(['financial:purchase:read']))->assertForbidden();
    $this->postJson($path,[...$d,'business_id'=>'OTHER'],mckHeaders())->assertForbidden();
    $response=$this->postJson($path,$d,mckHeaders())->assertCreated()->assertJsonPath('data.status','PENDING')->assertJsonPath('data.payment_intent_id',null);
    expect($w->fresh()->available_balance_cents)->toBe(10000)->and(PurchasePayment::where('wallet_id',$w->public_id)->exists())->toBeFalse();
    expect($response->json('data.confirmation_path'))->toStartWith('/finanzas/compras/solicitudes/');Http::assertNothingSent();
});
test('checkout preparation replay preserves expiration and rejects changed data for the same key or order',function(){
    [$u]=mckUser();$d=mckData($u);$h=mckHeaders();$path='/api/v1/financial/marketplace/payment-requests';
    $first=$this->postJson($path,$d,$h)->assertCreated()->json('data');
    $again=$this->postJson($path,$d,$h)->assertOk()->json('data');expect($again)->toBe($first);
    $this->postJson($path,[...$d,'amount_cents'=>2600],$h)->assertConflict();
    $this->postJson($path,[...$d,'amount_cents'=>2600],mckHeaders())->assertConflict();
    expect($this->postJson($path,$d,mckHeaders())->assertOk()->json('data.payment_request_id'))->toBe($first['payment_request_id']);
});
test('checkout requires native owner session consent review token and recent password before charging',function(){
    [$u,$w]=mckUser();$id=mckPrepare($this,$u);$path='/finanzas/compras/solicitudes/'.$id.'/confirmar';
    $this->get('/finanzas/compras/solicitudes/'.$id)->assertRedirect(route('login'));
    [$token]=mckBrowser($this,$u,$id,false);mckConfirm($this,$id,$token)->assertSessionHasErrors('payment');
    [$token]=mckBrowser($this,$u,$id);
    $this->post($path,['consent'=>false,'review_token'=>$token])->assertSessionHasErrors('consent');
    mckConfirm($this,$id,str_repeat('0',64))->assertSessionHasErrors('payment');
    $this->postJson($path,['consent'=>true,'review_token'=>$token,'buyer_id'=>(string)$u->getKey()])->assertUnprocessable();
    expect($w->fresh()->available_balance_cents)->toBe(10000);
    mckConfirm($this,$id,$token)->assertRedirect()->assertSessionHasNoErrors();$row=MarketplacePaymentRequest::where('public_id',$id)->firstOrFail();
    expect($row->status)->toBe('PAID')->and($w->fresh()->available_balance_cents)->toBe(7500)
        ->and($row->audit_context['actor_id'])->toBe('user:'.(string)$u->getKey());
});
test('checkout repeated confirmation returns the same purchase without another debit or event',function(){
    [$u,$w]=mckUser();$id=mckPrepare($this,$u);[$token]=mckBrowser($this,$u,$id);
    mckConfirm($this,$id,$token)->assertRedirect()->assertSessionHasNoErrors();$row=MarketplacePaymentRequest::where('public_id',$id)->firstOrFail();
    mckConfirm($this,$id,$token)->assertRedirect();
    expect($w->fresh()->available_balance_cents)->toBe(7500)->and(PurchasePayment::where('wallet_id',$w->public_id)->count())->toBe(1);
    $stream=DB::connection('sqlsrv')->table('financial_team4_streams')->where('payment_intent_id',strtolower($row->purchase_payment_id))->value('id');
    expect(DB::connection('sqlsrv')->table('financial_team4_outbox')->where('stream_id',$stream)->count())->toBe(1);Http::assertNothingSent();
});
test('checkout foreign users cannot view confirm or cancel another buyers request',function(){
    [$u,$w]=mckUser();$id=mckPrepare($this,$u);[$token]=mckBrowser($this,$u,$id);[$other]=mckUser();
    mckSession($this,$other);
    $this->get('/finanzas/compras/solicitudes/'.$id)->assertNotFound();mckConfirm($this,$id,$token)->assertNotFound();
    $this->post('/finanzas/compras/solicitudes/'.$id.'/cancelar')->assertNotFound();
    expect($w->fresh()->available_balance_cents)->toBe(10000);
});
test('checkout revoked sessions and inactive accounts cannot confirm a prepared purchase',function(){
    [$u,$w]=mckUser();$id=mckPrepare($this,$u);[$token,$session]=mckBrowser($this,$u,$id);
    $session->revoked_at=now();$session->save();mckConfirm($this,$id,$token)->assertForbidden();
    [$token]=mckBrowser($this,$u,$id);$u->forceFill(['must_change_password'=>true])->save();mckConfirm($this,$id,$token)->assertForbidden();
    expect($w->fresh()->available_balance_cents)->toBe(10000);
});
test('checkout expiration and cancellation are terminal and never debit',function(){
    [$u,$w]=mckUser();$id=mckPrepare($this,$u);[$token]=mckBrowser($this,$u,$id);
    MarketplacePaymentRequest::where('public_id',$id)->update(['expires_at'=>now()->subSecond()]);
    mckConfirm($this,$id,$token)->assertSessionHasErrors('payment');
    $id2=mckPrepare($this,$u);[$token2]=mckBrowser($this,$u,$id2);
    $this->post('/finanzas/compras/solicitudes/'.$id2.'/cancelar')->assertRedirect();
    $this->post('/finanzas/compras/solicitudes/'.$id2.'/cancelar')->assertRedirect();
    mckConfirm($this,$id2,$token2)->assertSessionHasErrors('payment');expect($w->fresh()->available_balance_cents)->toBe(10000);
});
test('checkout revokes service permission after preparation and isolates partner lookup',function(){
    [$u,$w]=mckUser();$d=mckData($u);$id=$this->postJson('/api/v1/financial/marketplace/payment-requests',$d,mckHeaders())->assertCreated()->json('data.payment_request_id');
    $query='/api/v1/financial/marketplace/payment-requests/by-order?'.http_build_query(['order_id'=>$d['order_id'],'business_id'=>$d['business_id']]);
    $this->getJson($query,mckHeaders(['financial:purchase:read']))->assertOk()->assertJsonPath('data.status','PENDING');
    $this->getJson($query,mckHeaders(['financial:purchase:prepare']))->assertForbidden();
    [$token]=mckBrowser($this,$u,$id);$headers=mckHeaders(['financial:purchase:read']);
    ServiceClient::where('client_id','svc_checkout_fixture')->update(['active'=>false]);
    mckConfirm($this,$id,$token)->assertForbidden();$this->getJson($query,$headers)->assertForbidden();expect($w->fresh()->available_balance_cents)->toBe(10000);
});
test('checkout insufficient balance leaves request pending and same confirmation can retry',function(){
    [$u,$w]=mckUser();$w->update(['available_balance_cents'=>100]);$id=mckPrepare($this,$u);[$token]=mckBrowser($this,$u,$id);
    mckConfirm($this,$id,$token)->assertSessionHasErrors('payment');
    expect(MarketplacePaymentRequest::where('public_id',$id)->firstOrFail()->status)->toBe('PENDING')
        ->and(PurchasePayment::where('wallet_id',$w->public_id)->exists())->toBeFalse();
    $w->update(['available_balance_cents'=>10000]);mckConfirm($this,$id,$token)->assertRedirect();expect($w->fresh()->available_balance_cents)->toBe(7500);
});
test('checkout disabled integration blocks preparation and confirmation without changing balances',function(){
    [$u,$w]=mckUser();$id=mckPrepare($this,$u);[$token]=mckBrowser($this,$u,$id);config(['financial_marketplace.enabled'=>false]);
    $this->postJson('/api/v1/financial/marketplace/payment-requests',mckData($u),mckHeaders())->assertServiceUnavailable();
    mckConfirm($this,$id,$token)->assertServiceUnavailable();expect($w->fresh()->available_balance_cents)->toBe(10000);
});
test('checkout rejects floats forged identity selectors and tampered stored amounts',function(){
    [$u,$w]=mckUser();$d=mckData($u);$path='/api/v1/financial/marketplace/payment-requests';
    $this->postJson($path,[...$d,'amount_cents'=>25.5],mckHeaders())->assertUnprocessable();
    $this->postJson($path,[...$d,'wallet_id'=>$w->public_id],mckHeaders())->assertUnprocessable();
    $id=mckPrepare($this,$u);[$token]=mckBrowser($this,$u,$id);
    MarketplacePaymentRequest::where('public_id',$id)->update(['amount_cents'=>1]);
    mckConfirm($this,$id,$token)->assertSessionHasErrors('payment');expect($w->fresh()->available_balance_cents)->toBe(10000);
});
test('checkout partner lookup cannot read a different configured clients request',function(){
    [$u]=mckUser();$d=mckData($u);$this->postJson('/api/v1/financial/marketplace/payment-requests',$d,mckHeaders())->assertCreated();
    config(['financial_marketplace.clients.svc_other_fixture'=>['name'=>'Otro cliente','businesses'=>['BUS-CHECKOUT'=>'Negocio de pruebas']]]);
    ServiceClient::create(['client_id'=>'svc_other_fixture','name'=>'Other fixture','active'=>true,'secret_hash'=>Hash::make('fixture-password'),'scopes'=>['financial:purchase:read']]);
    $token=app(OAuthTokenService::class)->issue('svc_other_fixture','fixture-password',['financial:purchase:read']);
    $this->getJson('/api/v1/financial/marketplace/payment-requests/by-order?'.http_build_query(['order_id'=>$d['order_id'],'business_id'=>$d['business_id']]),
        ['Authorization'=>'Bearer '.$token['access_token']])->assertNotFound();
});
test('checkout final persistence failure rolls back purchase event and wallet debit together',function(){
    [$u,$w]=mckUser();$id=mckPrepare($this,$u);[$token]=mckBrowser($this,$u,$id);$this->withoutExceptionHandling();
    $dispatcher=MarketplacePaymentRequest::getEventDispatcher();MarketplacePaymentRequest::setEventDispatcher(clone $dispatcher);
    $purchaseId=null;
    MarketplacePaymentRequest::saving(function($row)use(&$purchaseId){if($row->status==='PAID'){$purchaseId=strtolower($row->purchase_payment_id);throw new RuntimeException('fixture checkout failure');}});
    try {expect(fn()=>mckConfirm($this,$id,$token))->toThrow(RuntimeException::class,'fixture checkout failure');}
    finally {MarketplacePaymentRequest::setEventDispatcher($dispatcher);}
    expect($w->fresh()->available_balance_cents)->toBe(10000)->and(MarketplacePaymentRequest::where('public_id',$id)->firstOrFail()->status)->toBe('PENDING')
        ->and(PurchasePayment::where('wallet_id',$w->public_id)->exists())->toBeFalse()
        ->and(FinancialTransaction::where('idempotency_key','marketplace-confirm:'.$id)->exists())->toBeFalse()
        ->and(DB::connection('sqlsrv')->table('financial_team4_streams')->where('payment_intent_id',$purchaseId)->exists())->toBeFalse();
});
