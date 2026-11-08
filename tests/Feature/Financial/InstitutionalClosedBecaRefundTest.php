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
    $this->crfOperator = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->crfRecipient = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->crfActor = (string) $this->crfOperator->getKey();
    $this->crfAuthority = new class implements InstitutionalBudgetAuthorization {
        public bool $commit = true; public bool $closedRelease = true;
        public function allows(string $actorId, string $action, ?string $budgetId = null): bool { return $action === 'budget.refund.release' ? $this->closedRelease : ($action !== 'budget.commit' || $this->commit); }
    };
    $this->crfAccess = new class implements FinancialWebAuthorizer {
        public bool $issue = true;
        public function allows(string $userId, string $action, ?string $walletId = null): bool { return in_array($action,['bonus.issue','bonus.cancel'],true) && $this->issue; }
    };
    $this->crfIssuer = new class implements BonusAuthorizationProvider {
        public bool $allowed = true; public bool $unavailable = false;
        public function canIssueBonus(string $issuerType, string $issuerId, BonusType $type): bool {
            if ($this->unavailable) throw new IdentityFinancialAuthorizationUnavailable('FIXTURE_UNAVAILABLE');
            return $this->allowed && $issuerType === 'USER' && $type === BonusType::BECA;
        }
    };
    app()->instance(InstitutionalBudgetAuthorization::class, $this->crfAuthority);
    app()->instance(FinancialWebAuthorizer::class, $this->crfAccess);
    app()->instance(BonusAuthorizationProvider::class, $this->crfIssuer);
    $this->crfService = app(InstitutionalBecaIssuanceService::class);
    $data = ['institution_reference' => 'fixture-institution', 'approval_reference' => 'approval-'.Str::uuid(),
        'payer_reference' => 'fixture-approved-payer', 'purpose' => 'Programa BECA', 'approved_amount_cents' => 10000,
        'currency' => 'MXN', 'reason' => 'Aprobacion de prueba', 'valid_from' => now()->subDay()->toISOString(), 'expires_at' => now()->addMonth()->toISOString()];
    $r = app(InstitutionalBudgetService::class)->approve($this->crfActor, $data, 'approve-'.Str::uuid());
    $this->crfBudget = InstitutionalBonusBudget::where('public_id', $r['operation']->budget_id)->firstOrFail();
    $this->crfBuyer = (string)$this->crfRecipient->getKey();
    $this->crfWallet = app(\App\Domains\Financial\Services\WalletService::class)->create('USER',$this->crfBuyer,\App\Domains\Financial\Enums\WalletType::USUARIO);
    $this->crfConsent = new class implements \App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization {
        public bool $pay = true; public bool $refund = true; public bool $closed = true; public bool $unavailable = false;
        public function allows(string $actorId,string $action,array $context): bool {
            if ($this->unavailable) throw new RuntimeException('fixture-consent-unavailable');
            return match($action) {'purchase.pay' => $this->pay && ($context['authorization_reference'] ?? '') === 'fixture-consent', 'purchase.refund' => $this->refund, 'purchase.refund.closed' => $this->closed && ($context['refund_destination'] ?? '') === 'BUDGET_AVAILABLE', default => false};
        }
    };
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization::class,$this->crfConsent);
    $this->crfIssued = crfIssue($this);
    $this->crfMarket = ['business_id'=>'fixture-approved-business','order_id'=>'fixture-order-'.Str::uuid(),'items'=>[]];

    $this->crfPurchase=crfPay($this);
    // External payment follows the purchase; never weaken the production date check.
    $this->travel(1)->seconds();
    $this->crfSettlementAuthority=new class implements \App\Domains\Financial\Contracts\InstitutionalSettlementAuthorization {
        public bool $allowed=true; public bool $unavailable=false;
        public function allows(string $actorId,string $action,array $context): bool {
            if ($this->unavailable) throw new RuntimeException('fixture-payment-verifier-unavailable');
            return $this->allowed && $action==='settlement.record' && ($context['recipient_reference'] ?? '')==='fixture-approved-recipient';
        }
    };
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalSettlementAuthorization::class,$this->crfSettlementAuthority);

});
afterEach(function () {
    InstitutionalBudgetOperation::flushEventListeners(); BonusBudgetCommitment::flushEventListeners(); BonusLedgerEntry::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusObligation::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusRefund::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusSettlement::flushEventListeners();
    while (DB::connection('sqlsrv')->transactionLevel() > 0) DB::connection('sqlsrv')->rollBack();
});
function crfIssuePayload($test): array
{
    return ['award_reference' => 'fixture-award-'.Str::uuid(), 'beneficiary_id' => (string) $test->crfRecipient->getKey(), 'type' => 'BECA', 'amount_cents' => 3000,
        'valid_from' => now()->subMinute()->toISOString(), 'expires_at' => now()->addDay()->toISOString(),
        'combinable' => true, 'allows_partial_use' => true, 'reason' => 'Beca de prueba con presupuesto',
        'external_reference' => 'test-budget-issue-'.Str::uuid(), 'restrictions' => []];
}
function crfIssue($test, ?array $data = null, ?string $key = null, int $version = 1): array
{
    return $test->crfService->issue($test->crfActor, $test->crfBudget->public_id, $version,
        $data ?? crfIssuePayload($test), $key ?? 'issue-'.Str::uuid());
}
function crfState($test): array
{
    return [$test->crfBudget->fresh()->toArray(), Bonus::count(), BonusLedgerEntry::count(), BonusAdministrativeOperation::count(),
        BonusBudgetCommitment::count(), InstitutionalBudgetOperation::count(),
        Wallet::orderBy('id')->get(['public_id','available_balance_cents','held_balance_cents'])->toArray()];
}


function crfPay($test, mixed $amount = 1000, ?string $key = null, ?array $market = null, string $proof = 'fixture-consent'): array
{
    return app(\App\Domains\Financial\Services\PurchasePaymentService::class)->payWithInstitutionalBeca($test->crfWallet,
        $test->crfIssued['bonus_id'],$amount,$key ?? 'pay-'.Str::uuid(),$test->crfBuyer,$proof,$market ?? $test->crfMarket);
}
function crfRefund($test, array $purchase, int $amount, bool $approve = true): \App\Domains\Financial\Models\PurchaseRefundRequest
{
    $service = app(\App\Domains\Financial\Services\PurchaseRefundService::class);
    $payment = \App\Domains\Financial\Models\PurchasePayment::where('public_id',$purchase['purchase_payment_id'])->firstOrFail();
    $request = $service->request($payment,0,[['bonus_id'=>$test->crfIssued['bonus_id'],'amount_cents'=>$amount]],
        'request-'.Str::uuid(),$test->crfBuyer,'Devolucion de prueba institucional');
    return $approve ? $service->approve($request,$test->crfActor,'Revision de prueba') : $request;
}
function crfComplete($test,$refund,?string $key = null) {
    return app(\App\Domains\Financial\Services\PurchaseRefundService::class)->complete($refund,$key ?? 'refund-'.Str::uuid(),$test->crfActor);
}
function crfSpendingState($test): array {
    return [crfState($test),
        \App\Domains\Financial\Models\InstitutionalBonusObligation::orderBy('id')->get()->toArray(),
        \App\Domains\Financial\Models\InstitutionalBonusRefund::orderBy('id')->get()->toArray(),
        \App\Domains\Financial\Models\PurchasePayment::count(),\App\Domains\Financial\Models\FinancialTransaction::count(),
        \App\Domains\Financial\Models\LedgerEntry::count(),DB::connection('sqlsrv')->table('financial_team4_outbox')->orderBy('id')->get()->toArray()];
}


function crfData(int $amount=600): array {
    return ['amount_cents'=>$amount,'currency'=>'MXN','payment_provider'=>'fixture-bank-account',
        'payment_reference'=>'confirmed-payment-'.Str::uuid(),'recipient_reference'=>'fixture-approved-recipient',
        'paid_at'=>now()->toISOString(),'reason'=>'Registro de pago confirmado en prueba'];
}
function crfRecord($test,?array $data=null,?string $key=null,?int $version=null,?string $obligation=null): array {
    return app(\App\Domains\Financial\Services\InstitutionalSettlementService::class)->record($test->crfActor,
        $obligation ?? $test->crfPurchase['obligation_id'],$version ?? $test->crfBudget->fresh()->version,$data ?? crfData(),$key ?? 'settle-'.Str::uuid());
}
function crfSettlementState($test): array {
    return [crfSpendingState($test),\App\Domains\Financial\Models\InstitutionalBonusSettlement::orderBy('id')->get()->toArray()];
}
function crfReport($test): array {
    return app(\App\Domains\Financial\Services\InstitutionalObligationQueryService::class)->report($test->crfBudget->public_id);
}

function crfClose($t,bool $expire=false):void {
    $bonus=Bonus::where('public_id',$t->crfIssued['bonus_id'])->firstOrFail();
    if($expire)$t->travelTo($bonus->expires_at);
    app(\App\Domains\Financial\Services\InstitutionalBecaReleaseService::class)->{$expire?'expire':'cancel'}(
        $t->crfActor,$t->crfBudget->public_id,$bonus->public_id,$t->crfBudget->fresh()->version,'Cierre previo de prueba','close-'.Str::uuid());
}
function crfClosedComplete($t,$r,?string $key=null){return app(\App\Domains\Financial\Services\InstitutionalBecaPurchaseService::class)
    ->completeClosedRefund($r,$key??'closed-refund-'.Str::uuid(),$t->crfActor);}
function crfAll():array {
    $out=[];foreach(['institutional_bonus_budgets','institutional_budget_operations','bonus_budget_commitments','bonuses','bonus_ledger_entries',
        'institutional_bonus_obligations','institutional_bonus_refunds','institutional_bonus_settlements','purchase_payments','purchase_refund_requests',
        'purchase_refund_bonuses','financial_transactions','ledger_entries','wallets','financial_team4_outbox','financial_team4_streams']as$t)
        $out[$t]=DB::connection('sqlsrv')->table($t)->orderBy('id')->get()->toArray();return $out;
}

test('closed beca refund returns unpaid debt to budget with two balanced entries and no buyer credit',function(){
    crfClose($this);$r=crfRefund($this,$this->crfPurchase,400);$wallet=$this->crfWallet->fresh()->toArray();$bonus=Bonus::where('public_id',$this->crfIssued['bonus_id'])->firstOrFail()->toArray();
    $tx=crfClosedComplete($this,$r);$b=$this->crfBudget->fresh();
    expect($b->committed_amount_cents)->toBe(0)->and($b->consumed_amount_cents)->toBe(600)
        ->and($b->approved_amount_cents-$b->committed_amount_cents-$b->consumed_amount_cents)->toBe(9400)
        ->and($this->crfWallet->fresh()->toArray())->toEqual($wallet)
        ->and(Bonus::where('public_id',$this->crfIssued['bonus_id'])->firstOrFail()->toArray())->toEqual($bonus)
        ->and($tx->metadata['refund_destination'])->toBe('BUDGET_AVAILABLE')
        ->and(crfReport($this)['budgets'][0]['observed_closed_refund_release_cents'])->toBe('400');
    $entries=BonusLedgerEntry::where('bonus_id',$this->crfIssued['bonus_id'])->orderByDesc('id')->limit(2)->get();
    expect($entries[0]->amount_cents)->toBe(-400)->and($entries[0]->reference_type)->toBe('BUDGET_REFUND_RELEASE')
        ->and($entries[0]->remaining_after_cents)->toBe(0)->and($entries[1]->amount_cents)->toBe(400)
        ->and($entries[1]->remaining_after_cents)->toBe(400)->and(crfReport($this)['status'])->toBe('CONSISTENT');Http::assertNothingSent();
});
test('expired beca refund can reduce existing debt after budget expiry and suspension without extending validity',function(){
    crfClose($this,true);$this->crfBudget->update(['status'=>'SUSPENDED']);$this->travelTo($this->crfBudget->expires_at);
    $r=crfRefund($this,$this->crfPurchase,1000);crfClosedComplete($this,$r);
    expect(Bonus::where('public_id',$this->crfIssued['bonus_id'])->firstOrFail()->status)->toBe(\App\Domains\Financial\Enums\BonusStatus::EXPIRADO)
        ->and($this->crfBudget->fresh()->status)->toBe('SUSPENDED')->and($this->crfBudget->fresh()->consumed_amount_cents)->toBe(0)
        ->and(crfReport($this)['status'])->toBe('CONSISTENT');
});
test('closed beca refund exact replay preserves original evidence and rejects another key or changed actor',function(){
    crfClose($this);$r=crfRefund($this,$this->crfPurchase,400);$key='closed-'.Str::uuid();$tx=crfClosedComplete($this,$r,$key);$before=crfAll();
    expect(strtolower(crfClosedComplete($this,$r,strtoupper($key))->public_id))->toBe(strtolower($tx->public_id));
    expect(fn()=>crfClosedComplete($this,$r))->toThrow(ConflictHttpException::class);
    expect(fn()=>app(\App\Domains\Financial\Services\InstitutionalBecaPurchaseService::class)->completeClosedRefund($r,$key,(string)$this->crfRecipient->getKey()))->toThrow(ConflictHttpException::class);
    expect(crfAll())->toEqual($before);
});
test('closed beca refund has separate purchase and budget permissions and revocation blocks replay',function(){
    crfClose($this);$r=crfRefund($this,$this->crfPurchase,400);$key='closed-'.Str::uuid();$before=crfAll();
    $this->crfConsent->closed=false;expect(fn()=>crfClosedComplete($this,$r,$key))->toThrow(AuthorizationException::class);
    $this->crfConsent->closed=true;$this->crfAuthority->closedRelease=false;expect(fn()=>crfClosedComplete($this,$r,$key))->toThrow(AuthorizationException::class);
    expect(crfAll())->toEqual($before);$this->crfAuthority->closedRelease=true;crfClosedComplete($this,$r,$key);$before=crfAll();
    $this->crfAuthority->closedRelease=false;expect(fn()=>crfClosedComplete($this,$r,$key))->toThrow(AuthorizationException::class);expect(crfAll())->toEqual($before);
});
test('closed beca refund default bindings deny even when an approved request already exists',function(){
    crfClose($this);$r=crfRefund($this,$this->crfPurchase,400);$before=crfAll();
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization::class,new \App\Domains\Financial\Adapters\PendingInstitutionalPurchaseAuthorization());
    expect(fn()=>crfClosedComplete($this,$r))->toThrow(AuthorizationException::class);expect(crfAll())->toEqual($before);
});
test('closed beca refund refuses active bonus and legacy completion still refuses terminal bonus',function(){
    $r=crfRefund($this,$this->crfPurchase,400);$before=crfAll();expect(fn()=>crfClosedComplete($this,$r))->toThrow(ConflictHttpException::class);expect(crfAll())->toEqual($before);
    crfClose($this);$before=crfAll();expect(fn()=>crfComplete($this,$r))->toThrow(ConflictHttpException::class);expect(crfAll())->toEqual($before);
});
test('closed beca refund requires prior approval and never completes pending or rejected requests',function(){
    crfClose($this);$r=crfRefund($this,$this->crfPurchase,400,false);$before=crfAll();
    expect(fn()=>crfClosedComplete($this,$r))->toThrow(ConflictHttpException::class);expect(crfAll())->toEqual($before);
    app(\App\Domains\Financial\Services\PurchaseRefundService::class)->reject($r,$this->crfActor,'Rechazo de prueba');$before=crfAll();
    expect(fn()=>crfClosedComplete($this,$r))->toThrow(ConflictHttpException::class);expect(crfAll())->toEqual($before);
});
test('closed beca refund only allows the remaining unpaid portion and preserves external settlements',function(){
    $settlement=crfRecord($this,crfData(600));crfClose($this);$r=crfRefund($this,$this->crfPurchase,400);crfClosedComplete($this,$r);
    $debt=\App\Domains\Financial\Models\InstitutionalBonusObligation::where('public_id',$this->crfPurchase['obligation_id'])->firstOrFail();
    expect($debt->settled_amount_cents)->toBe(600)->and($debt->refunded_amount_cents)->toBe(400)
        ->and($this->crfBudget->fresh()->consumed_amount_cents)->toBe(600)->and(crfReport($this)['status'])->toBe('CONSISTENT');
    expect(fn()=>crfRefund($this,$this->crfPurchase,1))->toThrow(InvalidArgumentException::class);
});
test('closed beca refund audit failure rolls back the complete adjustment and permits same key retry',function(){
    crfClose($this);$r=crfRefund($this,$this->crfPurchase,400);$key='closed-'.Str::uuid();$before=crfAll();
    InstitutionalBudgetOperation::creating(function($op){if($op->action==='REFUND_CLOSED_BECA')throw new RuntimeException('fixture-audit-failure');});
    try{expect(fn()=>crfClosedComplete($this,$r,$key))->toThrow(RuntimeException::class);}finally{InstitutionalBudgetOperation::flushEventListeners();}
    expect(crfAll())->toEqual($before);crfClosedComplete($this,$r,$key);expect(crfReport($this)['status'])->toBe('CONSISTENT');
});
test('closed beca refund release movement failure rolls back both ledger entries and the outbox',function(){
    crfClose($this);$r=crfRefund($this,$this->crfPurchase,400);$key='closed-'.Str::uuid();$before=crfAll();
    BonusLedgerEntry::creating(function($e){if($e->reference_type==='BUDGET_REFUND_RELEASE')throw new RuntimeException('fixture-ledger-failure');});
    try{expect(fn()=>crfClosedComplete($this,$r,$key))->toThrow(RuntimeException::class);}finally{BonusLedgerEntry::flushEventListeners();}
    expect(crfAll())->toEqual($before);crfClosedComplete($this,$r,$key);expect(crfReport($this)['status'])->toBe('CONSISTENT');
});
test('closed beca refund two partial adjustments preserve terminal state and emit delta amounts once',function(){
    crfClose($this);$a=crfRefund($this,$this->crfPurchase,400);crfClosedComplete($this,$a);
    $b=crfRefund($this,$this->crfPurchase,600);$key='closed-'.Str::uuid();crfClosedComplete($this,$b,$key);$before=crfAll();crfClosedComplete($this,$b,$key);expect(crfAll())->toEqual($before);
    expect($this->crfBudget->fresh()->consumed_amount_cents)->toBe(0)->and($this->crfBudget->fresh()->committed_amount_cents)->toBe(0)
        ->and(Bonus::where('public_id',$this->crfIssued['bonus_id'])->firstOrFail()->remaining_amount_cents)->toBe(0)->and(crfReport($this)['status'])->toBe('CONSISTENT');
    $events=DB::connection('sqlsrv')->table('financial_team4_outbox')->orderBy('id')->get();expect($events)->toHaveCount(3);
    $first=json_decode($events[1]->body,true);$second=json_decode($events[2]->body,true);
    expect($first['status'])->toBe('PARTIALLY_REFUNDED')->and($first['amount'])->toEqual(4)->and($second['status'])->toBe('REFUNDED')->and($second['amount'])->toEqual(6);
});
test('closed beca refund missing closing evidence is detected by report and blocks replay and other writers',function(){
    crfClose($this);$r=crfRefund($this,$this->crfPurchase,400);$key='closed-'.Str::uuid();crfClosedComplete($this,$r,$key);
    DB::connection('sqlsrv')->table('bonus_ledger_entries')->where('reference_type','BUDGET_REFUND_RELEASE')->delete();$before=crfAll();
    expect(crfReport($this)['status'])->toBe('DIFFERENCES');expect(fn()=>crfClosedComplete($this,$r,$key))->toThrow(ConflictHttpException::class);
    expect(fn()=>app(InstitutionalBudgetService::class)->setApprovedAmount($this->crfActor,$this->crfBudget->public_id,$this->crfBudget->fresh()->version,11000,'Ajuste de prueba','adjust-'.Str::uuid()))->toThrow(ConflictHttpException::class);
    expect(crfAll())->toEqual($before);
});
test('closed beca refund destination corruption is never accepted as normal bonus restoration',function(){
    crfClose($this);$r=crfRefund($this,$this->crfPurchase,400);$key='closed-'.Str::uuid();crfClosedComplete($this,$r,$key);
    $op=InstitutionalBudgetOperation::where('idempotency_key',$key)->firstOrFail();$data=$op->after_data;$data['released_refund_amount_cents']=399;
    DB::connection('sqlsrv')->table('institutional_budget_operations')->where('public_id',$op->public_id)->update(['after_data'=>json_encode($data)]);$before=crfAll();
    expect(crfReport($this)['status'])->toBe('DIFFERENCES');expect(fn()=>crfClosedComplete($this,$r,$key))->toThrow(ConflictHttpException::class);expect(crfAll())->toEqual($before);
});
test('closed beca refund command previews without writes and requires explicit actor key and authorization',function(){
    crfClose($this);$r=crfRefund($this,$this->crfPurchase,400);$before=crfAll();
    $this->artisan('financial:complete-closed-beca-refund',['refundId'=>$r->public_id])->assertSuccessful();expect(crfAll())->toEqual($before);
    $this->artisan('financial:complete-closed-beca-refund',['refundId'=>$r->public_id,'--confirm'=>true])->assertExitCode(2);
    $this->crfConsent->closed=false;
    $this->artisan('financial:complete-closed-beca-refund',['refundId'=>$r->public_id,'--actor'=>$this->crfActor,'--key'=>'closed-'.Str::uuid(),'--confirm'=>true])->assertFailed();expect(crfAll())->toEqual($before);
    $this->crfConsent->closed=true;$key='closed-'.Str::uuid();
    $this->artisan('financial:complete-closed-beca-refund',['refundId'=>$r->public_id,'--actor'=>$this->crfActor,'--key'=>$key,'--confirm'=>true])->assertSuccessful();
    expect(crfReport($this)['status'])->toBe('CONSISTENT');
});
test('closed beca refund can follow an earlier live refund without double restoration',function(){
    $a=crfRefund($this,$this->crfPurchase,200);crfComplete($this,$a);crfClose($this);
    $b=crfRefund($this,$this->crfPurchase,300);crfClosedComplete($this,$b);
    expect($this->crfBudget->fresh()->consumed_amount_cents)->toBe(500)->and($this->crfBudget->fresh()->committed_amount_cents)->toBe(0)
        ->and(crfReport($this)['status'])->toBe('CONSISTENT');
});
test('closed beca refund cannot release a live exhausted award but can return unpaid debt after its expiry',function(){
    crfPay($this,2000,null,['business_id'=>'fixture-approved-business','order_id'=>'second-order-'.Str::uuid(),'items'=>[]]);
    $r=crfRefund($this,$this->crfPurchase,400);$before=crfAll();expect(fn()=>crfClosedComplete($this,$r))->toThrow(ConflictHttpException::class);expect(crfAll())->toEqual($before);
    $this->travelTo(Bonus::where('public_id',$this->crfIssued['bonus_id'])->firstOrFail()->expires_at);
    crfClosedComplete($this,$r);$bonus=Bonus::where('public_id',$this->crfIssued['bonus_id'])->firstOrFail();
    expect($bonus->status)->toBe(\App\Domains\Financial\Enums\BonusStatus::AGOTADO)->and($bonus->remaining_amount_cents)->toBe(0)
        ->and($this->crfBudget->fresh()->consumed_amount_cents)->toBe(2600)->and($this->crfBudget->fresh()->committed_amount_cents)->toBe(0)
        ->and(crfReport($this)['status'])->toBe('CONSISTENT');
});
test('closed beca refund outbox rejection rolls back debt budget both movements and completed status',function(){
    crfClose($this);$r=crfRefund($this,$this->crfPurchase,400);$key='closed-'.Str::uuid();
    DB::connection('sqlsrv')->table('financial_team4_streams')->where('payment_intent_id',strtolower($this->crfPurchase['purchase_payment_id']))->update(['order_id'=>'CORRUPTED-FIXTURE']);
    $before=crfAll();expect(fn()=>crfClosedComplete($this,$r,$key))->toThrow(ConflictHttpException::class);expect(crfAll())->toEqual($before);
    DB::connection('sqlsrv')->table('financial_team4_streams')->where('payment_intent_id',strtolower($this->crfPurchase['purchase_payment_id']))->update(['order_id'=>$this->crfMarket['order_id']]);
    crfClosedComplete($this,$r,$key);expect(crfReport($this)['status'])->toBe('CONSISTENT');
});
