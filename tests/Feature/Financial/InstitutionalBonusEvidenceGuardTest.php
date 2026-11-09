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
    $this->iegOperator = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->iegRecipient = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->iegActor = (string) $this->iegOperator->getKey();
    $this->iegAuthority = new class implements InstitutionalBudgetAuthorization {
        public bool $commit = true; public bool $closedRelease = true;
        public function allows(string $actorId, string $action, ?string $budgetId = null): bool { return $action === 'budget.refund.release' ? $this->closedRelease : ($action !== 'budget.commit' || $this->commit); }
    };
    $this->iegAccess = new class implements FinancialWebAuthorizer {
        public bool $issue = true;
        public function allows(string $userId, string $action, ?string $walletId = null): bool { return in_array($action,['bonus.issue','bonus.cancel'],true) && $this->issue; }
    };
    $this->iegIssuer = new class implements BonusAuthorizationProvider {
        public bool $allowed = true; public bool $unavailable = false;
        public function canIssueBonus(string $issuerType, string $issuerId, BonusType $type): bool {
            if ($this->unavailable) throw new IdentityFinancialAuthorizationUnavailable('FIXTURE_UNAVAILABLE');
            return $this->allowed && $issuerType === 'USER' && $type === BonusType::BECA;
        }
    };
    app()->instance(InstitutionalBudgetAuthorization::class, $this->iegAuthority);
    app()->instance(FinancialWebAuthorizer::class, $this->iegAccess);
    app()->instance(BonusAuthorizationProvider::class, $this->iegIssuer);
    $this->iegService = app(InstitutionalBecaIssuanceService::class);
    $data = ['institution_reference' => 'fixture-institution', 'approval_reference' => 'approval-'.Str::uuid(),
        'payer_reference' => 'fixture-approved-payer', 'purpose' => 'Programa BECA', 'approved_amount_cents' => 10000,
        'currency' => 'MXN', 'reason' => 'Aprobacion de prueba', 'valid_from' => now()->subDay()->toISOString(), 'expires_at' => now()->addMonth()->toISOString()];
    $r = app(InstitutionalBudgetService::class)->approve($this->iegActor, $data, 'approve-'.Str::uuid());
    $this->iegBudget = InstitutionalBonusBudget::where('public_id', $r['operation']->budget_id)->firstOrFail();
    $this->iegBuyer = (string)$this->iegRecipient->getKey();
    $this->iegWallet = app(\App\Domains\Financial\Services\WalletService::class)->create('USER',$this->iegBuyer,\App\Domains\Financial\Enums\WalletType::USUARIO);
    $this->iegConsent = new class implements \App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization {
        public bool $pay = true; public bool $refund = true; public bool $closed = true; public bool $unavailable = false;
        public function allows(string $actorId,string $action,array $context): bool {
            if ($this->unavailable) throw new RuntimeException('fixture-consent-unavailable');
            return match($action) {'purchase.pay' => $this->pay && ($context['authorization_reference'] ?? '') === 'fixture-consent', 'purchase.refund' => $this->refund, 'purchase.refund.closed' => $this->closed && ($context['refund_destination'] ?? '') === 'BUDGET_AVAILABLE', default => false};
        }
    };
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization::class,$this->iegConsent);
    $this->iegIssued = iegIssue($this);
    $this->iegMarket = ['business_id'=>'fixture-approved-business','order_id'=>'fixture-order-'.Str::uuid(),'items'=>[]];

    $this->iegPurchase=iegPay($this);
    // External payment follows the purchase; never weaken the production date check.
    $this->travel(1)->seconds();
    $this->iegSettlementAuthority=new class implements \App\Domains\Financial\Contracts\InstitutionalSettlementAuthorization {
        public bool $allowed=true; public bool $unavailable=false;
        public function allows(string $actorId,string $action,array $context): bool {
            if ($this->unavailable) throw new RuntimeException('fixture-payment-verifier-unavailable');
            return $this->allowed && $action==='settlement.record' && ($context['recipient_reference'] ?? '')==='fixture-approved-recipient';
        }
    };
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalSettlementAuthorization::class,$this->iegSettlementAuthority);

});
afterEach(function () {
    InstitutionalBudgetOperation::flushEventListeners(); BonusBudgetCommitment::flushEventListeners(); BonusLedgerEntry::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusObligation::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusRefund::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusSettlement::flushEventListeners();
    while (DB::connection('sqlsrv')->transactionLevel() > 0) DB::connection('sqlsrv')->rollBack();
});
function iegIssuePayload($test): array
{
    return ['award_reference' => 'fixture-award-'.Str::uuid(), 'beneficiary_id' => (string) $test->iegRecipient->getKey(), 'type' => 'BECA', 'amount_cents' => 3000,
        'valid_from' => now()->subMinute()->toISOString(), 'expires_at' => now()->addDay()->toISOString(),
        'combinable' => true, 'allows_partial_use' => true, 'reason' => 'Beca de prueba con presupuesto',
        'external_reference' => 'test-budget-issue-'.Str::uuid(), 'restrictions' => []];
}
function iegIssue($test, ?array $data = null, ?string $key = null, int $version = 1): array
{
    return $test->iegService->issue($test->iegActor, $test->iegBudget->public_id, $version,
        $data ?? iegIssuePayload($test), $key ?? 'issue-'.Str::uuid());
}
function iegState($test): array
{
    return [$test->iegBudget->fresh()->toArray(), Bonus::count(), BonusLedgerEntry::count(), BonusAdministrativeOperation::count(),
        BonusBudgetCommitment::count(), InstitutionalBudgetOperation::count(),
        Wallet::orderBy('id')->get(['public_id','available_balance_cents','held_balance_cents'])->toArray()];
}


function iegPay($test, mixed $amount = 1000, ?string $key = null, ?array $market = null, string $proof = 'fixture-consent'): array
{
    return app(\App\Domains\Financial\Services\PurchasePaymentService::class)->payWithInstitutionalBeca($test->iegWallet,
        $test->iegIssued['bonus_id'],$amount,$key ?? 'pay-'.Str::uuid(),$test->iegBuyer,$proof,$market ?? $test->iegMarket);
}
function iegRefund($test, array $purchase, int $amount, bool $approve = true): \App\Domains\Financial\Models\PurchaseRefundRequest
{
    $service = app(\App\Domains\Financial\Services\PurchaseRefundService::class);
    $payment = \App\Domains\Financial\Models\PurchasePayment::where('public_id',$purchase['purchase_payment_id'])->firstOrFail();
    $request = $service->request($payment,0,[['bonus_id'=>$test->iegIssued['bonus_id'],'amount_cents'=>$amount]],
        'request-'.Str::uuid(),$test->iegBuyer,'Devolucion de prueba institucional');
    return $approve ? $service->approve($request,$test->iegActor,'Revision de prueba') : $request;
}
function iegComplete($test,$refund,?string $key = null) {
    return app(\App\Domains\Financial\Services\PurchaseRefundService::class)->complete($refund,$key ?? 'refund-'.Str::uuid(),$test->iegActor);
}
function iegSpendingState($test): array {
    return [iegState($test),
        \App\Domains\Financial\Models\InstitutionalBonusObligation::orderBy('id')->get()->toArray(),
        \App\Domains\Financial\Models\InstitutionalBonusRefund::orderBy('id')->get()->toArray(),
        \App\Domains\Financial\Models\PurchasePayment::count(),\App\Domains\Financial\Models\FinancialTransaction::count(),
        \App\Domains\Financial\Models\LedgerEntry::count(),DB::connection('sqlsrv')->table('financial_team4_outbox')->orderBy('id')->get()->toArray()];
}


function iegData(int $amount=600): array {
    return ['amount_cents'=>$amount,'currency'=>'MXN','payment_provider'=>'fixture-bank-account',
        'payment_reference'=>'confirmed-payment-'.Str::uuid(),'recipient_reference'=>'fixture-approved-recipient',
        'paid_at'=>now()->toISOString(),'reason'=>'Registro de pago confirmado en prueba'];
}
function iegRecord($test,?array $data=null,?string $key=null,?int $version=null,?string $obligation=null): array {
    return app(\App\Domains\Financial\Services\InstitutionalSettlementService::class)->record($test->iegActor,
        $obligation ?? $test->iegPurchase['obligation_id'],$version ?? $test->iegBudget->fresh()->version,$data ?? iegData(),$key ?? 'settle-'.Str::uuid());
}
function iegSettlementState($test): array {
    return [iegSpendingState($test),\App\Domains\Financial\Models\InstitutionalBonusSettlement::orderBy('id')->get()->toArray()];
}
function iegReport($test): array {
    return app(\App\Domains\Financial\Services\InstitutionalObligationQueryService::class)->report($test->iegBudget->public_id);
}

function iegClose($t,bool $expire=false):void {
    $bonus=Bonus::where('public_id',$t->iegIssued['bonus_id'])->firstOrFail();
    if($expire)$t->travelTo($bonus->expires_at);
    app(\App\Domains\Financial\Services\InstitutionalBecaReleaseService::class)->{$expire?'expire':'cancel'}(
        $t->iegActor,$t->iegBudget->public_id,$bonus->public_id,$t->iegBudget->fresh()->version,'Cierre previo de prueba','close-'.Str::uuid());
}
function iegClosedComplete($t,$r,?string $key=null){return app(\App\Domains\Financial\Services\InstitutionalBecaPurchaseService::class)
    ->completeClosedRefund($r,$key??'closed-refund-'.Str::uuid(),$t->iegActor);}
function iegAll():array {
    $out=[];foreach(['institutional_bonus_budgets','institutional_budget_operations','bonus_budget_commitments','bonuses','bonus_ledger_entries','bonus_restrictions',
        'institutional_bonus_obligations','institutional_bonus_refunds','institutional_bonus_settlements','purchase_payments','purchase_refund_requests',
        'purchase_refund_bonuses','financial_transactions','ledger_entries','wallets','financial_team4_outbox','financial_team4_streams']as$t)
        $out[$t]=DB::connection('sqlsrv')->table($t)->orderBy('id')->get()->toArray();return $out;
}

function iegDamage($t,string $mode):void {
    DB::connection('sqlsrv')->table('bonuses')->where('public_id',$t->iegIssued['bonus_id'])->update(['institutional_budget_id'=>null]);
    if($mode==='commitment-only')DB::connection('sqlsrv')->table('institutional_bonus_obligations')->where('bonus_id',$t->iegIssued['bonus_id'])->delete();
    if($mode==='obligation-only')DB::connection('sqlsrv')->table('bonus_budget_commitments')->where('bonus_id',$t->iegIssued['bonus_id'])->delete();
}
test('institutional evidence blocks legacy lifecycle after marker loss without repairing any data',function(string $mode,string $action){
    $legacy=app(BonusService::class)->issue('USER',$this->iegBuyer,'USER',$this->iegActor,BonusType::BECA,500,now()->subMinute(),now()->addDay(),true,true,'guard-legacy-'.Str::uuid());
    iegDamage($this,$mode);$id=$this->iegIssued['bonus_id'];
    if($action==='expire')$this->travelTo(Bonus::where('public_id',$id)->firstOrFail()->expires_at);
    $before=iegAll();$service=app(BonusService::class);
    $work=fn()=>match($action){
        'consume'=>$service->consume(strtoupper($id),100),
        'cancel'=>$service->cancel($id,$this->iegActor,'Cancelacion heredada'),
        'expire'=>$service->expireIfNeeded($id),
        'restriction'=>$service->addRestriction($id,BonusRestrictionType::NEGOCIO,'fixture-business'),
        'combined'=>$service->consumeMultiple([['bonus_id'=>$legacy->public_id,'amount_cents'=>100],['bonus_id'=>$id,'amount_cents'=>100]]),
    };
    expect($work)->toThrow(ConflictHttpException::class);expect(iegAll())->toEqual($before);Http::assertNothingSent();
})->with(['commitment-only','obligation-only','both'])->with(['consume','cancel','expire','restriction','combined']);
test('institutional evidence prevents legacy refund fallback when marker and obligation are missing',function(){
    $r=iegRefund($this,$this->iegPurchase,400);iegDamage($this,'commitment-only');$before=iegAll();
    expect(fn()=>app(\App\Domains\Financial\Services\PurchaseRefundService::class)->complete($r,'guard-refund-'.Str::uuid(),$this->iegActor))->toThrow(ConflictHttpException::class);
    expect(iegAll())->toEqual($before);
});
test('institutional evidence does not prevent genuine unbacked bonus lifecycle',function(){
    $s=app(BonusService::class);$b=$s->issue('USER',$this->iegBuyer,'USER',$this->iegActor,BonusType::BECA,500,now()->subMinute(),now()->addDay(),true,true,'guard-unbacked-'.Str::uuid());
    $s->addRestriction($b->public_id,BonusRestrictionType::NEGOCIO,'fixture-business');
    expect($s->consume($b->public_id,100,'fixture-business')->remaining_amount_cents)->toBe(400);
    expect($s->cancel($b->public_id,$this->iegActor,'Cierre no institucional')->remaining_amount_cents)->toBe(0);
});
