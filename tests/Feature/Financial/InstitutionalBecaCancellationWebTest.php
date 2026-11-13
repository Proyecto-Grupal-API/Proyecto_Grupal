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
    $this->bcwOperator = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->bcwRecipient = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->bcwActor = (string) $this->bcwOperator->getKey();
    $this->bcwOperator->forceFill(['two_factor_secret'=>encrypt('fixture-beca-secret'),'two_factor_confirmed_at'=>now()])->save();
    $this->bcwSession=\App\Models\UserSession::create(['user_id'=>$this->bcwActor,'started_at'=>now()]);
    $this->bcwAuthority = new class implements InstitutionalBudgetAuthorization {
        public bool $commit = true;
        public function allows(string $actorId, string $action, ?string $budgetId = null): bool { return $action !== 'budget.commit' || $this->commit; }
    };
    $this->bcwAccess = new class implements FinancialWebAuthorizer {
        public bool $issue = true;
        public function allows(string $userId, string $action, ?string $walletId = null): bool { return in_array($action,['bonus.issue','bonus.cancel'],true) && $this->issue; }
    };
    $this->bcwIssuer = new class implements BonusAuthorizationProvider {
        public bool $allowed = true; public bool $unavailable = false;
        public function canIssueBonus(string $issuerType, string $issuerId, BonusType $type): bool {
            if ($this->unavailable) throw new IdentityFinancialAuthorizationUnavailable('FIXTURE_UNAVAILABLE');
            return $this->allowed && $issuerType === 'USER' && $type === BonusType::BECA;
        }
    };
    app()->instance(InstitutionalBudgetAuthorization::class, $this->bcwAuthority);
    app()->instance(FinancialWebAuthorizer::class, $this->bcwAccess);
    app()->instance(BonusAuthorizationProvider::class, $this->bcwIssuer);
    $this->bcwService = app(InstitutionalBecaIssuanceService::class);
    $data = ['institution_reference' => 'fixture-institution', 'approval_reference' => 'approval-'.Str::uuid(),
        'payer_reference' => 'fixture-approved-payer', 'purpose' => 'Programa BECA', 'approved_amount_cents' => 10000,
        'currency' => 'MXN', 'reason' => 'Aprobacion de prueba', 'valid_from' => now()->subDay()->toISOString(), 'expires_at' => now()->addMonth()->toISOString()];
    $r = app(InstitutionalBudgetService::class)->approve($this->bcwActor, $data, 'approve-'.Str::uuid());
    $this->bcwBudget = InstitutionalBonusBudget::where('public_id', $r['operation']->budget_id)->firstOrFail();
    $this->bcwBuyer = (string)$this->bcwRecipient->getKey();
    $this->bcwWallet = app(\App\Domains\Financial\Services\WalletService::class)->create('USER',$this->bcwBuyer,\App\Domains\Financial\Enums\WalletType::USUARIO);
    $this->bcwConsent = new class implements \App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization {
        public bool $pay = true; public bool $refund = true; public bool $unavailable = false;
        public function allows(string $actorId,string $action,array $context): bool {
            if ($this->unavailable) throw new RuntimeException('fixture-consent-unavailable');
            return $action === 'purchase.pay' ? $this->pay && ($context['authorization_reference'] ?? '') === 'fixture-consent' : $this->refund;
        }
    };
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization::class,$this->bcwConsent);
    $this->bcwIssued = bcwIssue($this);
    $this->bcwMarket = ['business_id'=>'fixture-approved-business','order_id'=>'fixture-order-'.Str::uuid(),'items'=>[]];

    $this->bcwPurchase=bcwPay($this);
    // External payment follows the purchase; never weaken the production date check.
    $this->travel(1)->seconds();
    $this->bcwSettlementAuthority=new class implements \App\Domains\Financial\Contracts\InstitutionalSettlementAuthorization {
        public bool $allowed=true; public bool $unavailable=false;
        public function allows(string $actorId,string $action,array $context): bool {
            if ($this->unavailable) throw new RuntimeException('fixture-payment-verifier-unavailable');
            return $this->allowed && $action==='settlement.record' && ($context['recipient_reference'] ?? '')==='fixture-approved-recipient';
        }
    };
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalSettlementAuthorization::class,$this->bcwSettlementAuthority);

    $this->bcwCancellationRights=new class implements FinancialWebAuthorizer{
        public bool $cancel=true;public bool $all=false;public bool $viewAll=false;
        public function allows(string $userId,string $action,?string $walletId=null):bool{return match($action){
            'bonus.cancel'=>$this->cancel,'bonus.cancel.all'=>$this->all,'bonus.view'=>true,'bonus.view.all'=>$this->viewAll,'bonus.issue'=>true,default=>false};}
    };
    $this->bcwBudgetRights=new class implements InstitutionalBudgetAuthorization{
        public bool $release=true;public bool $unavailable=false;
        public function allows(string $actorId,string $action,?string $budgetId=null):bool{
            if($action==='budget.release'&&$this->unavailable)throw new RuntimeException('fixture-private-cancel-authority');
            return $action==='budget.release'?$this->release:in_array($action,['budget.approve','budget.adjust','budget.suspend','budget.resume','budget.commit'],true);
        }
    };
    app()->instance(FinancialWebAuthorizer::class,$this->bcwCancellationRights);
    app()->instance(InstitutionalBudgetAuthorization::class,$this->bcwBudgetRights);


});
afterEach(function () {
    InstitutionalBudgetOperation::flushEventListeners(); BonusBudgetCommitment::flushEventListeners(); BonusLedgerEntry::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusObligation::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusRefund::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusSettlement::flushEventListeners();
    while (DB::connection('sqlsrv')->transactionLevel() > 0) DB::connection('sqlsrv')->rollBack();
});
function bcwIssuePayload($test): array
{
    return ['award_reference' => 'fixture-award-'.Str::uuid(), 'beneficiary_id' => (string) $test->bcwRecipient->getKey(), 'type' => 'BECA', 'amount_cents' => 3000,
        'valid_from' => now()->subMinute()->toISOString(), 'expires_at' => now()->addDay()->toISOString(),
        'combinable' => true, 'allows_partial_use' => true, 'reason' => 'Beca de prueba con presupuesto',
        'external_reference' => 'test-budget-issue-'.Str::uuid(), 'restrictions' => []];
}
function bcwIssue($test, ?array $data = null, ?string $key = null, int $version = 1): array
{
    return $test->bcwService->issue($test->bcwActor, $test->bcwBudget->public_id, $version,
        $data ?? bcwIssuePayload($test), $key ?? 'issue-'.Str::uuid());
}
function bcwPay($test, mixed $amount = 1000, ?string $key = null, ?array $market = null, string $proof = 'fixture-consent'): array
{
    return app(\App\Domains\Financial\Services\PurchasePaymentService::class)->payWithInstitutionalBeca($test->bcwWallet,
        $test->bcwIssued['bonus_id'],$amount,$key ?? 'pay-'.Str::uuid(),$test->bcwBuyer,$proof,$market ?? $test->bcwMarket);
}
function bcwLogin($t){
    $native=app('session')->driver();$native->invalidate();$native->put([
        \Illuminate\Support\Facades\Auth::guard('web')->getName()=>$t->bcwActor,
        'cd_session_id'=>(string)$t->bcwSession->getKey()]);$native->save();
    // JSON test requests must send the native session cookie used by the review signature.
    return $t->actingAs($t->bcwOperator)->withCredentials()->withCookie(config('session.cookie'),$native->getId());
}
function bcwRegistry($t,$id):void{
    $native=app('session')->driver();$native->put('cd_session_id',$id);$native->save();$t->withCookie(config('session.cookie'),$native->getId());
}
function bcwWebState():array{
    $out=[];foreach(['institutional_bonus_budgets','institutional_budget_operations','bonus_budget_commitments','bonuses','bonus_ledger_entries',
        'bonus_administrative_operations','wallets','ledger_entries','financial_transactions','purchase_payments','institutional_bonus_obligations','institutional_bonus_refunds','institutional_bonus_settlements','financial_team4_outbox','financial_team4_streams']as$table)
        $out[$table]=DB::connection('sqlsrv')->table($table)->orderBy('id')->get()->toArray();
    return $out;
}
function bcwReviewUrl($t,?string $id=null):string{return route('financial.bonuses.admin.beca.cancel.review',['bonusId'=>$id??$t->bcwIssued['bonus_id']]);}
function bcwReview($t,?string $id=null):array{
    $data=$t->getJson(bcwReviewUrl($t,$id))->assertOk()->json('data');
    $t->withCookie(config('session.cookie'),app('session')->getId());return $data;
}
function bcwCancelBody(array $review):array{return ['budget_version'=>$review['budget_version'],'review_token'=>$review['review_token'],
    'reason'=>'Cancelacion autorizada de prueba','confirm_cancel'=>true];}
function bcwCancel($t,array $body,?string $key=null,?string $id=null,bool $legacy=false){return $t->postJson(
    route($legacy?'financial.bonuses.admin.cancel':'financial.bonuses.admin.beca.cancel',['bonusId'=>$id??$t->bcwIssued['bonus_id']]),$body,
    ['Idempotency-Key'=>$key??'cancel-web-'.Str::uuid()]);}

test('institutional cancellation web requires native login and default authorities never grant access',function(){
    $this->getJson(bcwReviewUrl($this))->assertUnauthorized();bcwCancel($this,[])->assertUnauthorized();
    app()->instance(FinancialWebAuthorizer::class,new \App\Domains\Financial\Adapters\PendingFinancialWebAuthorizer());
    $before=bcwWebState();bcwLogin($this)->getJson(bcwReviewUrl($this))->assertForbidden();expect(bcwWebState())->toEqual($before);
});

test('institutional cancellation review is read only and exposes exact unused and net consumed amounts',function(){
    bcwLogin($this);$before=bcwWebState();
    $r=$this->getJson(bcwReviewUrl($this))->assertOk()->assertHeader('Cache-Control','no-store, private')
        ->assertJsonPath('data.remaining_amount_cents','2000')->assertJsonPath('data.consumed_amount_cents','1000')
        ->assertJsonPath('data.budget_version','3')->assertJsonPath('data.cancellable',true)->assertJsonMissingPath('data.payer_reference');
    expect(strlen($r->json('data.review_token')))->toBe(64)->and(bcwWebState())->toEqual($before);
});

test('institutional cancellation web releases only unused balance and preserves purchases obligations wallet and events',function(){
    bcwLogin($this);$review=bcwReview($this);$wallets=Wallet::orderBy('id')->get()->toArray();
    $debts=DB::connection('sqlsrv')->table('institutional_bonus_obligations')->orderBy('id')->get()->toArray();
    $outbox=DB::connection('sqlsrv')->table('financial_team4_outbox')->orderBy('id')->get()->toArray();
    $response=bcwCancel($this,bcwCancelBody($review))->assertCreated()->assertJsonPath('data.action','CANCEL_BECA')->assertJsonPath('data.released_amount_cents','2000');
    $budget=$this->bcwBudget->fresh();$bonus=Bonus::where('public_id',$this->bcwIssued['bonus_id'])->firstOrFail();
    expect($budget->committed_amount_cents)->toBe(0)->and($budget->consumed_amount_cents)->toBe(1000)
        ->and($bonus->status->value)->toBe('CANCELADO')->and($bonus->remaining_amount_cents)->toBe(0)
        ->and(Wallet::orderBy('id')->get()->toArray())->toEqual($wallets)
        ->and(DB::connection('sqlsrv')->table('institutional_bonus_obligations')->orderBy('id')->get()->toArray())->toEqual($debts)
        ->and(DB::connection('sqlsrv')->table('financial_team4_outbox')->orderBy('id')->get()->toArray())->toEqual($outbox);
    $op=InstitutionalBudgetOperation::where('public_id',$response->json('data.operation_id'))->firstOrFail();
    expect($op->after_data['web_audit']['channel'])->toBe('WEB')->and($op->after_data['web_audit']['identity_session_id'])->toBe((string)$this->bcwSession->getKey())
        ->and(strlen($op->after_data['web_audit']['native_session_hash']))->toBe(64)->and($op->actor_id)->toBe($this->bcwActor);
    Http::assertNothingSent();
});

test('institutional cancellation replay remains original after unrelated budget changes without another release',function(){
    bcwLogin($this);$body=bcwCancelBody(bcwReview($this));$key='cancel-web-'.Str::uuid();
    $first=bcwCancel($this,$body,$key)->assertCreated();
    bcwIssue($this,[...bcwIssuePayload($this),'amount_cents'=>1000],null,$this->bcwBudget->fresh()->version);$before=bcwWebState();
    bcwCancel($this,$body,$key)->assertOk()->assertJsonPath('replayed',true)->assertJsonPath('data.operation_id',$first->json('data.operation_id'));
    expect(bcwWebState())->toEqual($before);
});

test('institutional cancellation replay with changed reason or another request key cannot release again',function(){
    bcwLogin($this);$body=bcwCancelBody(bcwReview($this));$key='cancel-web-'.Str::uuid();bcwCancel($this,$body,$key)->assertCreated();$before=bcwWebState();
    bcwCancel($this,[...$body,'reason'=>'Otro motivo'],$key)->assertConflict();bcwCancel($this,$body)->assertConflict();expect(bcwWebState())->toEqual($before);
});

test('institutional cancellation token binds exact bonus budget version and registered session',function(){
    bcwLogin($this);$body=bcwCancelBody(bcwReview($this));$other=bcwIssue($this,[...bcwIssuePayload($this),'amount_cents'=>1000],null,$this->bcwBudget->fresh()->version);
    $body=bcwCancelBody(bcwReview($this));$before=bcwWebState();
    bcwCancel($this,$body,null,$other['bonus_id'])->assertConflict();
    bcwCancel($this,[...$body,'review_token'=>str_repeat('0',64)])->assertConflict();
    $session=\App\Models\UserSession::create(['user_id'=>$this->bcwActor,'started_at'=>now()]);
    bcwRegistry($this,(string)$session->getKey());bcwCancel($this,$body)->assertConflict();expect(bcwWebState())->toEqual($before);
});

test('institutional cancellation stale version requires explicit new review before releasing changed budget',function(){
    bcwLogin($this);$body=bcwCancelBody(bcwReview($this));bcwIssue($this,[...bcwIssuePayload($this),'amount_cents'=>1000],null,$this->bcwBudget->fresh()->version);$before=bcwWebState();
    bcwCancel($this,$body)->assertConflict();expect(bcwWebState())->toEqual($before);
    bcwCancel($this,bcwCancelBody(bcwReview($this)))->assertCreated();
});

test('institutional cancellation issuer scope and explicit global cancel authority remain separate from view grants',function(){
    $other=User::factory()->create(['email_verified_at'=>now(),'account_activation_pending'=>false,'must_change_password'=>false]);
    $grant=$this->bcwService->issue((string)$other->getKey(),$this->bcwBudget->public_id,$this->bcwBudget->fresh()->version,
        [...bcwIssuePayload($this),'amount_cents'=>1000],'other-issue-'.Str::uuid());
    bcwLogin($this);$this->bcwCancellationRights->viewAll=true;
    $this->getJson(bcwReviewUrl($this,$grant['bonus_id']))->assertNotFound();
    $this->bcwCancellationRights->all=true;$this->bcwBudgetRights->release=false;
    $this->getJson(bcwReviewUrl($this,$grant['bonus_id']))->assertForbidden();
    $this->bcwBudgetRights->release=true;$review=bcwReview($this,$grant['bonus_id']);bcwCancel($this,bcwCancelBody($review),null,$grant['bonus_id'])->assertCreated();
});

test('institutional cancellation requires cancel and exact release rights again after review and successful replay',function(){
    bcwLogin($this);$body=bcwCancelBody(bcwReview($this));$key='cancel-web-'.Str::uuid();$before=bcwWebState();
    $this->bcwCancellationRights->cancel=false;bcwCancel($this,$body,$key)->assertForbidden();
    $this->bcwCancellationRights->cancel=true;$this->bcwBudgetRights->release=false;bcwCancel($this,$body,$key)->assertForbidden();expect(bcwWebState())->toEqual($before);
    $this->bcwBudgetRights->release=true;bcwCancel($this,$body,$key)->assertCreated();$before=bcwWebState();
    $this->bcwBudgetRights->release=false;bcwCancel($this,$body,$key)->assertForbidden();expect(bcwWebState())->toEqual($before);
});

test('institutional cancellation session missing malformed foreign revoked and unconfirmed second factor deny writes',function(){
    bcwLogin($this);$body=bcwCancelBody(bcwReview($this));$before=bcwWebState();
    $foreign=\App\Models\UserSession::create(['user_id'=>$this->bcwBuyer,'started_at'=>now()]);
    foreach([null,'bad',(string)$foreign->getKey()]as$id){bcwRegistry($this,$id);$this->getJson(bcwReviewUrl($this))->assertForbidden();bcwCancel($this,$body)->assertForbidden();}
    $this->bcwSession->update(['revoked_at'=>now()]);bcwLogin($this);assertRevokedSessionDenied(bcwCancel($this,$body));
    $this->bcwSession->update(['revoked_at'=>null]);$this->bcwOperator->forceFill(['two_factor_confirmed_at'=>null])->save();
    bcwLogin($this)->getJson(bcwReviewUrl($this))->assertForbidden();bcwCancel($this,$body)->assertForbidden();expect(bcwWebState())->toEqual($before);
});

test('institutional cancellation malformed consent version selectors and keys reject without evidence',function(){
    bcwLogin($this);$body=bcwCancelBody(bcwReview($this));$before=bcwWebState();
    foreach([['confirm_cancel'=>false],['budget_version'=>'1.5'],['budget_version'=>'9223372036854775808'],['reason'=>''],
        ['actor_id'=>$this->bcwBuyer],['budget_id'=>$this->bcwBudget->public_id],['amount_cents'=>1],['native_session_hash'=>str_repeat('0',64)]]as$change)
        bcwCancel($this,[...$body,...$change])->assertUnprocessable();
    bcwCancel($this,$body,'bad key')->assertUnprocessable();bcwCancel($this,[...$body,'reason'=>"motivo\ninvalido"])->assertConflict();
    expect(bcwWebState())->toEqual($before);
});

test('institutional cancellation corrupted budget prevents preview and execution without automatic repair',function(){
    bcwLogin($this);$body=bcwCancelBody(bcwReview($this));DB::connection('sqlsrv')->table('institutional_bonus_budgets')->where('public_id',$this->bcwBudget->public_id)->increment('committed_amount_cents');$before=bcwWebState();
    $this->getJson(bcwReviewUrl($this))->assertConflict();bcwCancel($this,$body)->assertConflict();expect(bcwWebState())->toEqual($before);
});

test('institutional cancellation expired bonus is shown without a new action and is never expired by review',function(){
    bcwLogin($this);$body=bcwCancelBody(bcwReview($this));$this->travelTo(Bonus::where('public_id',$this->bcwIssued['bonus_id'])->firstOrFail()->expires_at);$before=bcwWebState();
    $review=bcwReview($this);expect($review['cancellable'])->toBeFalse()->and($review['review_token'])->toBeNull();
    bcwCancel($this,$body)->assertConflict();expect(bcwWebState())->toEqual($before);
});

test('institutional cancellation can release unused balance from a suspended budget while preserving consumed exposure',function(){
    app(InstitutionalBudgetService::class)->setSuspended($this->bcwActor,$this->bcwBudget->public_id,$this->bcwBudget->fresh()->version,true,'Revision','suspend-'.Str::uuid());
    bcwLogin($this);bcwCancel($this,bcwCancelBody(bcwReview($this)))->assertCreated();expect($this->bcwBudget->fresh()->status)->toBe('SUSPENDED')->and($this->bcwBudget->fresh()->consumed_amount_cents)->toBe(1000);
});

test('institutional cancellation supports future pending awards but never exhausted ones',function(){
    $future=bcwIssue($this,[...bcwIssuePayload($this),'amount_cents'=>1000,'valid_from'=>now()->addHour()->toISOString()],null,$this->bcwBudget->fresh()->version);
    bcwLogin($this);$review=bcwReview($this,$future['bonus_id']);bcwCancel($this,bcwCancelBody($review),null,$future['bonus_id'])->assertCreated();
    bcwPay($this,2000,null,[...$this->bcwMarket,'order_id'=>'exhaust-'.Str::uuid()]);
    $review=bcwReview($this);expect($review['cancellable'])->toBeFalse()->and($review['remaining_amount_cents'])->toBe('0');
});

test('institutional cancellation audit failure rolls back release and permits same request retry',function(){
    bcwLogin($this);$body=bcwCancelBody(bcwReview($this));$key='cancel-web-'.Str::uuid();$before=bcwWebState();
    $dispatcher=InstitutionalBudgetOperation::getEventDispatcher();InstitutionalBudgetOperation::setEventDispatcher(clone $dispatcher);
    InstitutionalBudgetOperation::creating(function(){throw new RuntimeException('fixture-private-cancellation-audit');});
    try{bcwCancel($this,$body,$key)->assertStatus(503)->assertDontSee('fixture-private-cancellation-audit');}
    finally{InstitutionalBudgetOperation::setEventDispatcher($dispatcher);}
    expect(bcwWebState())->toEqual($before);bcwCancel($this,$body,$key)->assertCreated();
});

test('institutional cancellation ledger failure rolls back budget bonus audit and native trace',function(){
    bcwLogin($this);$body=bcwCancelBody(bcwReview($this));$key='cancel-web-'.Str::uuid();$before=bcwWebState();
    $dispatcher=BonusLedgerEntry::getEventDispatcher();BonusLedgerEntry::setEventDispatcher(clone $dispatcher);
    BonusLedgerEntry::creating(function(){throw new RuntimeException('fixture-private-cancellation-ledger');});
    try{bcwCancel($this,$body,$key)->assertStatus(503)->assertDontSee('fixture-private-cancellation-ledger');}
    finally{BonusLedgerEntry::setEventDispatcher($dispatcher);}
    expect(bcwWebState())->toEqual($before);bcwCancel($this,$body,$key)->assertCreated();
});

test('institutional cancellation legacy endpoint cannot bypass review consent or version',function(){
    bcwLogin($this);$body=bcwCancelBody(bcwReview($this));$before=bcwWebState();bcwCancel($this,['reason'=>'Solo motivo'],null,null,true)->assertUnprocessable();expect(bcwWebState())->toEqual($before);
    bcwCancel($this,$body,null,null,true)->assertCreated()->assertJsonPath('data.action','CANCEL_BECA');
});

test('institutional cancellation listing and persisted review show only explicitly available action and final result',function(){
    bcwLogin($this);$list=$this->getJson(route('financial.bonuses.admin.records'))->assertOk();$row=collect($list->json('data'))->firstWhere('id',$this->bcwIssued['bonus_id']);
    expect($row['can_cancel'])->toBeFalse()->and($row['can_review_institutional_cancel'])->toBeTrue();
    $body=bcwCancelBody(bcwReview($this));bcwCancel($this,$body)->assertCreated();$review=bcwReview($this);
    expect($review['cancellable'])->toBeFalse()->and($review['last_cancellation']['released_amount_cents'])->toBe('2000')
        ->and($review['last_cancellation']['reason'])->toBe($body['reason'])->and($review['consumed_amount_cents'])->toBe('1000');
    $this->bcwBudgetRights->release=false;$list=$this->getJson(route('financial.bonuses.admin.records'))->assertOk();
    expect(collect($list->json('data'))->firstWhere('id',$this->bcwIssued['bonus_id'])['can_review_institutional_cancel'])->toBeFalse();
});

test('institutional cancellation authority outage is sanitized and never grants access',function(){
    bcwLogin($this);$body=bcwCancelBody(bcwReview($this));$this->bcwBudgetRights->unavailable=true;$before=bcwWebState();
    $this->getJson(bcwReviewUrl($this))->assertStatus(503)->assertDontSee('fixture-private-cancel-authority');
    bcwCancel($this,$body)->assertStatus(503)->assertDontSee('fixture-private-cancel-authority');expect(bcwWebState())->toEqual($before);
});

test('institutional cancellation direct callers cannot persist malformed web session audit',function(){
    $before=bcwWebState();$service=app(\App\Domains\Financial\Services\InstitutionalBecaReleaseService::class);
    foreach([[],['identity_session_id'=>'bad','native_session_hash'=>str_repeat('0',64)],
        ['identity_session_id'=>(string)$this->bcwSession->getKey(),'native_session_hash'=>'bad'],
        ['identity_session_id'=>(string)$this->bcwSession->getKey(),'native_session_hash'=>str_repeat('0',64),'actor_id'=>'forged']]as$audit){
        expect(fn()=>$service->cancel($this->bcwActor,$this->bcwBudget->public_id,$this->bcwIssued['bonus_id'],$this->bcwBudget->fresh()->version,
            'Prueba de auditoria','cancel-'.Str::uuid(),$audit))->toThrow(InvalidArgumentException::class);
    }
    expect(bcwWebState())->toEqual($before);
});


test('institutional cancellation broken marker never falls back to legacy cancellation',function(){
    bcwLogin($this);$body=bcwCancelBody(bcwReview($this));
    DB::connection('sqlsrv')->table('bonuses')->where('public_id',$this->bcwIssued['bonus_id'])->update(['institutional_budget_id'=>null]);$before=bcwWebState();
    $r=$this->getJson(route('financial.bonuses.admin.records'))->assertOk();$row=collect($r->json('data'))->firstWhere('id',$this->bcwIssued['bonus_id']);
    expect($row['can_cancel'])->toBeFalse()->and($row['institutional_evidence_incomplete'])->toBeTrue();
    bcwCancel($this,['reason'=>'Solo motivo'],null,null,true)->assertUnprocessable();
    bcwCancel($this,$body,null,null,true)->assertConflict();expect(bcwWebState())->toEqual($before);
});
