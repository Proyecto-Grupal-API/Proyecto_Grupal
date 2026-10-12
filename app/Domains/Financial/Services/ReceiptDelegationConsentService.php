<?php
namespace App\Domains\Financial\Services;
use App\Domains\Financial\Models\ReceiptUserDelegation;
use App\Domains\Identity\ReceiptDelegation\ReceiptSessionVerifier;
use App\Models\ServiceClient;
use App\Http\Middleware\ResolveReceiptDelegation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
class ReceiptDelegationConsentService {
    public function __construct(private WalletService $wallets,private PersonalReceiptQueryService $receipts,private ReceiptSessionVerifier $sessions){}
    public function clients():array {
        return ServiceClient::where('active',true)->limit(100)->get()->filter(function($c){$s=$c->scopes??[];$s=is_array($s)?$s:iterator_to_array($s);return in_array('financial:receipt:read',$s,true);})
            ->map(fn($c)=>['id'=>$c->client_id,'name'=>$c->name])->values()->all();
    }
    public function issue(Request $request,array $data):array {
        $this->sessions->assertSupportedDriver();
        $userId=(string)$request->user()->getKey();$identitySession=(string)$request->session()->get('cd_session_id','');
        $this->sessions->assertAccountSession($userId,$identitySession);
        $client=ServiceClient::where('client_id',$data['client_id'])->where('active',true)->first();
        $scopes=$client?->scopes??[];$scopes=is_array($scopes)?$scopes:iterator_to_array($scopes);
        if(!$client||!in_array('financial:receipt:read',$scopes,true))throw new AccessDeniedHttpException('Cliente no autorizado para comprobantes.');
        $source=$data['source'];$query=[];
        if($source==='list'){
            $path='/api/v1/financial/receipts';$action='receipt.list';
            $query=['page'=>(string)($data['page']??1),'per_page'=>(string)($data['per_page']??20)];
        }else{
            $wallet=$this->wallets->findByOwner('USER',$userId,\App\Domains\Financial\Enums\WalletType::USUARIO);abort_unless($wallet,404);
            $id=strtolower($data['operation_id']);
            $source==='transaction'?$this->receipts->transaction($wallet,$id):$this->receipts->purchase($wallet,$id);
            $path='/api/v1/financial/receipts/'.$source.'/'.$id;$action='receipt.read';
        }
        $url=$path.($query?'?'.http_build_query($query):'');$digest=ResolveReceiptDelegation::digest(Request::create($url,'GET'));
        $ttl=(int)config('financial_receipts.delegation_ttl_seconds',600);
        if($ttl<60||$ttl>3600)abort(503,'La vigencia de delegación está fuera de configuración admitida.');
        $proof='rcp_'.bin2hex(random_bytes(32));$expires=now()->addSeconds($ttl);
        $grant=ReceiptUserDelegation::create(['public_id'=>(string)Str::uuid(),'proof_hash'=>hash('sha256',$proof),
            'user_id'=>$userId,'client_id'=>$client->client_id,'client_name'=>$client->name,'identity_session_id'=>$identitySession,
            'native_session_id'=>$request->session()->getId(),'action'=>$action,'request_path'=>$path,'request_query'=>$query,
            'request_digest'=>$digest,'expires_at'=>$expires]);
        return ['id'=>strtolower($grant->public_id),'proof'=>$proof,'client_id'=>$client->client_id,'method'=>'GET','request_path'=>$url,'expires_at'=>$expires->toISOString()];
    }
    public function listing(string $userId):array {
        return ReceiptUserDelegation::where('user_id',$userId)->orderByDesc('id')->limit(50)->get()->map(fn($g)=>[
            'id'=>strtolower($g->public_id),'client_name'=>$g->client_name,'client_id'=>$g->client_id,'action'=>$g->action,
            'request_path'=>$g->request_path,'expires_at'=>$g->expires_at->toISOString(),'revoked'=>!!$g->revoked_at,
            'expired'=>$g->expires_at->lte(now()),'created_at'=>$g->created_at->toISOString()])->all();
    }
    public function revoke(string $userId,string $id):void {
        $grant=ReceiptUserDelegation::where('public_id',$id)->where('user_id',$userId)->firstOrFail();
        ReceiptUserDelegation::where('public_id',$grant->public_id)->whereNull('revoked_at')->update(['revoked_at'=>now()]);
    }
}
