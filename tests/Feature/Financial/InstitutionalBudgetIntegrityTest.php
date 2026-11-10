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
    $this->bgiOperator = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->bgiRecipient = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->bgiActor = (string) $this->bgiOperator->getKey();
    $this->bgiAuthority = new class implements InstitutionalBudgetAuthorization {
        public bool $commit = true;
        public function allows(string $actorId, string $action, ?string $budgetId = null): bool { return $action !== 'budget.commit' || $this->commit; }
    };
    $this->bgiAccess = new class implements FinancialWebAuthorizer {
        public bool $issue = true;
        public function allows(string $userId, string $action, ?string $walletId = null): bool { return in_array($action,['bonus.issue','bonus.cancel'],true) && $this->issue; }
    };
    $this->bgiIssuer = new class implements BonusAuthorizationProvider {
        public bool $allowed = true; public bool $unavailable = false;
        public function canIssueBonus(string $issuerType, string $issuerId, BonusType $type): bool {
            if ($this->unavailable) throw new IdentityFinancialAuthorizationUnavailable('FIXTURE_UNAVAILABLE');
            return $this->allowed && $issuerType === 'USER' && $type === BonusType::BECA;
        }
    };
    app()->instance(InstitutionalBudgetAuthorization::class, $this->bgiAuthority);
    app()->instance(FinancialWebAuthorizer::class, $this->bgiAccess);
    app()->instance(BonusAuthorizationProvider::class, $this->bgiIssuer);
    $this->bgiService = app(InstitutionalBecaIssuanceService::class);
    $data = ['institution_reference' => 'fixture-institution', 'approval_reference' => 'approval-'.Str::uuid(),
        'payer_reference' => 'fixture-approved-payer', 'purpose' => 'Programa BECA', 'approved_amount_cents' => 10000,
        'currency' => 'MXN', 'reason' => 'Aprobacion de prueba', 'valid_from' => now()->subDay()->toISOString(), 'expires_at' => now()->addMonth()->toISOString()];
    $r = app(InstitutionalBudgetService::class)->approve($this->bgiActor, $data, 'approve-'.Str::uuid());
    $this->bgiBudget = InstitutionalBonusBudget::where('public_id', $r['operation']->budget_id)->firstOrFail();
    $this->bgiBuyer = (string)$this->bgiRecipient->getKey();
    $this->bgiWallet = app(\App\Domains\Financial\Services\WalletService::class)->create('USER',$this->bgiBuyer,\App\Domains\Financial\Enums\WalletType::USUARIO);
    $this->bgiConsent = new class implements \App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization {
        public bool $pay = true; public bool $refund = true; public bool $unavailable = false;
        public function allows(string $actorId,string $action,array $context): bool {
            if ($this->unavailable) throw new RuntimeException('fixture-consent-unavailable');
            return $action === 'purchase.pay' ? $this->pay && ($context['authorization_reference'] ?? '') === 'fixture-consent' : $this->refund;
        }
    };
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization::class,$this->bgiConsent);
    $this->bgiIssued = bgiIssue($this);
    $this->bgiMarket = ['business_id'=>'fixture-approved-business','order_id'=>'fixture-order-'.Str::uuid(),'items'=>[]];

    $this->bgiPurchase=bgiPay($this);
    // External payment follows the purchase; never weaken the production date check.
    $this->travel(1)->seconds();
    $this->bgiSettlementAuthority=new class implements \App\Domains\Financial\Contracts\InstitutionalSettlementAuthorization {
        public bool $allowed=true; public bool $unavailable=false;
        public function allows(string $actorId,string $action,array $context): bool {
            if ($this->unavailable) throw new RuntimeException('fixture-payment-verifier-unavailable');
            return $this->allowed && $action==='settlement.record' && ($context['recipient_reference'] ?? '')==='fixture-approved-recipient';
        }
    };
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalSettlementAuthorization::class,$this->bgiSettlementAuthority);

});
afterEach(function () {
    InstitutionalBudgetOperation::flushEventListeners(); BonusBudgetCommitment::flushEventListeners(); BonusLedgerEntry::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusObligation::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusRefund::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusSettlement::flushEventListeners();
    while (DB::connection('sqlsrv')->transactionLevel() > 0) DB::connection('sqlsrv')->rollBack();
});
function bgiIssuePayload($test): array
{
    return ['award_reference' => 'fixture-award-'.Str::uuid(), 'beneficiary_id' => (string) $test->bgiRecipient->getKey(), 'type' => 'BECA', 'amount_cents' => 3000,
        'valid_from' => now()->subMinute()->toISOString(), 'expires_at' => now()->addDay()->toISOString(),
        'combinable' => true, 'allows_partial_use' => true, 'reason' => 'Beca de prueba con presupuesto',
        'external_reference' => 'test-budget-issue-'.Str::uuid(), 'restrictions' => []];
}
function bgiIssue($test, ?array $data = null, ?string $key = null, int $version = 1): array
{
    return $test->bgiService->issue($test->bgiActor, $test->bgiBudget->public_id, $version,
        $data ?? bgiIssuePayload($test), $key ?? 'issue-'.Str::uuid());
}
function bgiState($test): array
{
    return [$test->bgiBudget->fresh()->toArray(), Bonus::count(), BonusLedgerEntry::count(), BonusAdministrativeOperation::count(),
        BonusBudgetCommitment::count(), InstitutionalBudgetOperation::count(),
        Wallet::orderBy('id')->get(['public_id','available_balance_cents','held_balance_cents'])->toArray()];
}


function bgiPay($test, mixed $amount = 1000, ?string $key = null, ?array $market = null, string $proof = 'fixture-consent'): array
{
    return app(\App\Domains\Financial\Services\PurchasePaymentService::class)->payWithInstitutionalBeca($test->bgiWallet,
        $test->bgiIssued['bonus_id'],$amount,$key ?? 'pay-'.Str::uuid(),$test->bgiBuyer,$proof,$market ?? $test->bgiMarket);
}
function bgiRefund($test, array $purchase, int $amount, bool $approve = true): \App\Domains\Financial\Models\PurchaseRefundRequest
{
    $service = app(\App\Domains\Financial\Services\PurchaseRefundService::class);
    $payment = \App\Domains\Financial\Models\PurchasePayment::where('public_id',$purchase['purchase_payment_id'])->firstOrFail();
    $request = $service->request($payment,0,[['bonus_id'=>$test->bgiIssued['bonus_id'],'amount_cents'=>$amount]],
        'request-'.Str::uuid(),$test->bgiBuyer,'Devolucion de prueba institucional');
    return $approve ? $service->approve($request,$test->bgiActor,'Revision de prueba') : $request;
}
function bgiComplete($test,$refund,?string $key = null) {
    return app(\App\Domains\Financial\Services\PurchaseRefundService::class)->complete($refund,$key ?? 'refund-'.Str::uuid(),$test->bgiActor);
}
function bgiSpendingState($test): array {
    return [bgiState($test),
        \App\Domains\Financial\Models\InstitutionalBonusObligation::orderBy('id')->get()->toArray(),
        \App\Domains\Financial\Models\InstitutionalBonusRefund::orderBy('id')->get()->toArray(),
        \App\Domains\Financial\Models\PurchasePayment::count(),\App\Domains\Financial\Models\FinancialTransaction::count(),
        \App\Domains\Financial\Models\LedgerEntry::count(),DB::connection('sqlsrv')->table('financial_team4_outbox')->orderBy('id')->get()->toArray()];
}


function bgiData(int $amount=600): array {
    return ['amount_cents'=>$amount,'currency'=>'MXN','payment_provider'=>'fixture-bank-account',
        'payment_reference'=>'confirmed-payment-'.Str::uuid(),'recipient_reference'=>'fixture-approved-recipient',
        'paid_at'=>now()->toISOString(),'reason'=>'Registro de pago confirmado en prueba'];
}
function bgiRecord($test,?array $data=null,?string $key=null,?int $version=null,?string $obligation=null): array {
    return app(\App\Domains\Financial\Services\InstitutionalSettlementService::class)->record($test->bgiActor,
        $obligation ?? $test->bgiPurchase['obligation_id'],$version ?? $test->bgiBudget->fresh()->version,$data ?? bgiData(),$key ?? 'settle-'.Str::uuid());
}
function bgiSettlementState($test): array {
    $snapshot=[];
    foreach(['institutional_bonus_budgets','institutional_budget_operations','bonus_budget_commitments',
        'bonuses','bonus_ledger_entries','bonus_administrative_operations','purchase_payments',
        'purchase_refund_requests','purchase_refund_bonuses','institutional_bonus_obligations',
        'institutional_bonus_refunds','institutional_bonus_settlements','wallets','financial_transactions',
        'ledger_entries','financial_team4_outbox','financial_team4_streams'] as $table) {
        $snapshot[$table]=DB::connection('sqlsrv')->table($table)->orderBy('id')->get()->toArray();
    }
    return $snapshot;
}
function bgiReport($test): array {
    return app(\App\Domains\Financial\Services\InstitutionalObligationQueryService::class)->report($test->bgiBudget->public_id);
}

function bgiAction($t,string $action,?string $key=null): mixed {
    $v=$t->bgiBudget->fresh()->version; $key??='integrity-'.Str::uuid();
    return match($action) {
        'adjust'=>app(InstitutionalBudgetService::class)->setApprovedAmount($t->bgiActor,$t->bgiBudget->public_id,$v,12000,'Ampliacion de prueba',$key),
        'suspend'=>app(InstitutionalBudgetService::class)->setSuspended($t->bgiActor,$t->bgiBudget->public_id,$v,true,'Revision de prueba',$key),
        'issue'=>bgiIssue($t,null,$key,$v),
        'pay'=>bgiPay($t,100,$key,[...$t->bgiMarket,'order_id'=>'new-order-'.Str::uuid()]),
        'refund'=>bgiComplete($t,$t->bgiPendingRefund,$key),
        'cancel'=>app(\App\Domains\Financial\Services\InstitutionalBecaReleaseService::class)->cancel($t->bgiActor,$t->bgiBudget->public_id,$t->bgiIssued['bonus_id'],$v,'Cancelacion de prueba',$key),
        'expire'=>app(\App\Domains\Financial\Services\InstitutionalBecaReleaseService::class)->expire($t->bgiActor,$t->bgiBudget->public_id,$t->bgiIssued['bonus_id'],$v,'Vencimiento de prueba',$key),
        'settle'=>bgiRecord($t,null,$key,$v),
    };
}
function bgiCorrupt($t,string $kind): void {
    $db=DB::connection('sqlsrv');
    match($kind) {
        'committed'=>$db->table('institutional_bonus_budgets')->where('public_id',$t->bgiBudget->public_id)->update(['committed_amount_cents'=>3000]),
        'consumed'=>$db->table('institutional_bonus_budgets')->where('public_id',$t->bgiBudget->public_id)->update(['consumed_amount_cents'=>900]),
        'obligation'=>$db->table('institutional_bonus_obligations')->where('public_id',$t->bgiPurchase['obligation_id'])->update(['refunded_amount_cents'=>1]),
        'marker'=>$db->table('bonuses')->where('public_id',$t->bgiIssued['bonus_id'])->update(['institutional_budget_id'=>null]),
    };
}

test('budget integrity blocks every writer on inconsistent whole budget without repairing evidence',function(string $action,string $kind){
    if($action==='refund')$this->bgiPendingRefund=bgiRefund($this,$this->bgiPurchase,100);
    if($action==='expire')$this->travelTo(Bonus::where('public_id',$this->bgiIssued['bonus_id'])->firstOrFail()->expires_at);
    bgiCorrupt($this,$kind); $before=bgiSettlementState($this);
    expect(fn()=>bgiAction($this,$action))->toThrow(ConflictHttpException::class);
    expect(bgiSettlementState($this))->toEqual($before);
    Http::assertNothingSent();
})->with(['adjust','suspend','issue','pay','refund','cancel','expire','settle'])->with(['committed','consumed','obligation','marker']);

test('budget integrity valid reduction uses real issued and consumed exposure',function(){
    $v=$this->bgiBudget->fresh()->version;
    expect(fn()=>app(InstitutionalBudgetService::class)->setApprovedAmount($this->bgiActor,$this->bgiBudget->public_id,$v,2999,'Reduccion insuficiente','reduce-'.Str::uuid()))->toThrow(ConflictHttpException::class);
    app(InstitutionalBudgetService::class)->setApprovedAmount($this->bgiActor,$this->bgiBudget->public_id,$v,3000,'Reduccion valida','reduce-'.Str::uuid());
    $b=$this->bgiBudget->fresh();
    expect($b->approved_amount_cents)->toBe(3000)->and($b->committed_amount_cents)->toBe(2000)->and($b->consumed_amount_cents)->toBe(1000)
        ->and(bgiReport($this)['consistent'])->toBeTrue();
});

test('budget integrity detects another bonus evidence while aggregate counters still match',function(){
    $other=bgiIssue($this,null,null,$this->bgiBudget->fresh()->version);
    DB::connection('sqlsrv')->table('bonus_ledger_entries')->where('bonus_id',$other['bonus_id'])->update(['remaining_after_cents'=>2999]);
    $before=bgiSettlementState($this);
    expect(fn()=>bgiAction($this,'pay'))->toThrow(ConflictHttpException::class);
    expect(bgiSettlementState($this))->toEqual($before);
});

test('budget integrity refuses clean target when a different obligation is damaged',function(){
    $second=bgiPay($this,100,null,[...$this->bgiMarket,'order_id'=>'another-'.Str::uuid()]);
    DB::connection('sqlsrv')->table('institutional_bonus_obligations')->where('public_id',$second['obligation_id'])->update(['refunded_amount_cents'=>1]);
    $before=bgiSettlementState($this);
    expect(fn()=>bgiRecord($this))->toThrow(ConflictHttpException::class);
    expect(bgiSettlementState($this))->toEqual($before);
});

test('budget integrity rechecks successful replay after later corruption',function(string $action){
    $key='replay-'.Str::uuid();
    if($action==='refund')$this->bgiPendingRefund=bgiRefund($this,$this->bgiPurchase,100);
    // Keep exact payload and review version for replay, including payment proof/reference.
    if($action==='settle') {
        $data=bgiData();$v=$this->bgiBudget->fresh()->version;
        bgiRecord($this,$data,$key,$v);
        $retry=fn()=>bgiRecord($this,$data,$key,$v);
    } elseif($action==='issue') {
        $data=bgiIssuePayload($this);$v=$this->bgiBudget->fresh()->version;
        bgiIssue($this,$data,$key,$v);$retry=fn()=>bgiIssue($this,$data,$key,$v);
    } elseif($action==='adjust') {
        $v=$this->bgiBudget->fresh()->version;
        $svc=app(InstitutionalBudgetService::class);
        $retry=fn()=>$svc->setApprovedAmount($this->bgiActor,$this->bgiBudget->public_id,$v,12000,'Ajuste de prueba',$key);
        $retry();
    } elseif($action==='suspend') {
        $v=$this->bgiBudget->fresh()->version;$svc=app(InstitutionalBudgetService::class);
        $retry=fn()=>$svc->setSuspended($this->bgiActor,$this->bgiBudget->public_id,$v,true,'Revision de prueba',$key);$retry();
    } elseif(in_array($action,['cancel','expire'],true)) {
        if($action==='expire')$this->travelTo(Bonus::where('public_id',$this->bgiIssued['bonus_id'])->firstOrFail()->expires_at);
        $v=$this->bgiBudget->fresh()->version;$svc=app(\App\Domains\Financial\Services\InstitutionalBecaReleaseService::class);
        $retry=fn()=>$svc->{$action}($this->bgiActor,$this->bgiBudget->public_id,$this->bgiIssued['bonus_id'],$v,'Liberacion de prueba',$key);$retry();
    } elseif($action==='pay') {
        $market=[...$this->bgiMarket,'order_id'=>'replay-order-'.Str::uuid()];
        $retry=fn()=>bgiPay($this,100,$key,$market);$retry();
    } else {
        $retry=fn()=>bgiComplete($this,$this->bgiPendingRefund,$key);$retry();
    }
    DB::connection('sqlsrv')->table('institutional_bonus_budgets')->where('public_id',$this->bgiBudget->public_id)->increment('committed_amount_cents');
    $before=bgiSettlementState($this);
    expect($retry)->toThrow(ConflictHttpException::class);
    expect(bgiSettlementState($this))->toEqual($before);
})->with(['adjust','suspend','issue','pay','refund','cancel','expire','settle']);

test('budget integrity dependency failure refuses writes without financial mutations',function(){
    $before=bgiSettlementState($this);
    DB::connection('sqlsrv')->beforeExecuting(function($sql){if(str_contains($sql,'COUNT_BIG(*)'))throw new RuntimeException('fixture-integrity-query-unavailable');});
    expect(fn()=>bgiAction($this,'pay'))->toThrow(RuntimeException::class);
    expect(bgiSettlementState($this))->toEqual($before);
});

test('budget integrity postcondition failure rolls back a complete issuance and permits retry',function(){
    $data=bgiIssuePayload($this);$v=$this->bgiBudget->fresh()->version;$key='issue-'.Str::uuid();$before=bgiSettlementState($this);
    BonusBudgetCommitment::created(function($row){DB::connection('sqlsrv')->table('institutional_bonus_budgets')->where('public_id',$row->budget_id)->increment('committed_amount_cents');});
    try{expect(fn()=>bgiIssue($this,$data,$key,$v))->toThrow(ConflictHttpException::class);}
    finally{BonusBudgetCommitment::flushEventListeners();}
    expect(bgiSettlementState($this))->toEqual($before);
    expect(bgiIssue($this,$data,$key,$v)['replayed'])->toBeFalse();
});

test('budget integrity clean independent budget remains operational',function(){
    bgiCorrupt($this,'committed');$damaged=$this->bgiBudget->fresh()->toArray();
    $data=['institution_reference'=>'independent','approval_reference'=>'approval-'.Str::uuid(),'payer_reference'=>'approved-payer',
        'purpose'=>'Otro programa','approved_amount_cents'=>5000,'currency'=>'MXN','reason'=>'Prueba aislada',
        'valid_from'=>now()->subDay()->toISOString(),'expires_at'=>now()->addMonth()->toISOString()];
    $r=app(InstitutionalBudgetService::class)->approve($this->bgiActor,$data,'approve-'.Str::uuid());
    $result=app(InstitutionalBudgetService::class)->setApprovedAmount($this->bgiActor,$r['operation']->budget_id,1,6000,'Ampliacion','adjust-'.Str::uuid());
    expect($result['replayed'])->toBeFalse()->and($this->bgiBudget->fresh()->toArray())->toEqual($damaged);
});


test('budget integrity approval replay also rechecks current evidence',function(){
    $data=['institution_reference'=>'approval-replay','approval_reference'=>'approval-'.Str::uuid(),'payer_reference'=>'approved-payer',
        'purpose'=>'Programa','approved_amount_cents'=>5000,'currency'=>'MXN','reason'=>'Prueba aislada',
        'valid_from'=>now()->subDay()->toISOString(),'expires_at'=>now()->addMonth()->toISOString()];
    $key='approve-'.Str::uuid();$svc=app(InstitutionalBudgetService::class);$r=$svc->approve($this->bgiActor,$data,$key);
    DB::connection('sqlsrv')->table('institutional_bonus_budgets')->where('public_id',$r['operation']->budget_id)->update(['committed_amount_cents'=>1]);
    $before=InstitutionalBudgetOperation::count();
    expect(fn()=>$svc->approve($this->bgiActor,$data,$key))->toThrow(ConflictHttpException::class);
    expect(InstitutionalBudgetOperation::count())->toBe($before);
});
