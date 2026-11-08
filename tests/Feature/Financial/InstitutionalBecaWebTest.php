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
    $this->withoutVite(); $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
    $this->withoutMiddleware(\App\Http\Middleware\TrackDeviceSession::class);
    $this->bwfOperator = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->bwfRecipient = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->bwfActor = (string) $this->bwfOperator->getKey();
    $this->bwfOperator->forceFill(['two_factor_secret'=>encrypt('fixture-beca-secret'),'two_factor_confirmed_at'=>now()])->save();
    $this->bwfSession=\App\Models\UserSession::create(['user_id'=>$this->bwfActor,'started_at'=>now()]);
    $this->bwfAuthority = new class implements InstitutionalBudgetAuthorization {
        public bool $commit = true;
        public function allows(string $actorId, string $action, ?string $budgetId = null): bool { return $action !== 'budget.commit' || $this->commit; }
    };
    $this->bwfAccess = new class implements FinancialWebAuthorizer {
        public bool $issue = true;
        public function allows(string $userId, string $action, ?string $walletId = null): bool { return in_array($action,['bonus.issue','bonus.cancel'],true) && $this->issue; }
    };
    $this->bwfIssuer = new class implements BonusAuthorizationProvider {
        public bool $allowed = true; public bool $unavailable = false;
        public function canIssueBonus(string $issuerType, string $issuerId, BonusType $type): bool {
            if ($this->unavailable) throw new IdentityFinancialAuthorizationUnavailable('FIXTURE_UNAVAILABLE');
            return $this->allowed && $issuerType === 'USER' && $type === BonusType::BECA;
        }
    };
    app()->instance(InstitutionalBudgetAuthorization::class, $this->bwfAuthority);
    app()->instance(FinancialWebAuthorizer::class, $this->bwfAccess);
    app()->instance(BonusAuthorizationProvider::class, $this->bwfIssuer);
    $this->bwfService = app(InstitutionalBecaIssuanceService::class);
    $data = ['institution_reference' => 'fixture-institution', 'approval_reference' => 'approval-'.Str::uuid(),
        'payer_reference' => 'fixture-approved-payer', 'purpose' => 'Programa BECA', 'approved_amount_cents' => 10000,
        'currency' => 'MXN', 'reason' => 'Aprobacion de prueba', 'valid_from' => now()->subDay()->toISOString(), 'expires_at' => now()->addMonth()->toISOString()];
    $r = app(InstitutionalBudgetService::class)->approve($this->bwfActor, $data, 'approve-'.Str::uuid());
    $this->bwfBudget = InstitutionalBonusBudget::where('public_id', $r['operation']->budget_id)->firstOrFail();
    $this->bwfBuyer = (string)$this->bwfRecipient->getKey();
    $this->bwfWallet = app(\App\Domains\Financial\Services\WalletService::class)->create('USER',$this->bwfBuyer,\App\Domains\Financial\Enums\WalletType::USUARIO);
    $this->bwfConsent = new class implements \App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization {
        public bool $pay = true; public bool $refund = true; public bool $unavailable = false;
        public function allows(string $actorId,string $action,array $context): bool {
            if ($this->unavailable) throw new RuntimeException('fixture-consent-unavailable');
            return $action === 'purchase.pay' ? $this->pay && ($context['authorization_reference'] ?? '') === 'fixture-consent' : $this->refund;
        }
    };
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization::class,$this->bwfConsent);
    $this->bwfIssued = bwfIssue($this);
    $this->bwfMarket = ['business_id'=>'fixture-approved-business','order_id'=>'fixture-order-'.Str::uuid(),'items'=>[]];

    $this->bwfPurchase=bwfPay($this);
    // External payment follows the purchase; never weaken the production date check.
    $this->travel(1)->seconds();
    $this->bwfSettlementAuthority=new class implements \App\Domains\Financial\Contracts\InstitutionalSettlementAuthorization {
        public bool $allowed=true; public bool $unavailable=false;
        public function allows(string $actorId,string $action,array $context): bool {
            if ($this->unavailable) throw new RuntimeException('fixture-payment-verifier-unavailable');
            return $this->allowed && $action==='settlement.record' && ($context['recipient_reference'] ?? '')==='fixture-approved-recipient';
        }
    };
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalSettlementAuthorization::class,$this->bwfSettlementAuthority);

});
afterEach(function () {
    InstitutionalBudgetOperation::flushEventListeners(); BonusBudgetCommitment::flushEventListeners(); BonusLedgerEntry::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusObligation::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusRefund::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusSettlement::flushEventListeners();
    while (DB::connection('sqlsrv')->transactionLevel() > 0) DB::connection('sqlsrv')->rollBack();
});
function bwfIssuePayload($test): array
{
    return ['award_reference' => 'fixture-award-'.Str::uuid(), 'beneficiary_id' => (string) $test->bwfRecipient->getKey(), 'type' => 'BECA', 'amount_cents' => 3000,
        'valid_from' => now()->subMinute()->toISOString(), 'expires_at' => now()->addDay()->toISOString(),
        'combinable' => true, 'allows_partial_use' => true, 'reason' => 'Beca de prueba con presupuesto',
        'external_reference' => 'test-budget-issue-'.Str::uuid(), 'restrictions' => []];
}
function bwfIssue($test, ?array $data = null, ?string $key = null, int $version = 1): array
{
    return $test->bwfService->issue($test->bwfActor, $test->bwfBudget->public_id, $version,
        $data ?? bwfIssuePayload($test), $key ?? 'issue-'.Str::uuid());
}
function bwfPay($test, mixed $amount = 1000, ?string $key = null, ?array $market = null, string $proof = 'fixture-consent'): array
{
    return app(\App\Domains\Financial\Services\PurchasePaymentService::class)->payWithInstitutionalBeca($test->bwfWallet,
        $test->bwfIssued['bonus_id'],$amount,$key ?? 'pay-'.Str::uuid(),$test->bwfBuyer,$proof,$market ?? $test->bwfMarket);
}
function bwfLogin($t){return $t->actingAs($t->bwfOperator)->withSession(['cd_session_id'=>(string)$t->bwfSession->getKey()]);}
function bwfPayload($t):array{return [...bwfIssuePayload($t),'budget_id'=>strtolower($t->bwfBudget->public_id),
    'budget_version'=>(string)$t->bwfBudget->fresh()->version,'confirm_institutional'=>true];}
function bwfReviewUrl($t):string{return route('financial.bonuses.admin.beca.budget',['budgetId'=>$t->bwfBudget->public_id]);}
function bwfWebState():array{
    $out=[];foreach(['institutional_bonus_budgets','institutional_budget_operations','bonus_budget_commitments','bonuses','bonus_ledger_entries',
        'bonus_administrative_operations','wallets','ledger_entries','financial_transactions','purchase_payments','financial_team4_outbox']as$table)
        $out[$table]=DB::connection('sqlsrv')->table($table)->orderBy('id')->get()->toArray();
    return $out;
}
function bwfEmit($t,array $data,?string $key=null,bool $legacy=false){
    return $t->postJson(route($legacy?'financial.bonuses.admin.issue':'financial.bonuses.admin.beca.issue'),$data,['Idempotency-Key'=>$key??'web-'.Str::uuid()]);
}

test('beca web guest cannot review or issue',function(){
    $this->getJson(bwfReviewUrl($this))->assertUnauthorized();
    bwfEmit($this,bwfPayload($this))->assertUnauthorized();
});

test('beca web default authority denies before budget queries',function(){
    app()->instance(InstitutionalBudgetAuthorization::class,new \App\Domains\Financial\Adapters\PendingInstitutionalBudgetAuthorization());
    $payload=bwfPayload($this);$queries=[];DB::connection('sqlsrv')->listen(function($e)use(&$queries){$queries[]=$e->sql;});
    bwfLogin($this)->getJson(bwfReviewUrl($this))->assertForbidden();
    bwfEmit($this,$payload)->assertForbidden();
    expect($queries)->toBe([]);
});

test('beca web exact commit scope grants only a minimal read review without writes',function(){
    $id=strtolower($this->bwfBudget->public_id);
    app()->instance(InstitutionalBudgetAuthorization::class,new class($id) implements InstitutionalBudgetAuthorization{
        public function __construct(private string $id){}
        public function allows(string $actorId,string $action,?string $budgetId=null):bool{return $action==='budget.commit'&&$budgetId===$this->id;}
    });
    $before=bwfWebState();
    bwfLogin($this)->getJson(bwfReviewUrl($this))->assertOk()->assertHeader('Cache-Control','no-store, private')
        ->assertJsonPath('data.available_amount_cents','7000')->assertJsonPath('data.version','3')
        ->assertJsonMissingPath('data.payer_reference')->assertJsonMissingPath('data.approval_reference');
    $this->getJson(route('financial.bonuses.admin.beca.budget',['budgetId'=>(string)Str::uuid()]))->assertForbidden();
    expect(bwfWebState())->toEqual($before);
});

test('beca web issue atomically emits and commits without creating or debiting wallets',function(){
    $wallets=Wallet::orderBy('id')->get()->toArray();$payload=bwfPayload($this);
    bwfLogin($this);$r=bwfEmit($this,$payload)->assertCreated()->assertJsonPath('data.action','ISSUE_BECA')->assertJsonPath('replayed',false);
    $id=$r->json('data.bonus_id');
    expect(strtolower((string)Bonus::where('public_id',$id)->firstOrFail()->getRawOriginal('institutional_budget_id')))->toBe(strtolower($this->bwfBudget->public_id))
        ->and(BonusBudgetCommitment::where('bonus_id',$id)->count())->toBe(1)
        ->and($this->bwfBudget->fresh()->committed_amount_cents)->toBe(5000)
        ->and(Wallet::orderBy('id')->get()->toArray())->toEqual($wallets);
});

test('beca web exact retry preserves original review and rejects changed amount or award',function(){
    $payload=bwfPayload($this);$key='web-'.Str::uuid();bwfLogin($this);
    $first=bwfEmit($this,$payload,$key)->assertCreated();$before=bwfWebState();
    bwfEmit($this,$payload,$key)->assertOk()->assertJsonPath('replayed',true)->assertJsonPath('data.bonus_id',$first->json('data.bonus_id'));
    bwfEmit($this,[...$payload,'amount_cents'=>3001],$key)->assertConflict();
    bwfEmit($this,[...$payload,'award_reference'=>'changed-award'],$key)->assertConflict();
    expect(bwfWebState())->toEqual($before);
});

test('beca web legacy issue endpoint cannot bypass budget fields',function(){
    $payload=bwfPayload($this);$before=bwfWebState();bwfLogin($this);
    $plain=$payload;unset($plain['budget_id'],$plain['budget_version'],$plain['confirm_institutional']);
    bwfEmit($this,$plain,null,true)->assertUnprocessable();
    expect(bwfWebState())->toEqual($before);
    bwfEmit($this,$payload,null,true)->assertCreated()->assertJsonPath('data.action','ISSUE_BECA');
});

test('beca web stale review cannot issue and explicit new review allows a fresh request',function(){
    $payload=bwfPayload($this);bwfLogin($this)->getJson(bwfReviewUrl($this))->assertOk();
    bwfIssue($this,null,null,$this->bwfBudget->fresh()->version);$before=bwfWebState();
    bwfEmit($this,$payload)->assertConflict();expect(bwfWebState())->toEqual($before);
    $review=$this->getJson(bwfReviewUrl($this))->assertOk();
    bwfEmit($this,[...$payload,'budget_version'=>$review->json('data.version')])->assertCreated();
});

test('beca web missing consent award budget malformed version or forged actor rejects without issuing',function(){
    $payload=bwfPayload($this);$before=bwfWebState();bwfLogin($this);
    foreach([
        ['confirm_institutional'=>false],['award_reference'=>''],['budget_id'=>'bad'],['budget_version'=>'1.5'],
        ['budget_version'=>'9223372036854775808'],['actor_id'=>$this->bwfBuyer],['issuer_id'=>$this->bwfBuyer],
        ['issuer_scope_type'=>'business'],['type'=>'BENEFICIO'],['amount_cents'=>1.5]
    ]as$change)bwfEmit($this,[...$payload,...$change])->assertUnprocessable();
    expect(bwfWebState())->toEqual($before);
});

test('beca web session missing foreign revoked and second factor missing deny both review and emission',function(){
    $payload=bwfPayload($this);$before=bwfWebState();$this->actingAs($this->bwfOperator);
    $foreign=\App\Models\UserSession::create(['user_id'=>$this->bwfBuyer,'started_at'=>now()]);
    foreach([null,'invalid',(string)$foreign->getKey()]as$id){
        $this->withSession(['cd_session_id'=>$id])->getJson(bwfReviewUrl($this))->assertForbidden();bwfEmit($this,$payload)->assertForbidden();
    }
    $this->bwfSession->update(['revoked_at'=>now()]);bwfLogin($this)->getJson(bwfReviewUrl($this))->assertForbidden();bwfEmit($this,$payload)->assertForbidden();
    $this->bwfSession->update(['revoked_at'=>null]);$this->bwfOperator->forceFill(['two_factor_confirmed_at'=>null])->save();
    bwfLogin($this)->getJson(bwfReviewUrl($this))->assertForbidden();bwfEmit($this,$payload)->assertForbidden();
    expect(bwfWebState())->toEqual($before);
});

test('beca web issuer denial or identity outage leaves no commitment',function(){
    $payload=bwfPayload($this);$before=bwfWebState();bwfLogin($this);$this->bwfIssuer->allowed=false;
    bwfEmit($this,$payload)->assertForbidden();$this->bwfIssuer->allowed=true;$this->bwfIssuer->unavailable=true;
    bwfEmit($this,$payload)->assertStatus(503)->assertDontSee('FIXTURE_UNAVAILABLE');expect(bwfWebState())->toEqual($before);
});

test('beca web permission revoked after review blocks emission and a successful replay',function(){
    $payload=bwfPayload($this);$key='web-'.Str::uuid();bwfLogin($this)->getJson(bwfReviewUrl($this))->assertOk();
    $this->bwfAuthority->commit=false;bwfEmit($this,$payload,$key)->assertForbidden();
    $this->bwfAuthority->commit=true;bwfEmit($this,$payload,$key)->assertCreated();$before=bwfWebState();
    $this->bwfAuthority->commit=false;bwfEmit($this,$payload,$key)->assertForbidden();expect(bwfWebState())->toEqual($before);
});

test('beca web audit failure rolls back entire issuance and same request can retry',function(){
    $payload=bwfPayload($this);$key='web-'.Str::uuid();$before=bwfWebState();bwfLogin($this);
    BonusBudgetCommitment::creating(function(){throw new RuntimeException('fixture-private-db-diagnostic');});
    try{bwfEmit($this,$payload,$key)->assertStatus(503)->assertDontSee('fixture-private-db-diagnostic');}
    finally{BonusBudgetCommitment::flushEventListeners();}
    expect(bwfWebState())->toEqual($before);bwfEmit($this,$payload,$key)->assertCreated();
});

test('beca web damaged budget cannot be reviewed or used for issuance',function(){
    $payload=bwfPayload($this);DB::connection('sqlsrv')->table('institutional_bonus_budgets')->where('public_id',$this->bwfBudget->public_id)->increment('committed_amount_cents');
    $before=bwfWebState();bwfLogin($this)->getJson(bwfReviewUrl($this))->assertConflict();bwfEmit($this,$payload)->assertConflict();expect(bwfWebState())->toEqual($before);
});

test('beca web general bonus types cannot silently ignore institutional selectors',function(){
    $payload=bwfPayload($this);$payload['type']='BENEFICIO';$before=bwfWebState();bwfLogin($this);
    bwfEmit($this,$payload,null,true)->assertUnprocessable();expect(bwfWebState())->toEqual($before);
});

test('beca web institutional bonus listing never offers the legacy cancellation action',function(){
    bwfLogin($this)->getJson(route('financial.bonuses.admin.records'))->assertForbidden();
    app()->instance(FinancialWebAuthorizer::class,new class implements FinancialWebAuthorizer{
        public function allows(string $userId,string $action,?string $walletId=null):bool{return true;}
    });
    $r=$this->getJson(route('financial.bonuses.admin.records'))->assertOk();
    $row=collect($r->json('data'))->firstWhere('id',$this->bwfIssued['bonus_id']);
    expect($row['can_cancel'])->toBeFalse()->and($row['institutional_budget_id'])->toBe(strtolower($this->bwfBudget->public_id));
});


test('beca web suspension or insufficient capacity after review prevents emission',function(){
    $payload=bwfPayload($this);bwfLogin($this)->getJson(bwfReviewUrl($this))->assertOk();
    app(InstitutionalBudgetService::class)->setSuspended($this->bwfActor,$this->bwfBudget->public_id,$this->bwfBudget->fresh()->version,true,'Revision','suspend-'.Str::uuid());
    $before=bwfWebState();bwfEmit($this,$payload)->assertConflict();expect(bwfWebState())->toEqual($before);
    app(InstitutionalBudgetService::class)->setSuspended($this->bwfActor,$this->bwfBudget->public_id,$this->bwfBudget->fresh()->version,false,'Reactivacion','resume-'.Str::uuid());
    $payload=bwfPayload($this);$payload['amount_cents']=8000;$before=bwfWebState();
    bwfEmit($this,$payload)->assertConflict();expect(bwfWebState())->toEqual($before);
});

test('beca web own beneficiary and disabled current account cannot emit',function(){
    $payload=bwfPayload($this);$before=bwfWebState();bwfLogin($this);
    bwfEmit($this,[...$payload,'beneficiary_id'=>$this->bwfActor])->assertConflict();
    User::where('_id',$this->bwfActor)->update(['must_change_password'=>true]);
    bwfEmit($this,$payload)->assertForbidden();expect(bwfWebState())->toEqual($before);
});

test('beca web authorization outage does not leak diagnostics or consult financial data',function(){
    app()->instance(InstitutionalBudgetAuthorization::class,new class implements InstitutionalBudgetAuthorization{
        public function allows(string $actorId,string $action,?string $budgetId=null):bool{throw new RuntimeException('private-authority-diagnostic');}
    });
    $payload=bwfPayload($this);$queries=[];DB::connection('sqlsrv')->listen(function($e)use(&$queries){$queries[]=$e->sql;});
    bwfLogin($this)->getJson(bwfReviewUrl($this))->assertStatus(503)->assertDontSee('private-authority-diagnostic');
    bwfEmit($this,$payload)->assertStatus(503)->assertDontSee('private-authority-diagnostic');expect($queries)->toBe([]);
});
