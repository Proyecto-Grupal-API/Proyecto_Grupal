<?php
use App\Domains\Financial\Contracts\ReceiptDelegationProvider;
use App\Domains\Financial\Adapters\PendingReceiptDelegationProvider;
use App\Domains\Financial\Models\{PurchasePayment, FinancialTransaction};
use App\Domains\Financial\Enums\{WalletType, MovementType};
use App\Domains\Financial\Services\{WalletService,LedgerService};
use App\Http\Middleware\ResolveReceiptDelegation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

beforeEach(function () {
    $this->withHeader('Accept', 'application/json');
    if (!app()->environment('testing') || config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing'
        || config('database.connections.mongodb.database') !== 'campus_virtual_testing') throw new RuntimeException('Solo bases de pruebas.');
    DB::connection('sqlsrv')->beginTransaction(); $this->ra29Users=[];
    $state=(object)['grants'=>[],'calls'=>0,'unavailable'=>false,'malformed'=>false];
    app()->instance('test.ra29-state',$state);
    app()->instance(ReceiptDelegationProvider::class,new class($state) implements ReceiptDelegationProvider {
        public function __construct(private object $state) {}
        public function resolve(string $clientId,string $proof,string $action,string $digest): string {
            ++$this->state->calls;
            if ($this->state->unavailable) throw new RuntimeException('private diagnostic detail');
            if ($this->state->malformed) return 'service:forged-user';
            $grant=$this->state->grants[$proof]??null;
            if (!$grant || $grant['client']!==$clientId || $grant['action']!==$action || $grant['digest']!==$digest)
                throw new UnauthorizedHttpException('Bearer','Delegación inválida.');
            return $grant['user'];
        }
    });
});
afterEach(function () {
    while(DB::connection('sqlsrv')->transactionLevel()>0) DB::connection('sqlsrv')->rollBack();
    foreach($this->ra29Users??[] as $id) User::where('_id',$id)->delete();
});
function ra29User($test,int $balance=1000): array {
    $u=User::factory()->create();$u->forceFill(['email_verified_at'=>now(),'account_activation_pending'=>false])->save();
    $test->ra29Users[]=(string)$u->getKey();$w=app(WalletService::class)->create('USER',(string)$u->getKey(),WalletType::USUARIO);
    $t=$balance?app(LedgerService::class)->credit($w,$balance,MovementType::RECARGA,'ra29-'.Str::uuid(),'TEST',(string)Str::uuid()):null;
    return [$u,$w,$t];
}
function ra29Headers(User $u,string $url,string $action='receipt.list',string $scope='financial:receipt:read',?string $client=null): array {
    $client??='ra29-client-'.Str::uuid();$proof='ra29-proof-'.Str::uuid();
    $request=Request::create($url,'GET');
    app('test.ra29-state')->grants[$proof]=['user'=>(string)$u->getKey(),'client'=>$client,'action'=>$action,'digest'=>ResolveReceiptDelegation::digest($request)];
    $key=(string)config('oauth.signing_key');if(str_starts_with($key,'base64:'))$key=base64_decode(substr($key,7));
    $token=\Firebase\JWT\JWT::encode(['iss'=>config('oauth.issuer'),'aud'=>config('oauth.audience'),'sub'=>$client,
        'scope'=>$scope,'iat'=>now()->timestamp,'exp'=>now()->addMinutes(5)->timestamp],$key,'HS256');
    return ['Authorization'=>'Bearer '.$token,'X-User-Authorization'=>$proof,'X-Correlation-Id'=>'ra29-correlation'];
}
function ra29Url(string $suffix=''): string {return '/api/v1/financial/receipts'.$suffix;}

test('receipt api requires service scope and separate user delegation before any query',function(){
    [$u]=ra29User($this);$url=ra29Url();
    $this->get($url)->assertUnauthorized();
    $this->get($url,ra29Headers($u,$url,'receipt.list','financial:read'))->assertForbidden();
    expect(app('test.ra29-state')->calls)->toBe(0);
    $headers=ra29Headers($u,$url);unset($headers['X-User-Authorization']);
    $this->get($url,$headers)->assertUnauthorized();
    $this->get($url,ra29Headers($u,$url))->assertOk()->assertJsonCount(1,'data')->assertJsonPath('meta.correlation_id','ra29-correlation');
});
test('receipt api list and detail only expose delegated users operations',function(){
    [$u,$w,$t]=ra29User($this);[$other,$ow,$ot]=ra29User($this);
    $url=ra29Url();$this->get($url,ra29Headers($u,$url))->assertOk()->assertJsonCount(1,'data')
        ->assertJsonPath('data.0.id',strtolower($t->public_id));
    $url=ra29Url('/transaction/'.$ot->public_id);$this->get($url,ra29Headers($u,$url,'receipt.read'))->assertNotFound();
    $url=ra29Url('/transaction/'.Str::uuid());$this->get($url,ra29Headers($u,$url,'receipt.read'))->assertNotFound();
});
test('receipt delegation binds action client path and canonical pagination query',function(){
    [$u]=ra29User($this);$url=ra29Url('?page=1&per_page=1');$headers=ra29Headers($u,$url);
    $this->get(ra29Url('?per_page=1&page=1'),$headers)->assertOk();
    $this->get(ra29Url('?page=2&per_page=1'),$headers)->assertUnauthorized();
    $this->get(ra29Url('/transaction/'.Str::uuid()),$headers)->assertUnauthorized();
    $this->get($url,ra29Headers($u,$url,'receipt.read'))->assertUnauthorized();
    $proof=$headers['X-User-Authorization'];app('test.ra29-state')->grants[$proof]['client']='another-client';
    $this->get($url,$headers)->assertUnauthorized();
});
test('receipt api rejects identity selectors bodies and invalid pagination before delegation',function(){
    [$u]=ra29User($this);$url=ra29Url();
    foreach(['user_id','wallet_id','owner_id','actor_id'] as $field){$bad=$url.'?'.$field.'=forged';$this->get($bad,ra29Headers($u,$bad))->assertUnprocessable();}
    foreach(['page=0','per_page=51','page=abc'] as $query){$bad=$url.'?'.$query;$this->get($bad,ra29Headers($u,$bad))->assertUnprocessable();}
    $this->json('GET',$url,['user_id'=>'forged'],ra29Headers($u,$url))->assertUnprocessable();
    expect(app('test.ra29-state')->calls)->toBe(0);
});
test('receipt api pending provider dependency failure and malformed identity fail closed without diagnostic leakage',function(){
    [$u]=ra29User($this);$url=ra29Url();$headers=ra29Headers($u,$url);
    app('test.ra29-state')->unavailable=true;
    $this->get($url,$headers)->assertStatus(503)->assertJsonPath('message','La autorización de comprobantes no está disponible.');
    app('test.ra29-state')->unavailable=false;app('test.ra29-state')->malformed=true;
    $this->get($url,$headers)->assertStatus(503);
    app()->instance(ReceiptDelegationProvider::class,new PendingReceiptDelegationProvider());
    $this->get($url,$headers)->assertStatus(503);
});
test('receipt api revocation is rechecked and repeated reads do not create financial evidence',function(){
    [$u,$w,$t]=ra29User($this);$url=ra29Url('/transaction/'.$t->public_id);$headers=ra29Headers($u,$url,'receipt.read');
    $before=[FinancialTransaction::count(),$w->fresh()->available_balance_cents];
    foreach(range(1,2)as $_)$this->get($url,$headers)->assertOk()->assertJsonCount(1,'data.entries')
        ->assertJsonMissingPath('data.entries.0.wallet_id')->assertJsonMissingPath('data.metadata');
    expect([FinancialTransaction::count(),$w->fresh()->available_balance_cents])->toBe($before);
    unset(app('test.ra29-state')->grants[$headers['X-User-Authorization']]);
    $this->get($url,$headers)->assertUnauthorized();
});
test('receipt api includes fully bonus funded purchases without requiring a wallet debit',function(){
    [$u,$w]=ra29User($this,0);$p=PurchasePayment::create(['public_id'=>(string)Str::uuid(),'idempotency_key'=>'ra29-'.Str::uuid(),
        'wallet_id'=>$w->public_id,'bonus_id'=>(string)Str::uuid(),'currency'=>'MXN','total_amount_cents'=>1000,
        'bonus_amount_cents'=>1000,'wallet_amount_cents'=>0,'status'=>'COMPLETADO']);
    $url=ra29Url('/purchase/'.$p->public_id);$this->get($url,ra29Headers($u,$url,'receipt.read'))->assertOk()
        ->assertJsonPath('data.purchase.bonus_amount_cents',1000)->assertJsonCount(0,'data.entries');
});
test('receipt api throttles before consulting identity and empty wallet does not get created',function(){
    [$u,$w]=ra29User($this,0);$w->delete();$url=ra29Url();$headers=ra29Headers($u,$url);
    foreach(range(1,30)as $_)$this->get($url,$headers)->assertOk()->assertJsonCount(0,'data');
    $calls=app('test.ra29-state')->calls;$this->get($url,$headers)->assertStatus(429);
    expect(app('test.ra29-state')->calls)->toBe($calls)
        ->and(\App\Domains\Financial\Models\Wallet::where('owner_id',(string)$u->getKey())->exists())->toBeFalse();
});
