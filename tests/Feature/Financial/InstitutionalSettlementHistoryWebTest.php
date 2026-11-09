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
    DB::connection('sqlsrv')->beginTransaction(); Http::preventStrayRequests(); $this->freezeTime(); $this->travelTo(now()->startOfSecond());
    $this->shwOperator = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->shwRecipient = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->shwActor = (string) $this->shwOperator->getKey();
    $this->shwAuthority = new class implements InstitutionalBudgetAuthorization {
        public bool $commit = true;
        public function allows(string $actorId, string $action, ?string $budgetId = null): bool { return $action !== 'budget.commit' || $this->commit; }
    };
    $this->shwAccess = new class implements FinancialWebAuthorizer {
        public bool $issue = true;
        public function allows(string $userId, string $action, ?string $walletId = null): bool { return in_array($action,['bonus.issue','bonus.cancel'],true) && $this->issue; }
    };
    $this->shwIssuer = new class implements BonusAuthorizationProvider {
        public bool $allowed = true; public bool $unavailable = false;
        public function canIssueBonus(string $issuerType, string $issuerId, BonusType $type): bool {
            if ($this->unavailable) throw new IdentityFinancialAuthorizationUnavailable('FIXTURE_UNAVAILABLE');
            return $this->allowed && $issuerType === 'USER' && $type === BonusType::BECA;
        }
    };
    app()->instance(InstitutionalBudgetAuthorization::class, $this->shwAuthority);
    app()->instance(FinancialWebAuthorizer::class, $this->shwAccess);
    app()->instance(BonusAuthorizationProvider::class, $this->shwIssuer);
    $this->shwService = app(InstitutionalBecaIssuanceService::class);
    $data = ['institution_reference' => 'fixture-institution', 'approval_reference' => 'approval-'.Str::uuid(),
        'payer_reference' => 'fixture-approved-payer', 'purpose' => 'Programa BECA', 'approved_amount_cents' => 10000,
        'currency' => 'MXN', 'reason' => 'Aprobacion de prueba', 'valid_from' => now()->subDay()->toISOString(), 'expires_at' => now()->addMonth()->toISOString()];
    $r = app(InstitutionalBudgetService::class)->approve($this->shwActor, $data, 'approve-'.Str::uuid());
    $this->shwBudget = InstitutionalBonusBudget::where('public_id', $r['operation']->budget_id)->firstOrFail();
    $this->shwBuyer = (string)$this->shwRecipient->getKey();
    $this->shwWallet = app(\App\Domains\Financial\Services\WalletService::class)->create('USER',$this->shwBuyer,\App\Domains\Financial\Enums\WalletType::USUARIO);
    $this->shwConsent = new class implements \App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization {
        public bool $pay = true; public bool $refund = true; public bool $unavailable = false;
        public function allows(string $actorId,string $action,array $context): bool {
            if ($this->unavailable) throw new RuntimeException('fixture-consent-unavailable');
            return $action === 'purchase.pay' ? $this->pay && ($context['authorization_reference'] ?? '') === 'fixture-consent' : $this->refund;
        }
    };
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization::class,$this->shwConsent);
    $this->shwIssued = shwIssue($this);
    $this->shwMarket = ['business_id'=>'fixture-approved-business','order_id'=>'fixture-order-'.Str::uuid(),'items'=>[]];

    $this->shwPurchase=shwPay($this);
    $this->withoutVite();
    $this->withoutMiddleware(\App\Http\Middleware\TrackDeviceSession::class);
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
    $this->shwOperator->forceFill(['two_factor_secret'=>encrypt('fixture-read-secret'),'two_factor_confirmed_at'=>now()])->save();
    $this->shwSession=\App\Models\UserSession::create(['user_id'=>$this->shwActor,'started_at'=>now()]);
    $this->shwReadAuthority=new class implements InstitutionalBudgetAuthorization {
        public bool $all=false; public array $budgets=[]; public bool $unavailable=false; public array $actors=[];
        public function allows(string $actorId,string $action,?string $budgetId=null): bool {
            $this->actors[]=$actorId;
            if ($this->unavailable) throw new RuntimeException('fixture-authorization-private-diagnostic');
            return $action==='budget.read.all' ? $this->all : ($action==='budget.read' && in_array($budgetId,$this->budgets,true));
        }
    };
    app()->instance(InstitutionalBudgetAuthorization::class,$this->shwReadAuthority);

});
afterEach(function () {
    InstitutionalBudgetOperation::flushEventListeners(); BonusBudgetCommitment::flushEventListeners(); BonusLedgerEntry::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusObligation::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusRefund::flushEventListeners();
    while (DB::connection('sqlsrv')->transactionLevel() > 0) DB::connection('sqlsrv')->rollBack();
});
function shwIssuePayload($test): array
{
    return ['award_reference' => 'fixture-award-'.Str::uuid(), 'beneficiary_id' => (string) $test->shwRecipient->getKey(), 'type' => 'BECA', 'amount_cents' => 3000,
        'valid_from' => now()->subMinute()->toISOString(), 'expires_at' => now()->addDay()->toISOString(),
        'combinable' => true, 'allows_partial_use' => true, 'reason' => 'Beca de prueba con presupuesto',
        'external_reference' => 'test-budget-issue-'.Str::uuid(), 'restrictions' => []];
}
function shwIssue($test, ?array $data = null, ?string $key = null, int $version = 1): array
{
    return $test->shwService->issue($test->shwActor, $test->shwBudget->public_id, $version,
        $data ?? shwIssuePayload($test), $key ?? 'issue-'.Str::uuid());
}
function shwState($test): array
{
    return [$test->shwBudget->fresh()->toArray(), Bonus::count(), BonusLedgerEntry::count(), BonusAdministrativeOperation::count(),
        BonusBudgetCommitment::count(), InstitutionalBudgetOperation::count(),
        Wallet::orderBy('id')->get(['public_id','available_balance_cents','held_balance_cents'])->toArray()];
}


function shwPay($test, mixed $amount = 1000, ?string $key = null, ?array $market = null, string $proof = 'fixture-consent'): array
{
    return app(\App\Domains\Financial\Services\PurchasePaymentService::class)->payWithInstitutionalBeca($test->shwWallet,
        $test->shwIssued['bonus_id'],$amount,$key ?? 'pay-'.Str::uuid(),$test->shwBuyer,$proof,$market ?? $test->shwMarket);
}
function shwRefund($test, array $purchase, int $amount, bool $approve = true): \App\Domains\Financial\Models\PurchaseRefundRequest
{
    $service = app(\App\Domains\Financial\Services\PurchaseRefundService::class);
    $payment = \App\Domains\Financial\Models\PurchasePayment::where('public_id',$purchase['purchase_payment_id'])->firstOrFail();
    $request = $service->request($payment,0,[['bonus_id'=>$test->shwIssued['bonus_id'],'amount_cents'=>$amount]],
        'request-'.Str::uuid(),$test->shwBuyer,'Devolucion de prueba institucional');
    return $approve ? $service->approve($request,$test->shwActor,'Revision de prueba') : $request;
}
function shwComplete($test,$refund,?string $key = null) {
    return app(\App\Domains\Financial\Services\PurchaseRefundService::class)->complete($refund,$key ?? 'refund-'.Str::uuid(),$test->shwActor);
}
function shwSpendingState($test): array {
    return [shwState($test),
        \App\Domains\Financial\Models\InstitutionalBonusObligation::orderBy('id')->get()->toArray(),
        \App\Domains\Financial\Models\InstitutionalBonusRefund::orderBy('id')->get()->toArray(),
        \App\Domains\Financial\Models\PurchasePayment::count(),\App\Domains\Financial\Models\FinancialTransaction::count(),
        \App\Domains\Financial\Models\LedgerEntry::count(),DB::connection('sqlsrv')->table('financial_team4_outbox')->orderBy('id')->get()->toArray()];
}


function shwSignIn($test) {
    return $test->actingAs($test->shwOperator)->withSession(['cd_session_id'=>(string)$test->shwSession->getKey()]);
}
function shwUrl($test,array $extra=[]): string {
    return route('financial.institutional.index',['budget'=>strtolower($test->shwBudget->public_id),...$extra]);
}

function shwHistoryUrl($t,array $params=[]):string{return route('financial.institutional.history',['budgetId'=>$t->shwBudget->public_id,...$params]);}
function shwHistory($t,array $params=[]){
    $url=shwHistoryUrl($t,$params);$headers=['X-Inertia'=>'true'];
    $version=app(\App\Http\Middleware\HandleInertiaRequests::class)->version(\Illuminate\Http\Request::create($url));
    if($version!==null)$headers['X-Inertia-Version']=$version;
    return shwSignIn($t)->getJson($url,$headers);
}
function shwReport($t,array $params=[]):array{return shwHistory($t,$params)->assertOk()->json('props.history');}

function shwSettle($t,int $amount):array{
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalSettlementAuthorization::class,new class implements \App\Domains\Financial\Contracts\InstitutionalSettlementAuthorization{
        public function allows(string $actorId,string $action,array $context):bool{return $action==='settlement.record';}
    });
    $t->travel(1)->seconds();
    return app(\App\Domains\Financial\Services\InstitutionalSettlementService::class)->record($t->shwActor,$t->shwPurchase['obligation_id'],$t->shwBudget->fresh()->version,
        ['amount_cents'=>$amount,'currency'=>'MXN','payment_provider'=>'fixture-bank','payment_reference'=>'fixture-payment-'.Str::uuid(),
        'recipient_reference'=>'PRIVATE-RECIPIENT','paid_at'=>now()->toISOString(),'reason'=>'Liquidacion de prueba'], 'history-settle-'.Str::uuid());
}
test('settlement history displays two actual partial payments without changing budget availability or historical snapshots',function(){
    $this->shwReadAuthority->all=true;$available=$this->shwBudget->fresh()->approved_amount_cents-$this->shwBudget->fresh()->committed_amount_cents-$this->shwBudget->fresh()->consumed_amount_cents;
    shwSettle($this,400);shwSettle($this,300);$before=shwSpendingState($this);$r=shwHistory($this,['action'=>'SETTLE_BECA'])->assertOk();$items=$r->json('props.history.items');
    expect($items)->toHaveCount(2)->and($items[0]['settlement']['paid_amount_cents'])->toBe('300')
        ->and($items[0]['settlement']['before']['settled_amount_cents'])->toBe('400')
        ->and($items[0]['settlement']['after']['settled_amount_cents'])->toBe('700')
        ->and($items[0]['settlement']['before']['unpaid_amount_cents'])->toBe('600')
        ->and($items[0]['settlement']['after']['unpaid_amount_cents'])->toBe('300')
        ->and($items[1]['settlement']['before']['settled_amount_cents'])->toBe('0')
        ->and($items[1]['settlement']['after']['settled_amount_cents'])->toBe('400')
        ->and($items[0]['settlement']['obligation_id'])->toBe(strtolower($this->shwPurchase['obligation_id']))
        ->and($items[0]['before']['available_amount_cents'])->toBe((string)$available)
        ->and($items[0]['after']['available_amount_cents'])->toBe((string)$available)
        ->and(shwSpendingState($this))->toEqual($before);
    expect($r->getContent())->not->toContain('PRIVATE-RECIPIENT','payment_provider','payment_reference','recipient_reference','native_session_hash');Http::assertNothingSent();
});
test('settlement history does not fabricate obligation totals on other operation types',function(){
    $this->shwReadAuthority->all=true;$h=shwReport($this);foreach($h['items']as$item)expect($item['settlement'])->toBeNull();
});
test('settlement history missing or malformed amounts are null and marked for review',function(string $case){
    $this->shwReadAuthority->all=true;$result=shwSettle($this,400);$op=InstitutionalBudgetOperation::where('public_id',$result['operation']->public_id)->firstOrFail();$data=$op->after_data;
    if($case==='missing')unset($data['unpaid_amount_cents']);else $data['unpaid_amount_cents']=$case==='float'?3.5:-1;
    DB::connection('sqlsrv')->table('institutional_budget_operations')->where('public_id',$op->public_id)->update(['after_data'=>json_encode($data)]);
    $entry=shwReport($this,['action'=>'SETTLE_BECA'])['items'][0];
    expect($entry['settlement']['after']['unpaid_amount_cents'])->toBeNull()->and($entry['recorded_fields_valid'])->toBeFalse();
})->with(['missing','float','negative']);
test('settlement history preserves large decimal strings rather than converting to float',function(){
    $this->shwReadAuthority->all=true;$result=shwSettle($this,400);$op=InstitutionalBudgetOperation::where('public_id',$result['operation']->public_id)->firstOrFail();$data=$op->after_data;$data['settled_amount_cents']='9223372036854775808123';
    DB::connection('sqlsrv')->table('institutional_budget_operations')->where('public_id',$op->public_id)->update(['after_data'=>json_encode($data)]);
    expect(shwReport($this,['action'=>'SETTLE_BECA'])['items'][0]['settlement']['after']['settled_amount_cents'])->toBe('9223372036854775808123');
});
test('settlement history never bypasses the current scoped read authorization',function(){
    shwSettle($this,400);shwHistory($this,['action'=>'SETTLE_BECA'])->assertForbidden();
    $this->shwReadAuthority->budgets=[strtolower($this->shwBudget->public_id)];shwHistory($this,['action'=>'SETTLE_BECA'])->assertOk();
    $this->shwReadAuthority->budgets=[];shwHistory($this,['action'=>'SETTLE_BECA'])->assertForbidden();
});
