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
    $this->stlOperator = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->stlRecipient = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->stlActor = (string) $this->stlOperator->getKey();
    $this->stlAuthority = new class implements InstitutionalBudgetAuthorization {
        public bool $commit = true;
        public function allows(string $actorId, string $action, ?string $budgetId = null): bool { return $action !== 'budget.commit' || $this->commit; }
    };
    $this->stlAccess = new class implements FinancialWebAuthorizer {
        public bool $issue = true;
        public function allows(string $userId, string $action, ?string $walletId = null): bool { return in_array($action,['bonus.issue','bonus.cancel'],true) && $this->issue; }
    };
    $this->stlIssuer = new class implements BonusAuthorizationProvider {
        public bool $allowed = true; public bool $unavailable = false;
        public function canIssueBonus(string $issuerType, string $issuerId, BonusType $type): bool {
            if ($this->unavailable) throw new IdentityFinancialAuthorizationUnavailable('FIXTURE_UNAVAILABLE');
            return $this->allowed && $issuerType === 'USER' && $type === BonusType::BECA;
        }
    };
    app()->instance(InstitutionalBudgetAuthorization::class, $this->stlAuthority);
    app()->instance(FinancialWebAuthorizer::class, $this->stlAccess);
    app()->instance(BonusAuthorizationProvider::class, $this->stlIssuer);
    $this->stlService = app(InstitutionalBecaIssuanceService::class);
    $data = ['institution_reference' => 'fixture-institution', 'approval_reference' => 'approval-'.Str::uuid(),
        'payer_reference' => 'fixture-approved-payer', 'purpose' => 'Programa BECA', 'approved_amount_cents' => 10000,
        'currency' => 'MXN', 'reason' => 'Aprobacion de prueba', 'valid_from' => now()->subDay()->toISOString(), 'expires_at' => now()->addMonth()->toISOString()];
    $r = app(InstitutionalBudgetService::class)->approve($this->stlActor, $data, 'approve-'.Str::uuid());
    $this->stlBudget = InstitutionalBonusBudget::where('public_id', $r['operation']->budget_id)->firstOrFail();
    $this->stlBuyer = (string)$this->stlRecipient->getKey();
    $this->stlWallet = app(\App\Domains\Financial\Services\WalletService::class)->create('USER',$this->stlBuyer,\App\Domains\Financial\Enums\WalletType::USUARIO);
    $this->stlConsent = new class implements \App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization {
        public bool $pay = true; public bool $refund = true; public bool $unavailable = false;
        public function allows(string $actorId,string $action,array $context): bool {
            if ($this->unavailable) throw new RuntimeException('fixture-consent-unavailable');
            return $action === 'purchase.pay' ? $this->pay && ($context['authorization_reference'] ?? '') === 'fixture-consent' : $this->refund;
        }
    };
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization::class,$this->stlConsent);
    $this->stlIssued = stlIssue($this);
    $this->stlMarket = ['business_id'=>'fixture-approved-business','order_id'=>'fixture-order-'.Str::uuid(),'items'=>[]];

    $this->stlPurchase=stlPay($this);
    // External payment follows the purchase; never weaken the production date check.
    $this->travel(1)->seconds();
    $this->stlSettlementAuthority=new class implements \App\Domains\Financial\Contracts\InstitutionalSettlementAuthorization {
        public bool $allowed=true; public bool $unavailable=false;
        public function allows(string $actorId,string $action,array $context): bool {
            if ($this->unavailable) throw new RuntimeException('fixture-payment-verifier-unavailable');
            return $this->allowed && $action==='settlement.record' && ($context['recipient_reference'] ?? '')==='fixture-approved-recipient';
        }
    };
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalSettlementAuthorization::class,$this->stlSettlementAuthority);

});
afterEach(function () {
    InstitutionalBudgetOperation::flushEventListeners(); BonusBudgetCommitment::flushEventListeners(); BonusLedgerEntry::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusObligation::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusRefund::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusSettlement::flushEventListeners();
    while (DB::connection('sqlsrv')->transactionLevel() > 0) DB::connection('sqlsrv')->rollBack();
});
function stlIssuePayload($test): array
{
    return ['award_reference' => 'fixture-award-'.Str::uuid(), 'beneficiary_id' => (string) $test->stlRecipient->getKey(), 'type' => 'BECA', 'amount_cents' => 3000,
        'valid_from' => now()->subMinute()->toISOString(), 'expires_at' => now()->addDay()->toISOString(),
        'combinable' => true, 'allows_partial_use' => true, 'reason' => 'Beca de prueba con presupuesto',
        'external_reference' => 'test-budget-issue-'.Str::uuid(), 'restrictions' => []];
}
function stlIssue($test, ?array $data = null, ?string $key = null, int $version = 1): array
{
    return $test->stlService->issue($test->stlActor, $test->stlBudget->public_id, $version,
        $data ?? stlIssuePayload($test), $key ?? 'issue-'.Str::uuid());
}
function stlState($test): array
{
    return [$test->stlBudget->fresh()->toArray(), Bonus::count(), BonusLedgerEntry::count(), BonusAdministrativeOperation::count(),
        BonusBudgetCommitment::count(), InstitutionalBudgetOperation::count(),
        Wallet::orderBy('id')->get(['public_id','available_balance_cents','held_balance_cents'])->toArray()];
}


function stlPay($test, mixed $amount = 1000, ?string $key = null, ?array $market = null, string $proof = 'fixture-consent'): array
{
    return app(\App\Domains\Financial\Services\PurchasePaymentService::class)->payWithInstitutionalBeca($test->stlWallet,
        $test->stlIssued['bonus_id'],$amount,$key ?? 'pay-'.Str::uuid(),$test->stlBuyer,$proof,$market ?? $test->stlMarket);
}
function stlRefund($test, array $purchase, int $amount, bool $approve = true): \App\Domains\Financial\Models\PurchaseRefundRequest
{
    $service = app(\App\Domains\Financial\Services\PurchaseRefundService::class);
    $payment = \App\Domains\Financial\Models\PurchasePayment::where('public_id',$purchase['purchase_payment_id'])->firstOrFail();
    $request = $service->request($payment,0,[['bonus_id'=>$test->stlIssued['bonus_id'],'amount_cents'=>$amount]],
        'request-'.Str::uuid(),$test->stlBuyer,'Devolucion de prueba institucional');
    return $approve ? $service->approve($request,$test->stlActor,'Revision de prueba') : $request;
}
function stlComplete($test,$refund,?string $key = null) {
    return app(\App\Domains\Financial\Services\PurchaseRefundService::class)->complete($refund,$key ?? 'refund-'.Str::uuid(),$test->stlActor);
}
function stlSpendingState($test): array {
    return [stlState($test),
        \App\Domains\Financial\Models\InstitutionalBonusObligation::orderBy('id')->get()->toArray(),
        \App\Domains\Financial\Models\InstitutionalBonusRefund::orderBy('id')->get()->toArray(),
        \App\Domains\Financial\Models\PurchasePayment::count(),\App\Domains\Financial\Models\FinancialTransaction::count(),
        \App\Domains\Financial\Models\LedgerEntry::count(),DB::connection('sqlsrv')->table('financial_team4_outbox')->orderBy('id')->get()->toArray()];
}


function stlData(int $amount=600): array {
    return ['amount_cents'=>$amount,'currency'=>'MXN','payment_provider'=>'fixture-bank-account',
        'payment_reference'=>'confirmed-payment-'.Str::uuid(),'recipient_reference'=>'fixture-approved-recipient',
        'paid_at'=>now()->toISOString(),'reason'=>'Registro de pago confirmado en prueba'];
}
function stlRecord($test,?array $data=null,?string $key=null,?int $version=null,?string $obligation=null): array {
    return app(\App\Domains\Financial\Services\InstitutionalSettlementService::class)->record($test->stlActor,
        $obligation ?? $test->stlPurchase['obligation_id'],$version ?? $test->stlBudget->fresh()->version,$data ?? stlData(),$key ?? 'settle-'.Str::uuid());
}
function stlSettlementState($test): array {
    return [stlSpendingState($test),\App\Domains\Financial\Models\InstitutionalBonusSettlement::orderBy('id')->get()->toArray()];
}
function stlReport($test): array {
    return app(\App\Domains\Financial\Services\InstitutionalObligationQueryService::class)->report($test->stlBudget->public_id);
}

test('institutional settlement records a partial external payment without changing cash bonus consumption or outbox',function () {
    $before=$this->stlBudget->fresh(); $bonus=Bonus::where('public_id',$this->stlIssued['bonus_id'])->firstOrFail()->toArray();
    $wallet=$this->stlWallet->fresh()->toArray(); $entries=BonusLedgerEntry::count();
    $events=DB::connection('sqlsrv')->table('financial_team4_outbox')->count();
    $r=stlRecord($this); $debt=\App\Domains\Financial\Models\InstitutionalBonusObligation::where('public_id',$this->stlPurchase['obligation_id'])->firstOrFail();
    expect($r['replayed'])->toBeFalse()->and($debt->settled_amount_cents)->toBe(600)
        ->and($this->stlBudget->fresh()->consumed_amount_cents)->toBe($before->consumed_amount_cents)
        ->and($this->stlBudget->fresh()->committed_amount_cents)->toBe($before->committed_amount_cents)
        ->and($this->stlBudget->fresh()->version)->toBe(4)
        ->and(Bonus::where('public_id',$this->stlIssued['bonus_id'])->firstOrFail()->toArray())->toBe($bonus)
        ->and($this->stlWallet->fresh()->toArray())->toBe($wallet)->and(BonusLedgerEntry::count())->toBe($entries)
        ->and(DB::connection('sqlsrv')->table('financial_team4_outbox')->count())->toBe($events);
    Http::assertNothingSent();
});

test('institutional settlement report separates consumed settled and remaining unpaid amounts',function () {
    stlRecord($this,stlData(1000)); $r=stlReport($this);
    expect($r['consistent'])->toBeTrue()->and($r['settlement_implemented'])->toBeTrue()
        ->and($r['budgets'][0]['consumed_amount_cents'])->toBe('1000')
        ->and($r['entries'][0]['net_consumed_amount_cents'])->toBe('1000')
        ->and($r['entries'][0]['settled_amount_cents'])->toBe('1000')->and($r['entries'][0]['pending_amount_cents'])->toBe('0');
    $this->artisan('financial:institutional-obligations',['--budget'=>$this->stlBudget->public_id,'--settlements'=>true])->assertExitCode(0);
});

test('institutional settlement replay returns original record after later payments without applying money twice',function () {
    $data=stlData(600); $key='settle-'.Str::uuid(); $a=stlRecord($this,$data,$key,3);
    stlRecord($this,stlData(400)); $before=stlSettlementState($this);
    $b=stlRecord($this,[...$data,'payment_provider'=>strtoupper($data['payment_provider']),'payment_reference'=>strtoupper($data['payment_reference'])],strtoupper($key),3);
    expect($b['settlement_id'])->toBe($a['settlement_id'])->and($b['replayed'])->toBeTrue();
    expect(stlSettlementState($this))->toEqual($before);
});

test('institutional settlement prevents reuse of the same external payment with another key or obligation',function () {
    $data=stlData(500); stlRecord($this,$data); $before=stlSettlementState($this);
    expect(fn()=>stlRecord($this,$data))->toThrow(ConflictHttpException::class);
    expect(stlSettlementState($this))->toEqual($before);
    $other=stlPay($this,1000,null,[...$this->stlMarket,'order_id'=>'second-'.Str::uuid()]); $before=stlSettlementState($this);
    expect(fn()=>stlRecord($this,$data,null,null,$other['obligation_id']))->toThrow(ConflictHttpException::class);
    expect(stlSettlementState($this))->toEqual($before);
});

test('institutional settlement changed replay payload stale version and overpayment reject atomically',function () {
    $data=stlData(600); $key='settle-'.Str::uuid(); stlRecord($this,$data,$key,3); $before=stlSettlementState($this);
    expect(fn()=>stlRecord($this,[...$data,'amount_cents'=>601],$key,3))->toThrow(ConflictHttpException::class);
    expect(fn()=>stlRecord($this,stlData(1),null,3))->toThrow(ConflictHttpException::class);
    expect(fn()=>stlRecord($this,stlData(401)))->toThrow(ConflictHttpException::class);
    expect(stlSettlementState($this))->toEqual($before);
});

test('institutional settlement default authority and revoked actor authority prevent registration and replay',function () {
    $data=stlData(); $key='settle-'.Str::uuid(); stlRecord($this,$data,$key,3); $before=stlSettlementState($this);
    $this->stlSettlementAuthority->allowed=false;
    expect(fn()=>stlRecord($this,$data,$key,3))->toThrow(AuthorizationException::class);
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalSettlementAuthorization::class,
        new \App\Domains\Financial\Adapters\PendingInstitutionalSettlementAuthorization());
    expect(fn()=>stlRecord($this,stlData(1)))->toThrow(AuthorizationException::class);
    expect(stlSettlementState($this))->toEqual($before);
});

test('institutional settlement requires verified recipient and fails closed on unavailable verification',function () {
    $before=stlSettlementState($this);
    expect(fn()=>stlRecord($this,[...stlData(),'recipient_reference'=>'forged-recipient']))->toThrow(AuthorizationException::class);
    $this->stlSettlementAuthority->unavailable=true;
    expect(fn()=>stlRecord($this))->toThrow(RuntimeException::class);
    expect(stlSettlementState($this))->toEqual($before);
});

test('institutional settlement audit persistence failure rolls back debt counter and budget version and allows retry',function () {
    $data=stlData(); $key='settle-'.Str::uuid(); $before=stlSettlementState($this);
    InstitutionalBudgetOperation::creating(function($op){if($op->action==='SETTLE_BECA')throw new RuntimeException('fixture-settlement-audit');});
    try{expect(fn()=>stlRecord($this,$data,$key))->toThrow(RuntimeException::class);}finally{InstitutionalBudgetOperation::flushEventListeners();}
    expect(stlSettlementState($this))->toEqual($before); expect(stlRecord($this,$data,$key)['replayed'])->toBeFalse();
});

test('institutional settlement record persistence failure rolls back every change',function () {
    $before=stlSettlementState($this);
    \App\Domains\Financial\Models\InstitutionalBonusSettlement::creating(function(){throw new RuntimeException('fixture-settlement-record');});
    try{expect(fn()=>stlRecord($this))->toThrow(RuntimeException::class);}finally{\App\Domains\Financial\Models\InstitutionalBonusSettlement::flushEventListeners();}
    expect(stlSettlementState($this))->toEqual($before);
});

test('institutional settlement blocks pending and approved refund reservations until resolved',function () {
    $r=stlRefund($this,$this->stlPurchase,100,false); $before=stlSettlementState($this);
    expect(fn()=>stlRecord($this))->toThrow(ConflictHttpException::class); expect(stlSettlementState($this))->toEqual($before);
    app(\App\Domains\Financial\Services\PurchaseRefundService::class)->approve($r,$this->stlActor,'Revision');
    expect(fn()=>stlRecord($this))->toThrow(ConflictHttpException::class);
});

test('institutional settlement rejected refund no longer blocks recording an existing verified payment',function () {
    $r=stlRefund($this,$this->stlPurchase,100,false);
    app(\App\Domains\Financial\Services\PurchaseRefundService::class)->reject($r,$this->stlActor,'Rechazo de prueba');
    expect(stlRecord($this)['replayed'])->toBeFalse();
});

test('institutional settlement only permits refunds of the remaining unpaid portion',function () {
    stlRecord($this,stlData(600)); $before=stlSettlementState($this);
    expect(fn()=>stlRefund($this,$this->stlPurchase,401))->toThrow(InvalidArgumentException::class); expect(stlSettlementState($this))->toEqual($before);
    $r=stlRefund($this,$this->stlPurchase,400); stlComplete($this,$r); $report=stlReport($this);
    expect($report['consistent'])->toBeTrue()->and($report['entries'][0]['settled_amount_cents'])->toBe('600')
        ->and($report['entries'][0]['refunded_amount_cents'])->toBe('400')->and($report['entries'][0]['pending_amount_cents'])->toBe('0')
        ->and($this->stlBudget->fresh()->consumed_amount_cents)->toBe(600);
});

test('institutional settlement can pay an existing debt after cancellation suspension and budget expiration',function () {
    app(\App\Domains\Financial\Services\InstitutionalBecaReleaseService::class)->cancel($this->stlActor,$this->stlBudget->public_id,
        $this->stlIssued['bonus_id'],3,'Cancelar remanente','cancel-'.Str::uuid());
    $this->stlBudget->update(['status'=>'SUSPENDED']); $this->travelTo($this->stlBudget->expires_at);
    stlRecord($this,stlData(1000));
    expect(stlReport($this)['consistent'])->toBeTrue()->and($this->stlBudget->fresh()->consumed_amount_cents)->toBe(1000)
        ->and($this->stlBudget->fresh()->committed_amount_cents)->toBe(0);
});

test('institutional settlement validates actual integers dates and references before any settlement',function () {
    $before=stlSettlementState($this);
    foreach([0,-1,1.5,'100',true] as $amount)expect(fn()=>stlRecord($this,[...stlData(),'amount_cents'=>$amount]))->toThrow(InvalidArgumentException::class);
    expect(fn()=>stlRecord($this,[...stlData(),'currency'=>'USD']))->toThrow(InvalidArgumentException::class);
    expect(fn()=>stlRecord($this,[...stlData(),'paid_at'=>now()->addMinute()->toISOString()]))->toThrow(InvalidArgumentException::class);
    expect(fn()=>stlRecord($this,[...stlData(),'paid_at'=>'2026-02-30T00:00:00Z']))->toThrow(InvalidArgumentException::class);
    expect(fn()=>stlRecord($this,[...stlData(),'paid_at'=>now()->subDay()->toISOString()]))->toThrow(ConflictHttpException::class);
    expect(stlSettlementState($this))->toEqual($before);
});

test('institutional settlement detects a changed counter without matching immutable evidence',function () {
    DB::connection('sqlsrv')->table('institutional_bonus_obligations')->where('public_id',$this->stlPurchase['obligation_id'])->update(['settled_amount_cents'=>1]);
    $before=stlSettlementState($this); expect(fn()=>stlRecord($this))->toThrow(ConflictHttpException::class);
    expect(stlSettlementState($this))->toEqual($before);
    $r=stlReport($this); expect($r['consistent'])->toBeFalse()->and(array_column($r['budgets'][0]['findings'],'code'))->toContain('SETTLEMENT_COUNTER_VS_EVIDENCE');
});

test('institutional settlement payment reference corruption blocks further writes and reports evidence mismatch',function () {
    $r=stlRecord($this);
    DB::connection('sqlsrv')->table('institutional_bonus_settlements')->where('public_id',$r['settlement_id'])->update(['payment_reference'=>'altered-reference']);
    expect(fn()=>stlRecord($this,stlData(1)))->toThrow(ConflictHttpException::class);
    expect(array_column(stlReport($this)['budgets'][0]['findings'],'code'))->toContain('SETTLEMENT_EVIDENCE');
});

test('institutional settlement details list recorded payment references without recipient or human identities',function () {
    $data=stlData(); $r=stlRecord($this,$data);
    $report=app(\App\Domains\Financial\Services\InstitutionalObligationQueryService::class)->report($this->stlBudget->public_id,null,1,10,false,true);
    expect($report['entries_kind'])->toBe('SETTLEMENTS')->and($report['entries'][0]['settlement_id'])->toBe($r['settlement_id'])
        ->and($report['entries'][0]['payment_reference'])->toBe($data['payment_reference']);
    $json=json_encode($report,JSON_THROW_ON_ERROR); expect($json)->not->toContain($this->stlActor,$this->stlBuyer,'fixture-approved-recipient');
});

test('institutional settlement held request lock prevents a second application',function () {
    $key='settle-'.Str::uuid(); $lockKey='institution-budget:'.hash('sha256',$key);
    $locks=app(\App\Domains\Financial\Support\FinancialJobLock::class); $token=$locks->acquire($lockKey,180); $before=stlSettlementState($this);
    try{expect(fn()=>stlRecord($this,null,$key))->toThrow(ConflictHttpException::class);}finally{$locks->release($lockKey,$token);}
    expect(stlSettlementState($this))->toEqual($before);
});
