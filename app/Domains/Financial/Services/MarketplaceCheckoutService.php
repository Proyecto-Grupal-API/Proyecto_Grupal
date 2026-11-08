<?php
namespace App\Domains\Financial\Services;
use App\Domains\Financial\Models\{MarketplacePaymentRequest,Wallet,Bonus,InstitutionalBonusBudget,PurchasePayment};
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Integrations\Team4\PaymentEventPayload;
use App\Models\{User,UserSession,ServiceClient};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Preparation by a configured merchant is not consent. Only a native browser confirmation can debit. */
class MarketplaceCheckoutService {
    public function enabled(): bool {return (bool)config('financial_marketplace.enabled',false);}
    private function enabledOrFail():void {abort_unless($this->enabled(),503,'La confirmacion de compras no esta habilitada.');}
    public function merchant(string $client,string $business,string $scope='financial:purchase:prepare'):array {
        $this->enabledOrFail();
        $clients=config('financial_marketplace.clients',[]);$entry=is_array($clients)?($clients[$client]??null):null;
        abort_unless(is_array($entry)&&is_string($entry['name']??null)&&$entry['name']!==''
            &&is_array($entry['businesses']??null)&&is_string($entry['businesses'][$business]??null)
            &&$entry['businesses'][$business]!=='',403,'Cliente o negocio no habilitado para compras.');
        $stored=ServiceClient::where('client_id',$client)->where('active',true)->first();
        $scopes=$stored?->scopes??[];$scopes=is_array($scopes)?$scopes:iterator_to_array($scopes);
        abort_unless($stored&&in_array($scope,$scopes,true),403,'El cliente ya no tiene permiso para esta operacion.');
        return ['client_name'=>$entry['name'],'business_name'=>$entry['businesses'][$business]];
    }
    private function normalize(array $d):array {
        abort_unless(isset($d['order_id'],$d['business_id'],$d['amount_cents'],$d['currency'])&&is_int($d['amount_cents'])&&$d['amount_cents']>0
            &&is_string($d['description']??'')&&mb_strlen($d['description']??'')<=200,422,'Datos de compra no validos.');
        abort_unless(is_string($d['buyer_id']??null)&&preg_match('/^[a-fA-F0-9]{24}$/',$d['buyer_id']),422,'Se requiere el ID canonico del comprador.');
        $canonical=PaymentEventPayload::canonical([
            'payment_intent_id'=>'prepare-validation','order_id'=>$d['order_id'],'business_id'=>$d['business_id'],
            'status'=>'PAID','amount_cents'=>$d['amount_cents'],'currency'=>$d['currency'],
            'occurred_at'=>'2026-01-01T00:00:00Z','items'=>$d['items']??[],
        ]);
        return ['buyer_id'=>strtolower($d['buyer_id']),'order_id'=>$canonical['order_id'],'business_id'=>$canonical['business_id'],
            'amount_cents'=>$canonical['amount_cents'],'currency'=>'MXN','items'=>$canonical['items'],'description'=>$d['description']??''];
    }
    private function digest(array $payload):string {return hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));}
    private function account(string $id):?User {
        $u=User::find($id);
        return $u&&!$u->deleted_at&&!$u->account_activation_pending&&!$u->must_change_password&&$u->hasVerifiedEmail()?$u:null;
    }
    public function prepare(string $client,string $key,array $input):MarketplacePaymentRequest {
        abort_unless(trim($key)!==''&&strlen($key)<=255,422,'Clave de idempotencia no valida.');
        $d=$this->normalize($input);$this->merchant($client,$d['business_id']);
        abort_unless($this->account($d['buyer_id']),422,'El comprador no es una cuenta habilitada en Finanzas.');
        $ttl=(int)config('financial_marketplace.ttl_seconds',600);abort_unless($ttl>=60&&$ttl<=1800,503,'Vigencia de compras no valida.');
        $hash=$this->digest($d);$keyHash=hash('sha256',json_encode([$client,$key],JSON_THROW_ON_ERROR));
        $origin=hash('sha256',json_encode([$client,$d['business_id'],$d['order_id']],JSON_THROW_ON_ERROR));
        return DB::connection('sqlsrv')->transaction(function()use($d,$hash,$keyHash,$origin,$client,$ttl){
            $existing=MarketplacePaymentRequest::where('key_hash',$keyHash)->first()
                ??MarketplacePaymentRequest::where('origin_key',$origin)->first();
            if($existing){if(!hash_equals($existing->request_hash,$hash))throw new ConflictHttpException('El pedido o la clave ya tienen otros datos.');return $existing;}
            $created=MarketplacePaymentRequest::create(['public_id'=>(string)Str::uuid(),'client_id'=>$client,
                'buyer_id'=>$d['buyer_id'],'business_id'=>$d['business_id'],'order_id'=>$d['order_id'],
                'origin_key'=>$origin,'key_hash'=>$keyHash,'request_hash'=>$hash,'payload'=>$d,
                'amount_cents'=>$d['amount_cents'],'currency'=>'MXN','expires_at'=>now()->addSeconds($ttl),'status'=>'PENDING']);
            // Return the persisted SQL representation, including datetime precision, on first response and replays.
            $created->refresh();$created->wasRecentlyCreated=true;return $created;
        });
    }
    public function status(MarketplacePaymentRequest $row):string {
        return $row->status==='PENDING'&&now()->gte($row->expires_at)?'EXPIRED':$row->status;
    }
    public function partnerResult(MarketplacePaymentRequest $row):array {
        return ['payment_request_id'=>strtolower($row->public_id),'order_id'=>$row->order_id,'business_id'=>$row->business_id,
            'status'=>$this->status($row),'amount_cents'=>$row->amount_cents,'currency'=>$row->currency,
            'payment_intent_id'=>$row->purchase_payment_id?strtolower($row->purchase_payment_id):null,
            'expires_at'=>$row->expires_at->toISOString(),
            'confirmation_path'=>'/finanzas/compras/solicitudes/'.strtolower($row->public_id)];
    }
    public function owned(Request $request,string $id):MarketplacePaymentRequest {
        return MarketplacePaymentRequest::where('public_id',$id)->where('buyer_id',(string)$request->user()->getKey())->firstOrFail();
    }
    public function session(Request $request):void {
        $this->enabledOrFail();$id=(string)$request->user()->getKey();
        abort_unless($this->account($id),403,'La cuenta no esta habilitada.');
        $sessionId=$request->session()->get('cd_session_id');
        abort_unless(is_string($sessionId)&&preg_match('/^[a-fA-F0-9]{24}$/',$sessionId),403,'Se requiere una sesion registrada en Identidad.');
        $session=UserSession::find($sessionId);
        abort_unless($session&&!$session->isRevoked()&&(string)$session->user_id===$id,403,'La sesion ya no esta vigente.');
    }
    public function passwordRecent(Request $request):bool {
        $max=(int)config('financial_marketplace.password_max_age_seconds',300);
        abort_unless($max>=60&&$max<=900,503,'Configuracion de confirmacion no valida.');
        $at=$request->session()->get('auth.password_confirmed_at');
        return is_int($at)&&$at<=now()->timestamp&&$at>=now()->timestamp-$max;
    }
    public function reviewToken(Request $request,MarketplacePaymentRequest $row):string {
        abort_unless(is_string(config('app.key'))&&strlen(config('app.key'))>=32,503,'Clave de aplicacion no valida.');
        return hash_hmac('sha256',strtolower($row->public_id).'|'.$row->request_hash.'|'.(string)$request->user()->getKey().'|'.$request->session()->getId().'|'.$request->session()->get('cd_session_id'),(string)config('app.key'));
    }
    public function becaEnabled():bool {return $this->enabled() && (bool)config('financial_marketplace.institutional_beca_enabled',false);}
    public function becaReviewToken(Request $r,MarketplacePaymentRequest $row,string $bonus,string $budget):string {
        return hash_hmac('sha256',$this->reviewToken($r,$row).'|BECA|'.strtolower($bonus).'|'.strtolower($budget),(string)config('app.key'));
    }
    public function becaOptions(Request $r,MarketplacePaymentRequest $row):array {
        if(!$this->becaEnabled() || $this->status($row)!=='PENDING')return [];
        try{app(InstitutionalBudgetReadAccess::class)->actor($r);}catch(\Illuminate\Auth\Access\AuthorizationException $e){return [];}
        $wallet=Wallet::where('owner_type','USER')->where('owner_id',(string)$r->user()->getKey())->where('type',WalletType::USUARIO->value)
            ->where('currency','MXN')->where('status','ACTIVA')->first();
        if(!$wallet)return [];
        $bonuses=Bonus::where('beneficiary_type','USER')->where('beneficiary_id',(string)$r->user()->getKey())->where('type','BECA')->where('currency','MXN')
            ->whereNotNull('institutional_budget_id')->whereIn('status',['ACTIVO','PENDIENTE'])->where('valid_from','<=',now())->where('expires_at','>',now())
            ->where('remaining_amount_cents','>=',$row->amount_cents)
            ->where(fn($q)=>$q->where('allows_partial_use',true)->orWhere('remaining_amount_cents',$row->amount_cents))
            ->whereDoesntHave('restrictions',fn($q)=>$q->where('restriction_type','CATEGORIA'))
            ->where(fn($q)=>$q->whereDoesntHave('restrictions',fn($r)=>$r->where('restriction_type','NEGOCIO'))
                ->orWhereHas('restrictions',fn($r)=>$r->where('restriction_type','NEGOCIO')->where('target_id',$row->business_id)))
            ->whereIn('institutional_budget_id',InstitutionalBonusBudget::select('public_id')->where('status','ACTIVE')->where('bonus_type','BECA')->where('currency','MXN')
                ->where('valid_from','<=',now())->where('expires_at','>',now())->where('committed_amount_cents','>=',$row->amount_cents))
            ->orderBy('expires_at')->orderBy('id')->limit(20)->get();
        return $bonuses->map(fn($bonus)=>['bonus_id'=>strtolower($bonus->public_id),'remaining_amount_cents'=>(string)$bonus->remaining_amount_cents,
            'expires_at'=>$bonus->expires_at->toISOString(),'funding_review_token'=>$this->becaReviewToken($r,$row,$bonus->public_id,$bonus->getRawOriginal('institutional_budget_id'))])->all();
    }
    public function confirm(Request $request,string $id,string $token,?string $bonusId=null,?string $fundingToken=null):MarketplacePaymentRequest {
        $this->session($request);
        if($bonusId!==null){abort_unless($this->becaEnabled(),503,'El pago con BECA no esta habilitado.');abort_unless(Str::isUuid($bonusId)&&is_string($fundingToken),422);$bonusId=strtolower($bonusId);}
        return DB::connection('sqlsrv')->transaction(function()use($request,$id,$token,$bonusId,$fundingToken){
            $row=MarketplacePaymentRequest::where('public_id',$id)->where('buyer_id',(string)$request->user()->getKey())->lockForUpdate()->firstOrFail();
            $this->merchant($row->client_id,$row->business_id);
            abort_unless(hash_equals($this->reviewToken($request,$row),$token),409,'Vuelve a abrir el detalle y revisa la compra.');
            abort_unless($this->passwordRecent($request),409,'Confirma tu contrasena antes de autorizar el pago.');
            $d=$this->normalize($row->payload);
            abort_unless(hash_equals($row->request_hash,$this->digest($d))&&$d['buyer_id']===$row->buyer_id
                &&$d['business_id']===$row->business_id&&$d['order_id']===$row->order_id
                &&$d['amount_cents']===$row->amount_cents&&$d['currency']===$row->currency,409,'Los datos de la solicitud requieren revision.');
            if($row->status==='PAID'){
                $audit=$row->audit_context??[];
                abort_unless(($audit['payment_method']??'WALLET')===($bonusId===null?'WALLET':'BECA'),409,'La compra ya se pago con otro medio.');
                if($bonusId!==null){
                    app(InstitutionalBudgetReadAccess::class)->actor($request);
                    abort_unless(($audit['bonus_id']??null)===$bonusId && is_string($audit['budget_id']??null)
                        && hash_equals($this->becaReviewToken($request,$row,$bonusId,$audit['budget_id']),$fundingToken),409,'El medio de pago requiere revision.');
                    $budget=InstitutionalBonusBudget::where('public_id',$audit['budget_id'])->lockForUpdate()->firstOrFail();
                    app(InstitutionalBudgetIntegrity::class)->assertState($budget);
                    $bonus=Bonus::where('public_id',$bonusId)->lockForUpdate()->firstOrFail();
                    app(InstitutionalBonusIntegrity::class)->assertState($budget,$bonus);
                    abort_unless($bonus->beneficiary_type==='USER' && strtolower($bonus->beneficiary_id)===$row->buyer_id,409);
                    $paid=PurchasePayment::where('public_id',$row->purchase_payment_id)->where('bonus_id',$bonusId)->where('total_amount_cents',$row->amount_cents)->where('business_id',$row->business_id)
                        ->where('idempotency_key','marketplace-confirm:'.strtolower($row->public_id))->first();
                    abort_unless($paid,409);
                    app(\App\Domains\Financial\Integrations\Team4\PurchasePaymentEvents::class)->paid($paid,['order_id'=>$d['order_id'],'business_id'=>$d['business_id'],'items'=>$d['items']],true);
                }
                return $row;
            }
            abort_unless($this->status($row)==='PENDING',409,'La solicitud ya no se puede pagar.');
            $wallet=Wallet::where('owner_type','USER')->where('owner_id',(string)$request->user()->getKey())
                ->where('type',WalletType::USUARIO->value)->where('currency',$d['currency']);
            // BECA service locks budget -> bonus -> wallet; never hold wallet before budget.
            $wallet=$bonusId===null?$wallet->lockForUpdate()->first():$wallet->first();
            abort_unless($wallet,409,'No tienes una wallet habilitada para esta moneda.');
            // Wallet path revalidates after its lock; BECA capability revalidates again after its own ordered locks.
            // SQL and Mongo do not share a distributed transaction.
            $this->session($request);$this->merchant($row->client_id,$row->business_id);
            abort_unless($this->status($row)==='PENDING'&&$this->passwordRecent($request),409,'La solicitud o la confirmacion de contrasena expiro.');
            $market=['order_id'=>$d['order_id'],'business_id'=>$d['business_id'],'items'=>$d['items']];$budgetId=null;
            if($bonusId===null){
                $result=app(PurchasePaymentService::class)->payWithWallet($wallet,$d['amount_cents'],'marketplace-confirm:'.strtolower($row->public_id),$d['business_id'],null,$market);
            }else{
                app(InstitutionalBudgetReadAccess::class)->actor($request);
                $bonus=Bonus::where('public_id',$bonusId)->where('beneficiary_type','USER')->where('beneficiary_id',$row->buyer_id)->first();
                $budgetId=$bonus?->getRawOriginal('institutional_budget_id');
                abort_unless($budgetId && hash_equals($this->becaReviewToken($request,$row,$bonusId,$budgetId),$fundingToken),409,'Vuelve a revisar la BECA seleccionada.');
                $budgetId=strtolower($budgetId);$proof='checkout-beca:'.$fundingToken;
                $expected=['wallet_id'=>strtolower($wallet->public_id),'buyer_id'=>$row->buyer_id,'bonus_id'=>$bonusId,
                    'amount_cents'=>$d['amount_cents'],'currency'=>'MXN','category_id'=>null,'authorization_reference'=>$proof,
                    'marketplace'=>$market,'budget_id'=>$budgetId];
                $result=app(InstitutionalCheckoutConsent::class)->within($request,$row,$expected,fn()=>app(PurchasePaymentService::class)->payWithInstitutionalBeca(
                    $wallet,$bonusId,$d['amount_cents'],'marketplace-confirm:'.strtolower($row->public_id),$row->buyer_id,$proof,$market));
            }
            $row->status='PAID';$row->purchase_payment_id=$result['purchase_payment_id'];$row->confirmed_at=now();
            $row->audit_context=['payment_method'=>$bonusId===null?'WALLET':'BECA','bonus_id'=>$bonusId,'budget_id'=>$budgetId,'channel'=>'WEB','actor_id'=>'user:'.$row->buyer_id,'client_id'=>$row->client_id,
                'identity_session_id'=>$request->session()->get('cd_session_id'),
                'native_session_hash'=>hash('sha256',$request->session()->getId()),'ip'=>$request->ip(),
                'password_confirmed_at'=>$request->session()->get('auth.password_confirmed_at')];$row->save();return $row;
        });
    }
    public function cancel(Request $request,string $id):MarketplacePaymentRequest {
        $this->session($request);
        return DB::connection('sqlsrv')->transaction(function()use($request,$id){
            $row=MarketplacePaymentRequest::where('public_id',$id)->where('buyer_id',(string)$request->user()->getKey())->lockForUpdate()->firstOrFail();
            if($row->status==='CANCELLED')return $row;
            abort_unless($this->status($row)==='PENDING',409,'La solicitud ya no se puede cancelar.');
            $row->status='CANCELLED';$row->save();return $row;
        });
    }
}
