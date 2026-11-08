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
    $this->ibhOperator = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->ibhRecipient = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->ibhActor = (string) $this->ibhOperator->getKey();
    $this->ibhAuthority = new class implements InstitutionalBudgetAuthorization {
        public bool $commit = true;
        public function allows(string $actorId, string $action, ?string $budgetId = null): bool { return $action !== 'budget.commit' || $this->commit; }
    };
    $this->ibhAccess = new class implements FinancialWebAuthorizer {
        public bool $issue = true;
        public function allows(string $userId, string $action, ?string $walletId = null): bool { return in_array($action,['bonus.issue','bonus.cancel'],true) && $this->issue; }
    };
    $this->ibhIssuer = new class implements BonusAuthorizationProvider {
        public bool $allowed = true; public bool $unavailable = false;
        public function canIssueBonus(string $issuerType, string $issuerId, BonusType $type): bool {
            if ($this->unavailable) throw new IdentityFinancialAuthorizationUnavailable('FIXTURE_UNAVAILABLE');
            return $this->allowed && $issuerType === 'USER' && $type === BonusType::BECA;
        }
    };
    app()->instance(InstitutionalBudgetAuthorization::class, $this->ibhAuthority);
    app()->instance(FinancialWebAuthorizer::class, $this->ibhAccess);
    app()->instance(BonusAuthorizationProvider::class, $this->ibhIssuer);
    $this->ibhService = app(InstitutionalBecaIssuanceService::class);
    $data = ['institution_reference' => 'fixture-institution', 'approval_reference' => 'approval-'.Str::uuid(),
        'payer_reference' => 'fixture-approved-payer', 'purpose' => 'Programa BECA', 'approved_amount_cents' => 10000,
        'currency' => 'MXN', 'reason' => 'Aprobacion de prueba', 'valid_from' => now()->subDay()->toISOString(), 'expires_at' => now()->addMonth()->toISOString()];
    $r = app(InstitutionalBudgetService::class)->approve($this->ibhActor, $data, 'approve-'.Str::uuid());
    $this->ibhBudget = InstitutionalBonusBudget::where('public_id', $r['operation']->budget_id)->firstOrFail();
    $this->ibhBuyer = (string)$this->ibhRecipient->getKey();
    $this->ibhWallet = app(\App\Domains\Financial\Services\WalletService::class)->create('USER',$this->ibhBuyer,\App\Domains\Financial\Enums\WalletType::USUARIO);
    $this->ibhConsent = new class implements \App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization {
        public bool $pay = true; public bool $refund = true; public bool $unavailable = false;
        public function allows(string $actorId,string $action,array $context): bool {
            if ($this->unavailable) throw new RuntimeException('fixture-consent-unavailable');
            return $action === 'purchase.pay' ? $this->pay && ($context['authorization_reference'] ?? '') === 'fixture-consent' : $this->refund;
        }
    };
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization::class,$this->ibhConsent);
    $this->ibhIssued = ibhIssue($this);
    $this->ibhMarket = ['business_id'=>'fixture-approved-business','order_id'=>'fixture-order-'.Str::uuid(),'items'=>[]];

    $this->ibhPurchase=ibhPay($this);
    $this->withoutVite();
    $this->withoutMiddleware(\App\Http\Middleware\TrackDeviceSession::class);
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
    $this->ibhOperator->forceFill(['two_factor_secret'=>encrypt('fixture-read-secret'),'two_factor_confirmed_at'=>now()])->save();
    $this->ibhSession=\App\Models\UserSession::create(['user_id'=>$this->ibhActor,'started_at'=>now()]);
    $this->ibhReadAuthority=new class implements InstitutionalBudgetAuthorization {
        public bool $all=false; public array $budgets=[]; public bool $unavailable=false; public array $actors=[];
        public function allows(string $actorId,string $action,?string $budgetId=null): bool {
            $this->actors[]=$actorId;
            if ($this->unavailable) throw new RuntimeException('fixture-authorization-private-diagnostic');
            return $action==='budget.read.all' ? $this->all : ($action==='budget.read' && in_array($budgetId,$this->budgets,true));
        }
    };
    app()->instance(InstitutionalBudgetAuthorization::class,$this->ibhReadAuthority);

});
afterEach(function () {
    InstitutionalBudgetOperation::flushEventListeners(); BonusBudgetCommitment::flushEventListeners(); BonusLedgerEntry::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusObligation::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusRefund::flushEventListeners();
    while (DB::connection('sqlsrv')->transactionLevel() > 0) DB::connection('sqlsrv')->rollBack();
});
function ibhIssuePayload($test): array
{
    return ['award_reference' => 'fixture-award-'.Str::uuid(), 'beneficiary_id' => (string) $test->ibhRecipient->getKey(), 'type' => 'BECA', 'amount_cents' => 3000,
        'valid_from' => now()->subMinute()->toISOString(), 'expires_at' => now()->addDay()->toISOString(),
        'combinable' => true, 'allows_partial_use' => true, 'reason' => 'Beca de prueba con presupuesto',
        'external_reference' => 'test-budget-issue-'.Str::uuid(), 'restrictions' => []];
}
function ibhIssue($test, ?array $data = null, ?string $key = null, int $version = 1): array
{
    return $test->ibhService->issue($test->ibhActor, $test->ibhBudget->public_id, $version,
        $data ?? ibhIssuePayload($test), $key ?? 'issue-'.Str::uuid());
}
function ibhState($test): array
{
    return [$test->ibhBudget->fresh()->toArray(), Bonus::count(), BonusLedgerEntry::count(), BonusAdministrativeOperation::count(),
        BonusBudgetCommitment::count(), InstitutionalBudgetOperation::count(),
        Wallet::orderBy('id')->get(['public_id','available_balance_cents','held_balance_cents'])->toArray()];
}


function ibhPay($test, mixed $amount = 1000, ?string $key = null, ?array $market = null, string $proof = 'fixture-consent'): array
{
    return app(\App\Domains\Financial\Services\PurchasePaymentService::class)->payWithInstitutionalBeca($test->ibhWallet,
        $test->ibhIssued['bonus_id'],$amount,$key ?? 'pay-'.Str::uuid(),$test->ibhBuyer,$proof,$market ?? $test->ibhMarket);
}
function ibhRefund($test, array $purchase, int $amount, bool $approve = true): \App\Domains\Financial\Models\PurchaseRefundRequest
{
    $service = app(\App\Domains\Financial\Services\PurchaseRefundService::class);
    $payment = \App\Domains\Financial\Models\PurchasePayment::where('public_id',$purchase['purchase_payment_id'])->firstOrFail();
    $request = $service->request($payment,0,[['bonus_id'=>$test->ibhIssued['bonus_id'],'amount_cents'=>$amount]],
        'request-'.Str::uuid(),$test->ibhBuyer,'Devolucion de prueba institucional');
    return $approve ? $service->approve($request,$test->ibhActor,'Revision de prueba') : $request;
}
function ibhComplete($test,$refund,?string $key = null) {
    return app(\App\Domains\Financial\Services\PurchaseRefundService::class)->complete($refund,$key ?? 'refund-'.Str::uuid(),$test->ibhActor);
}
function ibhSpendingState($test): array {
    return [ibhState($test),
        \App\Domains\Financial\Models\InstitutionalBonusObligation::orderBy('id')->get()->toArray(),
        \App\Domains\Financial\Models\InstitutionalBonusRefund::orderBy('id')->get()->toArray(),
        \App\Domains\Financial\Models\PurchasePayment::count(),\App\Domains\Financial\Models\FinancialTransaction::count(),
        \App\Domains\Financial\Models\LedgerEntry::count(),DB::connection('sqlsrv')->table('financial_team4_outbox')->orderBy('id')->get()->toArray()];
}


function ibhSignIn($test) {
    return $test->actingAs($test->ibhOperator)->withSession(['cd_session_id'=>(string)$test->ibhSession->getKey()]);
}
function ibhUrl($test,array $extra=[]): string {
    return route('financial.institutional.index',['budget'=>strtolower($test->ibhBudget->public_id),...$extra]);
}

function ibhHistoryUrl($t,array $params=[]):string{return route('financial.institutional.history',['budgetId'=>$t->ibhBudget->public_id,...$params]);}
function ibhHistory($t,array $params=[]){
    $url=ibhHistoryUrl($t,$params);$headers=['X-Inertia'=>'true'];
    $version=app(\App\Http\Middleware\HandleInertiaRequests::class)->version(\Illuminate\Http\Request::create($url));
    if($version!==null)$headers['X-Inertia-Version']=$version;
    return ibhSignIn($t)->getJson($url,$headers);
}
function ibhReport($t,array $params=[]):array{return ibhHistory($t,$params)->assertOk()->json('props.history');}

test('budget history requires native authentication and explicit scoped read authority',function(){
    $this->getJson(ibhHistoryUrl($this))->assertUnauthorized();
    ibhHistory($this)->assertForbidden();
    $this->ibhReadAuthority->budgets=[strtolower($this->ibhBudget->public_id)];
    expect(ibhReport($this)['budget_id'])->toBe(strtolower($this->ibhBudget->public_id));
});
test('budget history provides exact snapshots and whitelisted references without mutation or private payloads',function(){
    $this->ibhReadAuthority->all=true;$before=ibhSpendingState($this);$response=ibhHistory($this)->assertOk();$h=$response->json('props.history');
    expect($h['items'])->toHaveCount(3)->and($h['items'][0]['action'])->toBe('CONSUME_BECA')
        ->and($h['items'][0]['before']['committed_amount_cents'])->toBe('3000')
        ->and($h['items'][0]['after']['committed_amount_cents'])->toBe('2000')
        ->and($h['items'][0]['after']['consumed_amount_cents'])->toBe('1000')
        ->and($h['items'][0]['recorded_fields_valid'])->toBeTrue()->and(ibhSpendingState($this))->toEqual($before);
    $text=$response->getContent();foreach(['payer_reference','actor_id','identity_session_id','native_session_hash','request_hash','idempotency_key','approval_reference']as$key)expect($text)->not->toContain('"'.$key.'"');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');Http::assertNothingSent();
});
test('budget history approval does not invent a previous zero snapshot',function(){
    $this->ibhReadAuthority->all=true;$h=ibhReport($this,['action'=>'APPROVE']);
    expect($h['items'])->toHaveCount(1)->and($h['items'][0]['before']['approved_amount_cents'])->toBeNull()
        ->and($h['items'][0]['after']['approved_amount_cents'])->toBe('10000');
});
test('budget history keyset pagination has no repeated operations when a new audit arrives',function(){
    $this->ibhReadAuthority->all=true;$a=ibhReport($this,['per_page'=>1]);expect($a['has_more'])->toBeTrue();
    // A fresh valid operation inserted between pages must not shift the older page.
    $op=InstitutionalBudgetOperation::orderByDesc('id')->firstOrFail();$data=$op->getAttributes();unset($data['id']);
    $data['public_id']=(string)Str::uuid();$data['idempotency_key']='history-insert-'.Str::uuid();InstitutionalBudgetOperation::insert($data);
    $b=ibhReport($this,['per_page'=>1,'before'=>$a['next_cursor']]);
    expect($b['items'][0]['operation_id'])->not->toBe($a['items'][0]['operation_id'])->and($b['items'][0]['action'])->toBe('ISSUE_BECA');
    $c=ibhReport($this,['per_page'=>1,'before'=>$b['next_cursor']]);expect($c['items'][0]['action'])->toBe('APPROVE')->and($c['has_more'])->toBeFalse();
});
test('budget history filter never escapes authorized budget and absent authorized budget returns not found',function(){
    $this->ibhReadAuthority->budgets=[strtolower($this->ibhBudget->public_id)];
    $foreign=(string)Str::uuid();ibhSignIn($this)->getJson(route('financial.institutional.history',['budgetId'=>$foreign]))->assertForbidden();
    $this->ibhReadAuthority->all=true;ibhSignIn($this)->getJson(route('financial.institutional.history',['budgetId'=>$foreign]))->assertNotFound();
});
test('budget history refuses extra selectors invalid action cursors and excessive pages',function(){
    $this->ibhReadAuthority->all=true;$before=ibhSpendingState($this);
    foreach([['subject_id'=>$this->ibhActor],['business'=>'arbitrary'],['action'=>'DROP'],['per_page'=>21],['before'=>'1e3'],['before'=>'0'],['before'=>str_repeat('9',30)]]as$params)ibhHistory($this,$params)->assertUnprocessable();
    expect(ibhSpendingState($this))->toEqual($before);
});
test('budget history current permission and registered session revocation immediately deny access',function(){
    $this->ibhReadAuthority->all=true;ibhHistory($this)->assertOk();$this->ibhReadAuthority->all=false;ibhHistory($this)->assertForbidden();
    $this->ibhReadAuthority->all=true;$this->ibhSession->update(['revoked_at'=>now()]);ibhHistory($this)->assertForbidden();
});
test('budget history authorization outage is sanitized and never returns financial history',function(){
    $this->ibhReadAuthority->unavailable=true;$r=ibhHistory($this)->assertStatus(503);
    expect($r->getContent())->not->toContain('fixture-authorization-private-diagnostic','operation_id','approved_amount_cents');
});
test('budget history malformed stored amounts stay explicit and never become a false zero',function(){
    $this->ibhReadAuthority->all=true;$op=InstitutionalBudgetOperation::orderByDesc('id')->firstOrFail();$after=$op->after_data;
    $after['available_amount_cents']=1.5;$after['payer_reference']='PRIVATE-PAYER';$after['web_audit']=['native_session_hash'=>'PRIVATE-HASH'];
    DB::connection('sqlsrv')->table('institutional_budget_operations')->where('id',$op->id)->update(['after_data'=>json_encode($after)]);
    $r=ibhHistory($this)->assertOk();$h=$r->json('props.history.items.0');expect($h['after']['available_amount_cents'])->toBeNull()->and($h['recorded_fields_valid'])->toBeFalse();
    expect($r->getContent())->not->toContain('PRIVATE-PAYER','PRIVATE-HASH');
});
test('budget history preserves decimal strings beyond native integer range and escapes no data through raw snapshots',function(){
    $this->ibhReadAuthority->all=true;$op=InstitutionalBudgetOperation::orderByDesc('id')->firstOrFail();$after=$op->after_data;$after['approved_amount_cents']='9223372036854775808123';
    DB::connection('sqlsrv')->table('institutional_budget_operations')->where('id',$op->id)->update(['after_data'=>json_encode($after)]);
    expect(ibhReport($this)['items'][0]['after']['approved_amount_cents'])->toBe('9223372036854775808123');
});
test('budget history screen links from authorized budget view and registers no mutation endpoint',function(){
    $this->ibhReadAuthority->all=true;ibhSignIn($this)->get(ibhUrl($this))->assertOk();ibhHistory($this)->assertOk();
    $this->postJson(ibhHistoryUrl($this),[])->assertStatus(405);
});
test('budget history shows completed closed refund as budget recovery with exact related references',function(){
    app()->instance(InstitutionalBudgetAuthorization::class,$this->ibhAuthority);
    app(\App\Domains\Financial\Services\InstitutionalBecaReleaseService::class)->cancel($this->ibhActor,$this->ibhBudget->public_id,$this->ibhIssued['bonus_id'],
        $this->ibhBudget->fresh()->version,'Cierre de prueba','history-close-'.Str::uuid());
    $r=ibhRefund($this,$this->ibhPurchase,400);
    app(\App\Domains\Financial\Services\InstitutionalBecaPurchaseService::class)->completeClosedRefund($r,'history-refund-'.Str::uuid(),$this->ibhActor);
    app()->instance(InstitutionalBudgetAuthorization::class,$this->ibhReadAuthority);$this->ibhReadAuthority->all=true;
    $h=ibhReport($this,['action'=>'REFUND_CLOSED_BECA']);expect($h['items'])->toHaveCount(1)
        ->and($h['items'][0]['after']['consumed_amount_cents'])->toBe('600')
        ->and($h['items'][0]['after']['committed_amount_cents'])->toBe('0')
        ->and($h['items'][0]['references']['refund_request_id'])->toBe(strtolower($r->public_id))
        ->and($h['items'][0]['recorded_fields_valid'])->toBeTrue();
});
