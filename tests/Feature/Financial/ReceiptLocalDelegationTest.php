<?php
use App\Models\{User,UserSession,ServiceClient};
use App\Domains\Financial\Models\{Wallet,ReceiptUserDelegation};
use App\Domains\Financial\Services\{WalletService,LedgerService};
use App\Domains\Financial\Enums\{WalletType,MovementType};
use Illuminate\Support\Facades\{DB,Auth,Hash};
use Illuminate\Support\Str;

beforeEach(function(){
    if(!app()->environment('testing')||config('database.connections.sqlsrv.database')!=='campus_digital_financial_testing'
        ||config('database.connections.mongodb.database')!=='campus_virtual_testing')throw new RuntimeException('Solo bases de pruebas.');
    DB::connection('sqlsrv')->beginTransaction();$this->rd29Users=[];$this->withoutVite();
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
    $this->withHeader('Accept','application/json');
    config(['financial_receipts.delegation_provider'=>'local','financial_receipts.delegation_ttl_seconds'=>600]);
    $this->rd29Secret='fixture-secret-'.Str::uuid();$this->rd29Client=ServiceClient::create(['client_id'=>'rd29-'.Str::uuid(),
        'name'=>'Aplicación de prueba','active'=>true,'scopes'=>['financial:receipt:read'],'secret_hash'=>Hash::make($this->rd29Secret)]);
});
afterEach(function(){
    $this->travelBack();while(DB::connection('sqlsrv')->transactionLevel()>0)DB::connection('sqlsrv')->rollBack();
    foreach($this->rd29Users??[]as$id)User::where('_id',$id)->delete();
});
function rd29User($test):array {
    $u=User::factory()->create();$u->forceFill(['email_verified_at'=>now(),'account_activation_pending'=>false,'must_change_password'=>false])->save();
    $test->rd29Users[]=(string)$u->getKey();$w=app(WalletService::class)->create('USER',(string)$u->getKey(),WalletType::USUARIO);
    $t=app(LedgerService::class)->credit($w,1000,MovementType::RECARGA,'rd29-'.Str::uuid());return[$u,$w,$t];
}
function rd29Login($test,User $u):void {
    Auth::guard('web')->login($u);$test->actingAs($u);
    $session=UserSession::create(['user_id'=>(string)$u->getKey(),'started_at'=>now(),'last_activity_at'=>now(),'revoked_at'=>null]);
    $test->withSession(['cd_session_id'=>(string)$session->getKey()]);
}
function rd29Issue($test,User $u,array $overrides=[]):array {
    rd29Login($test,$u);
    $data=array_merge(['client_id'=>$test->rd29Client->client_id,'source'=>'list','page'=>1,'per_page'=>20,'consent'=>true],$overrides);
    $grant=$test->postJson('/finanzas/comprobantes-api',$data)->assertCreated()->json('data');
    $test->withCookie(session()->getName(),session()->getId());
    return$grant;
}
function rd29Headers($test,array $grant):array {
    $token=app(\App\Services\OAuthTokenService::class)->issue($test->rd29Client->client_id,$test->rd29Secret,['financial:receipt:read']);
    return['Authorization'=>'Bearer '.$token['access_token'],'X-User-Authorization'=>$grant['proof']];
}

test('local receipt delegation issues from a genuine session and real service token reads only owned receipts',function(){
    [$u,$w,$t]=rd29User($this);rd29User($this);$g=rd29Issue($this,$u);$headers=rd29Headers($this,$g);
    $before=$w->fresh()->available_balance_cents;
    $this->get($g['request_path'],$headers)->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',strtolower($t->public_id));
    $row=ReceiptUserDelegation::where('public_id',$g['id'])->firstOrFail();
    expect($row->proof_hash)->toBe(hash('sha256',$g['proof']))->and($w->fresh()->available_balance_cents)->toBe($before);
    $raw=DB::connection('sqlsrv')->table('receipt_user_delegations')->where('public_id',$g['id'])->first();
    expect($raw->native_session_id)->not->toBe($row->native_session_id);
});
test('receipt consent cannot select another owner and explicit agreement is required',function(){
    [$u,$w]=rd29User($this);[$other,$ow,$ot]=rd29User($this);rd29Login($this,$u);
    $base=['client_id'=>$this->rd29Client->client_id,'source'=>'list','consent'=>false];
    $this->postJson('/finanzas/comprobantes-api',$base)->assertUnprocessable();
    $this->postJson('/finanzas/comprobantes-api',array_merge($base,['consent'=>true,'user_id'=>(string)$other->getKey()]))->assertUnprocessable();
    $this->postJson('/finanzas/comprobantes-api',array_merge($base,['consent'=>true,'source'=>'transaction','operation_id'=>$ot->public_id]))->assertNotFound();
});
test('receipt local proof binds exact action path page and client',function(){
    [$u,$w,$t]=rd29User($this);$g=rd29Issue($this,$u);$h=rd29Headers($this,$g);
    $this->get('/api/v1/financial/receipts?page=2&per_page=20',$h)->assertUnauthorized();
    $this->get('/api/v1/financial/receipts/transaction/'.$t->public_id,$h)->assertUnauthorized();
    $this->get($g['request_path'],$h)->assertOk();
    $g2=rd29Issue($this,$u,['source'=>'transaction','operation_id'=>$t->public_id]);
    $this->get($g2['request_path'],rd29Headers($this,$g2))->assertOk()->assertJsonPath('data.id',strtolower($t->public_id));
});
test('receipt consent revocation and expiry stop the proof without financial side effects',function(){
    [$u,$w]=rd29User($this);$g=rd29Issue($this,$u);$h=rd29Headers($this,$g);
    $this->postJson('/finanzas/comprobantes-api/'.$g['id'].'/revoke')->assertOk();
    $this->get($g['request_path'],$h)->assertUnauthorized();
    $g=rd29Issue($this,$u);$h=rd29Headers($this,$g);
    ReceiptUserDelegation::where('public_id',$g['id'])->update(['expires_at'=>now()->subSecond()]);
    $this->get($g['request_path'],$h)->assertUnauthorized();expect($w->fresh()->available_balance_cents)->toBe(1000);
});
test('inactive service client account or revoked source session invalidates receipt delegation',function(){
    [$u]=rd29User($this);$g=rd29Issue($this,$u);$h=rd29Headers($this,$g);
    $this->rd29Client->update(['active'=>false]);$this->get($g['request_path'],$h)->assertUnauthorized();
    $this->rd29Client->update(['active'=>true]);$u->forceFill(['account_activation_pending'=>true])->save();$this->get($g['request_path'],$h)->assertUnauthorized();
    $u->forceFill(['account_activation_pending'=>false])->save();
    $row=ReceiptUserDelegation::where('public_id',$g['id'])->firstOrFail();UserSession::where('_id',$row->identity_session_id)->update(['revoked_at'=>now()]);
    $this->get($g['request_path'],$h)->assertUnauthorized();
});
test('logout destroys native authorization session and previous receipt proof is refused',function(){
    [$u]=rd29User($this);$g=rd29Issue($this,$u);$h=rd29Headers($this,$g);
    $this->post('/logout')->assertRedirect();$this->get($g['request_path'],$h)->assertUnauthorized();
});
test('receipt permission history never returns raw proof and another account cannot revoke it',function(){
    [$u]=rd29User($this);[$other]=rd29User($this);$g=rd29Issue($this,$u);
    $this->get('/finanzas/comprobantes-api/records')->assertOk()->assertJsonCount(1,'data')
        ->assertJsonMissingPath('data.0.proof')->assertJsonMissingPath('data.0.proof_hash')->assertJsonMissingPath('data.0.native_session_id');
    rd29Login($this,$other);$this->postJson('/finanzas/comprobantes-api/'.$g['id'].'/revoke')->assertNotFound();
});
