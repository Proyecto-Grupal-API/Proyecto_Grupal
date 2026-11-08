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
    $this->ibwOperator = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->ibwRecipient = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->ibwActor = (string) $this->ibwOperator->getKey();
    $this->ibwAuthority = new class implements InstitutionalBudgetAuthorization {
        public bool $commit = true;
        public function allows(string $actorId, string $action, ?string $budgetId = null): bool { return $action !== 'budget.commit' || $this->commit; }
    };
    $this->ibwAccess = new class implements FinancialWebAuthorizer {
        public bool $issue = true;
        public function allows(string $userId, string $action, ?string $walletId = null): bool { return in_array($action,['bonus.issue','bonus.cancel'],true) && $this->issue; }
    };
    $this->ibwIssuer = new class implements BonusAuthorizationProvider {
        public bool $allowed = true; public bool $unavailable = false;
        public function canIssueBonus(string $issuerType, string $issuerId, BonusType $type): bool {
            if ($this->unavailable) throw new IdentityFinancialAuthorizationUnavailable('FIXTURE_UNAVAILABLE');
            return $this->allowed && $issuerType === 'USER' && $type === BonusType::BECA;
        }
    };
    app()->instance(InstitutionalBudgetAuthorization::class, $this->ibwAuthority);
    app()->instance(FinancialWebAuthorizer::class, $this->ibwAccess);
    app()->instance(BonusAuthorizationProvider::class, $this->ibwIssuer);
    $this->ibwService = app(InstitutionalBecaIssuanceService::class);
    $data = ['institution_reference' => 'fixture-institution', 'approval_reference' => 'approval-'.Str::uuid(),
        'payer_reference' => 'fixture-approved-payer', 'purpose' => 'Programa BECA', 'approved_amount_cents' => 10000,
        'currency' => 'MXN', 'reason' => 'Aprobacion de prueba', 'valid_from' => now()->subDay()->toISOString(), 'expires_at' => now()->addMonth()->toISOString()];
    $r = app(InstitutionalBudgetService::class)->approve($this->ibwActor, $data, 'approve-'.Str::uuid());
    $this->ibwBudget = InstitutionalBonusBudget::where('public_id', $r['operation']->budget_id)->firstOrFail();
    $this->ibwBuyer = (string)$this->ibwRecipient->getKey();
    $this->ibwWallet = app(\App\Domains\Financial\Services\WalletService::class)->create('USER',$this->ibwBuyer,\App\Domains\Financial\Enums\WalletType::USUARIO);
    $this->ibwConsent = new class implements \App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization {
        public bool $pay = true; public bool $refund = true; public bool $unavailable = false;
        public function allows(string $actorId,string $action,array $context): bool {
            if ($this->unavailable) throw new RuntimeException('fixture-consent-unavailable');
            return $action === 'purchase.pay' ? $this->pay && ($context['authorization_reference'] ?? '') === 'fixture-consent' : $this->refund;
        }
    };
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization::class,$this->ibwConsent);
    $this->ibwIssued = ibwIssue($this);
    $this->ibwMarket = ['business_id'=>'fixture-approved-business','order_id'=>'fixture-order-'.Str::uuid(),'items'=>[]];

    $this->withoutVite();
    $this->withoutMiddleware(\App\Http\Middleware\TrackDeviceSession::class);
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
    $this->ibwOperator->forceFill(['two_factor_secret'=>encrypt('fixture-read-secret'),'two_factor_confirmed_at'=>now()])->save();
    $this->ibwSession=\App\Models\UserSession::create(['user_id'=>$this->ibwActor,'started_at'=>now()]);
    $this->ibwReadAuthority=new class implements InstitutionalBudgetAuthorization {
        public bool $all=false; public array $budgets=[]; public bool $unavailable=false; public array $actors=[];
        public function allows(string $actorId,string $action,?string $budgetId=null): bool {
            $this->actors[]=$actorId;
            if ($this->unavailable) throw new RuntimeException('fixture-authorization-private-diagnostic');
            return $action==='budget.read.all' ? $this->all : ($action==='budget.read' && in_array($budgetId,$this->budgets,true));
        }
    };
    app()->instance(InstitutionalBudgetAuthorization::class,$this->ibwReadAuthority);

});
afterEach(function () {
    InstitutionalBudgetOperation::flushEventListeners(); BonusBudgetCommitment::flushEventListeners(); BonusLedgerEntry::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusObligation::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusRefund::flushEventListeners();
    while (DB::connection('sqlsrv')->transactionLevel() > 0) DB::connection('sqlsrv')->rollBack();
});
function ibwIssuePayload($test): array
{
    return ['award_reference' => 'fixture-award-'.Str::uuid(), 'beneficiary_id' => (string) $test->ibwRecipient->getKey(), 'type' => 'BECA', 'amount_cents' => 3000,
        'valid_from' => now()->subMinute()->toISOString(), 'expires_at' => now()->addDay()->toISOString(),
        'combinable' => true, 'allows_partial_use' => true, 'reason' => 'Beca de prueba con presupuesto',
        'external_reference' => 'test-budget-issue-'.Str::uuid(), 'restrictions' => []];
}
function ibwIssue($test, ?array $data = null, ?string $key = null, int $version = 1): array
{
    return $test->ibwService->issue($test->ibwActor, $test->ibwBudget->public_id, $version,
        $data ?? ibwIssuePayload($test), $key ?? 'issue-'.Str::uuid());
}
function ibwState($test): array
{
    return [$test->ibwBudget->fresh()->toArray(), Bonus::count(), BonusLedgerEntry::count(), BonusAdministrativeOperation::count(),
        BonusBudgetCommitment::count(), InstitutionalBudgetOperation::count(),
        Wallet::orderBy('id')->get(['public_id','available_balance_cents','held_balance_cents'])->toArray()];
}


function ibwPay($test, mixed $amount = 1000, ?string $key = null, ?array $market = null, string $proof = 'fixture-consent'): array
{
    return app(\App\Domains\Financial\Services\PurchasePaymentService::class)->payWithInstitutionalBeca($test->ibwWallet,
        $test->ibwIssued['bonus_id'],$amount,$key ?? 'pay-'.Str::uuid(),$test->ibwBuyer,$proof,$market ?? $test->ibwMarket);
}
function ibwRefund($test, array $purchase, int $amount, bool $approve = true): \App\Domains\Financial\Models\PurchaseRefundRequest
{
    $service = app(\App\Domains\Financial\Services\PurchaseRefundService::class);
    $payment = \App\Domains\Financial\Models\PurchasePayment::where('public_id',$purchase['purchase_payment_id'])->firstOrFail();
    $request = $service->request($payment,0,[['bonus_id'=>$test->ibwIssued['bonus_id'],'amount_cents'=>$amount]],
        'request-'.Str::uuid(),$test->ibwBuyer,'Devolucion de prueba institucional');
    return $approve ? $service->approve($request,$test->ibwActor,'Revision de prueba') : $request;
}
function ibwComplete($test,$refund,?string $key = null) {
    return app(\App\Domains\Financial\Services\PurchaseRefundService::class)->complete($refund,$key ?? 'refund-'.Str::uuid(),$test->ibwActor);
}
function ibwSpendingState($test): array {
    return [ibwState($test),
        \App\Domains\Financial\Models\InstitutionalBonusObligation::orderBy('id')->get()->toArray(),
        \App\Domains\Financial\Models\InstitutionalBonusRefund::orderBy('id')->get()->toArray(),
        \App\Domains\Financial\Models\PurchasePayment::count(),\App\Domains\Financial\Models\FinancialTransaction::count(),
        \App\Domains\Financial\Models\LedgerEntry::count(),DB::connection('sqlsrv')->table('financial_team4_outbox')->orderBy('id')->get()->toArray()];
}


function ibwSignIn($test) {
    return $test->actingAs($test->ibwOperator)->withSession(['cd_session_id'=>(string)$test->ibwSession->getKey()]);
}
function ibwUrl($test,array $extra=[]): string {
    return route('financial.institutional.index',['budget'=>strtolower($test->ibwBudget->public_id),...$extra]);
}

test('institutional budget screen requires native authentication and does not grant access by default',function () {
    $this->get(route('financial.institutional.index'))->assertRedirect(route('login'));
    ibwSignIn($this)->get(ibwUrl($this))->assertForbidden();
});

test('institutional budget screen default pending authority denies financial reads',function () {
    app()->instance(InstitutionalBudgetAuthorization::class,new \App\Domains\Financial\Adapters\PendingInstitutionalBudgetAuthorization());
    $queries=[]; DB::connection('sqlsrv')->listen(function($event)use(&$queries){$queries[]=$event->sql;});
    ibwSignIn($this)->get(ibwUrl($this))->assertForbidden(); expect($queries)->toBe([]);
});

test('institutional budget screen global reader sees budget labels exact amounts and no financial mutations',function () {
    $this->ibwReadAuthority->all=true; $before=ibwSpendingState($this);
    ibwSignIn($this)->get(ibwUrl($this))->assertOk()->assertHeader('Cache-Control','no-store, private')
        ->assertInertia(fn(\Inertia\Testing\AssertableInertia $p)=>$p->component('Financial/InstitutionalBudgets')
            ->where('report.budgets.0.budget_id',strtolower($this->ibwBudget->public_id))
            ->where('report.budgets.0.approved_amount_cents','10000')->where('labels.0.purpose','Programa BECA')->where('canListAll',true));
    expect(ibwSpendingState($this))->toEqual($before);
});

test('institutional budget screen scoped reader cannot enumerate other budgets',function () {
    $this->ibwReadAuthority->budgets=[strtolower($this->ibwBudget->public_id)];
    ibwSignIn($this)->get(ibwUrl($this))->assertOk()->assertInertia(fn(\Inertia\Testing\AssertableInertia $p)=>$p->where('canListAll',false));
    $this->get(route('financial.institutional.index'))->assertForbidden();
    $this->get(route('financial.institutional.index',['budget'=>(string)Str::uuid()]))->assertForbidden();
});

test('institutional budget screen keeps whole budget totals when a reader filters one business',function () {
    $this->ibwReadAuthority->all=true;
    ibwPay($this); ibwPay($this,500,null,[...$this->ibwMarket,'business_id'=>'second-business','order_id'=>'second-'.Str::uuid()]);
    ibwSignIn($this)->get(ibwUrl($this,['business'=>'second-business']))->assertOk()
        ->assertInertia(fn(\Inertia\Testing\AssertableInertia $p)=>$p->where('report.budgets.0.obligations.pending_amount_cents','1500')
            ->where('report.entries.0.pending_amount_cents','500')->where('filters.business','second-business'));
});

test('institutional budget screen rejects identity selectors and invalid pagination before SQL',function () {
    $this->ibwReadAuthority->all=true; $queries=[]; DB::connection('sqlsrv')->listen(function($event)use(&$queries){$queries[]=$event->sql;});
    ibwSignIn($this)->getJson(ibwUrl($this,['actor_id'=>$this->ibwBuyer]))->assertUnprocessable();
    $this->getJson(ibwUrl($this,['page'=>'1.5']))->assertUnprocessable();
    $this->getJson(ibwUrl($this,['per_page'=>21]))->assertUnprocessable();
    $this->getJson(ibwUrl($this,['view'=>'write']))->assertUnprocessable();
    expect($queries)->toBe([]);
});

test('institutional budget screen rechecks permission on every request and does not cache a previous grant',function () {
    $this->ibwReadAuthority->all=true; ibwSignIn($this)->get(ibwUrl($this))->assertOk();
    $this->ibwReadAuthority->all=false; $this->get(ibwUrl($this))->assertForbidden();
});

test('institutional budget screen rejects missing malformed foreign and revoked registered sessions',function () {
    $this->ibwReadAuthority->all=true; $this->actingAs($this->ibwOperator);
    foreach([null,'bad',(string)Str::uuid()] as $id)$this->withSession(['cd_session_id'=>$id])->get(ibwUrl($this))->assertForbidden();
    $foreign=\App\Models\UserSession::create(['user_id'=>$this->ibwBuyer,'started_at'=>now()]);
    $this->withSession(['cd_session_id'=>(string)$foreign->getKey()])->get(ibwUrl($this))->assertForbidden();
    $this->ibwSession->update(['revoked_at'=>now()]); ibwSignIn($this)->get(ibwUrl($this))->assertForbidden();
});

test('institutional budget screen requires confirmed second factor and rechecks account eligibility',function () {
    $this->ibwReadAuthority->all=true; $this->ibwOperator->forceFill(['two_factor_confirmed_at'=>null])->save();
    ibwSignIn($this)->get(ibwUrl($this))->assertForbidden();
    $this->ibwOperator->forceFill(['two_factor_confirmed_at'=>now()])->save();
    User::where('_id',$this->ibwActor)->update(['must_change_password'=>true]);
    $this->get(ibwUrl($this))->assertForbidden();
});

test('institutional budget screen authorization dependency failure returns sanitized unavailable response',function () {
    $this->ibwReadAuthority->unavailable=true;
    ibwSignIn($this)->get(ibwUrl($this))->assertStatus(503)->assertDontSee('fixture-authorization-private-diagnostic');
});

test('institutional budget screen query failure never returns SQL diagnostics or financial props',function () {
    $this->ibwReadAuthority->all=true;
    DB::connection('sqlsrv')->beforeExecuting(function($sql){if(str_contains($sql,'institutional_bonus_budgets'))throw new RuntimeException('fixture-sql-private-diagnostic');});
    ibwSignIn($this)->get(ibwUrl($this))->assertStatus(503)->assertDontSee('fixture-sql-private-diagnostic')->assertDontSee('approved_amount_cents');
});

test('institutional budget screen exposes no write endpoint',function () {
    $this->ibwReadAuthority->all=true;
    ibwSignIn($this)->post(route('financial.institutional.index'),['amount_cents'=>1])->assertStatus(405);
});

test('institutional budget screen supports obligations and settlement views without exposing human or recipient references',function () {
    $this->ibwReadAuthority->all=true; $purchase=ibwPay($this);
    ibwSignIn($this)->get(ibwUrl($this,['view'=>'obligations']))->assertOk()
        ->assertInertia(fn(\Inertia\Testing\AssertableInertia $p)=>$p->where('report.entries.0.purchase_payment_id',$purchase['purchase_payment_id'])
            ->where('report',fn($r)=>!str_contains(json_encode($r),$this->ibwBuyer)));
    $this->get(ibwUrl($this,['view'=>'settlements']))->assertOk()
        ->assertInertia(fn(\Inertia\Testing\AssertableInertia $p)=>$p->where('report.entries_kind','SETTLEMENTS')->where('report.entries',[]));
});

test('institutional budget dashboard link is visible only to an eligible global reader',function () {
    ibwSignIn($this)->get(route('financial.dashboard'))->assertOk()
        ->assertInertia(fn(\Inertia\Testing\AssertableInertia $p)=>$p->where('canReadInstitutionalBudgets',false));
    $this->ibwReadAuthority->all=true;
    $this->get(route('financial.dashboard'))->assertOk()
        ->assertInertia(fn(\Inertia\Testing\AssertableInertia $p)=>$p->where('canReadInstitutionalBudgets',true));
});
