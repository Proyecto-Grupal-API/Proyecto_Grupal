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
    $this->bpOperator = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->bpRecipient = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->bpActor = (string) $this->bpOperator->getKey();
    $this->bpAuthority = new class implements InstitutionalBudgetAuthorization {
        public bool $commit = true;
        public function allows(string $actorId, string $action, ?string $budgetId = null): bool { return $action !== 'budget.commit' || $this->commit; }
    };
    $this->bpAccess = new class implements FinancialWebAuthorizer {
        public bool $issue = true;
        public function allows(string $userId, string $action, ?string $walletId = null): bool { return in_array($action,['bonus.issue','bonus.cancel'],true) && $this->issue; }
    };
    $this->bpIssuer = new class implements BonusAuthorizationProvider {
        public bool $allowed = true; public bool $unavailable = false;
        public function canIssueBonus(string $issuerType, string $issuerId, BonusType $type): bool {
            if ($this->unavailable) throw new IdentityFinancialAuthorizationUnavailable('FIXTURE_UNAVAILABLE');
            return $this->allowed && $issuerType === 'USER' && $type === BonusType::BECA;
        }
    };
    app()->instance(InstitutionalBudgetAuthorization::class, $this->bpAuthority);
    app()->instance(FinancialWebAuthorizer::class, $this->bpAccess);
    app()->instance(BonusAuthorizationProvider::class, $this->bpIssuer);
    $this->bpService = app(InstitutionalBecaIssuanceService::class);
    $data = ['institution_reference' => 'fixture-institution', 'approval_reference' => 'approval-'.Str::uuid(),
        'payer_reference' => 'fixture-approved-payer', 'purpose' => 'Programa BECA', 'approved_amount_cents' => 10000,
        'currency' => 'MXN', 'reason' => 'Aprobacion de prueba', 'valid_from' => now()->subDay()->toISOString(), 'expires_at' => now()->addMonth()->toISOString()];
    $r = app(InstitutionalBudgetService::class)->approve($this->bpActor, $data, 'approve-'.Str::uuid());
    $this->bpBudget = InstitutionalBonusBudget::where('public_id', $r['operation']->budget_id)->firstOrFail();
    $this->bpBuyer = (string)$this->bpRecipient->getKey();
    $this->bpWallet = app(\App\Domains\Financial\Services\WalletService::class)->create('USER',$this->bpBuyer,\App\Domains\Financial\Enums\WalletType::USUARIO);
    $this->bpConsent = new class implements \App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization {
        public bool $pay = true; public bool $refund = true; public bool $unavailable = false;
        public function allows(string $actorId,string $action,array $context): bool {
            if ($this->unavailable) throw new RuntimeException('fixture-consent-unavailable');
            return $action === 'purchase.pay' ? $this->pay && ($context['authorization_reference'] ?? '') === 'fixture-consent' : $this->refund;
        }
    };
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization::class,$this->bpConsent);
    $this->bpIssued = bpIssue($this);
    $this->bpMarket = ['business_id'=>'fixture-approved-business','order_id'=>'fixture-order-'.Str::uuid(),'items'=>[]];

});
afterEach(function () {
    InstitutionalBudgetOperation::flushEventListeners(); BonusBudgetCommitment::flushEventListeners(); BonusLedgerEntry::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusObligation::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusRefund::flushEventListeners();
    while (DB::connection('sqlsrv')->transactionLevel() > 0) DB::connection('sqlsrv')->rollBack();
});
function bpIssuePayload($test): array
{
    return ['award_reference' => 'fixture-award-'.Str::uuid(), 'beneficiary_id' => (string) $test->bpRecipient->getKey(), 'type' => 'BECA', 'amount_cents' => 3000,
        'valid_from' => now()->subMinute()->toISOString(), 'expires_at' => now()->addDay()->toISOString(),
        'combinable' => true, 'allows_partial_use' => true, 'reason' => 'Beca de prueba con presupuesto',
        'external_reference' => 'test-budget-issue-'.Str::uuid(), 'restrictions' => []];
}
function bpIssue($test, ?array $data = null, ?string $key = null, int $version = 1): array
{
    return $test->bpService->issue($test->bpActor, $test->bpBudget->public_id, $version,
        $data ?? bpIssuePayload($test), $key ?? 'issue-'.Str::uuid());
}
function bpState($test): array
{
    return [$test->bpBudget->fresh()->toArray(), Bonus::count(), BonusLedgerEntry::count(), BonusAdministrativeOperation::count(),
        BonusBudgetCommitment::count(), InstitutionalBudgetOperation::count(),
        Wallet::orderBy('id')->get(['public_id','available_balance_cents','held_balance_cents'])->toArray()];
}


function bpPay($test, mixed $amount = 1000, ?string $key = null, ?array $market = null, string $proof = 'fixture-consent'): array
{
    return app(\App\Domains\Financial\Services\PurchasePaymentService::class)->payWithInstitutionalBeca($test->bpWallet,
        $test->bpIssued['bonus_id'],$amount,$key ?? 'pay-'.Str::uuid(),$test->bpBuyer,$proof,$market ?? $test->bpMarket);
}
function bpRefund($test, array $purchase, int $amount, bool $approve = true): \App\Domains\Financial\Models\PurchaseRefundRequest
{
    $service = app(\App\Domains\Financial\Services\PurchaseRefundService::class);
    $payment = \App\Domains\Financial\Models\PurchasePayment::where('public_id',$purchase['purchase_payment_id'])->firstOrFail();
    $request = $service->request($payment,0,[['bonus_id'=>$test->bpIssued['bonus_id'],'amount_cents'=>$amount]],
        'request-'.Str::uuid(),$test->bpBuyer,'Devolucion de prueba institucional');
    return $approve ? $service->approve($request,$test->bpActor,'Revision de prueba') : $request;
}
function bpComplete($test,$refund,?string $key = null) {
    return app(\App\Domains\Financial\Services\PurchaseRefundService::class)->complete($refund,$key ?? 'refund-'.Str::uuid(),$test->bpActor);
}
function bpSpendingState($test): array {
    return [bpState($test),
        \App\Domains\Financial\Models\InstitutionalBonusObligation::orderBy('id')->get()->toArray(),
        \App\Domains\Financial\Models\InstitutionalBonusRefund::orderBy('id')->get()->toArray(),
        \App\Domains\Financial\Models\PurchasePayment::count(),\App\Domains\Financial\Models\FinancialTransaction::count(),
        \App\Domains\Financial\Models\LedgerEntry::count(),DB::connection('sqlsrv')->table('financial_team4_outbox')->orderBy('id')->get()->toArray()];
}

test('institutional purchase atomically creates purchase debt budget consumption and event with no cash debit', function () {
    $wallet = $this->bpWallet->fresh()->toArray(); $ledger = \App\Domains\Financial\Models\LedgerEntry::count();
    $result = bpPay($this);
    $budget = $this->bpBudget->fresh(); $bonus = Bonus::where('public_id',$this->bpIssued['bonus_id'])->firstOrFail();
    $debt = \App\Domains\Financial\Models\InstitutionalBonusObligation::where('public_id',$result['obligation_id'])->firstOrFail();
    expect($budget->committed_amount_cents)->toBe(2000)->and($budget->consumed_amount_cents)->toBe(1000)
        ->and($budget->version)->toBe(3)->and($bonus->remaining_amount_cents)->toBe(2000)
        ->and($debt->amount_cents)->toBe(1000)->and($debt->refunded_amount_cents)->toBe(0)
        ->and($debt->business_id)->toBe('fixture-approved-business')->and($debt->payer_reference)->toBe('fixture-approved-payer')
        ->and($this->bpWallet->fresh()->toArray())->toBe($wallet)->and(\App\Domains\Financial\Models\LedgerEntry::count())->toBe($ledger);
    $body = DB::connection('sqlsrv')->table('financial_team4_outbox')->orderByDesc('id')->value('body');
    expect(json_decode($body,true)['payment_intent_id'])->toBe($result['purchase_payment_id'])
        ->and(json_decode($body,true)['status'])->toBe('PAID'); Http::assertNothingSent();
});

test('institutional purchase is visible in the existing personal receipt without inventing wallet entries', function () {
    $result = bpPay($this);
    $receipt = app(\App\Domains\Financial\Services\PersonalReceiptQueryService::class)->purchase($this->bpWallet,$result['purchase_payment_id']);
    expect($receipt['entries'])->toBe([])->and($receipt['purchase']['bonus_amount_cents'])->toBe(1000)
        ->and($receipt['purchase']['wallet_amount_cents'])->toBe(0);
});

test('institutional purchase exact replay preserves debt balances and event and rejects altered consent or amount', function () {
    $key = 'pay-'.Str::uuid(); $a = bpPay($this,1000,$key); $before = bpSpendingState($this);
    $b = bpPay($this,1000,strtoupper($key));
    expect($b['purchase_payment_id'])->toBe($a['purchase_payment_id'])->and($b['replayed'])->toBeTrue();
    expect(fn () => bpPay($this,1001,$key))->toThrow(ConflictHttpException::class);
    expect(fn () => bpPay($this,1000,$key,null,'forged-proof'))->toThrow(AuthorizationException::class);
    expect(bpSpendingState($this))->toEqual($before);
});

test('institutional purchase cannot charge an order twice with a new key', function () {
    bpPay($this); $before = bpSpendingState($this);
    expect(fn () => bpPay($this))->toThrow(ConflictHttpException::class);
    expect(bpSpendingState($this))->toEqual($before);
});

test('institutional purchase default authorization and revoked consent deny before changing money', function () {
    $before = bpSpendingState($this); $this->bpConsent->pay = false;
    expect(fn () => bpPay($this))->toThrow(AuthorizationException::class);
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization::class,
        new \App\Domains\Financial\Adapters\PendingInstitutionalPurchaseAuthorization());
    expect(fn () => bpPay($this))->toThrow(AuthorizationException::class);
    expect(bpSpendingState($this))->toEqual($before);
});

test('institutional purchase refuses insufficient bonus partial use restriction and suspended budget', function () {
    $before = bpSpendingState($this);
    expect(fn () => bpPay($this,3001))->toThrow(ConflictHttpException::class);
    expect(bpSpendingState($this))->toEqual($before);
    $bonus = Bonus::where('public_id',$this->bpIssued['bonus_id'])->firstOrFail(); $bonus->update(['allows_partial_use'=>false]);
    expect(fn () => bpPay($this,1000))->toThrow(ConflictHttpException::class); $bonus->update(['allows_partial_use'=>true]);
    $this->bpBudget->update(['status'=>'SUSPENDED']); $before = bpSpendingState($this);
    expect(fn () => bpPay($this))->toThrow(ConflictHttpException::class); expect(bpSpendingState($this))->toEqual($before);
});

test('institutional purchase validates owner amount and dependency failures without a partial purchase', function () {
    $before = bpSpendingState($this);
    foreach ([0,-1,1.5,'100',true] as $amount) expect(fn () => bpPay($this,$amount))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(\App\Domains\Financial\Services\PurchasePaymentService::class)->payWithInstitutionalBeca($this->bpWallet,
        $this->bpIssued['bonus_id'],1000,'pay-'.Str::uuid(),$this->bpActor,'fixture-consent',$this->bpMarket))->toThrow(AuthorizationException::class);
    $this->bpConsent->unavailable = true; expect(fn () => bpPay($this))->toThrow(RuntimeException::class);
    expect(bpSpendingState($this))->toEqual($before);
});

test('institutional purchase debt persistence failure rolls back budget bonus purchase and event and permits retry', function () {
    $before = bpSpendingState($this); $key = 'pay-'.Str::uuid();
    \App\Domains\Financial\Models\InstitutionalBonusObligation::creating(function () { throw new RuntimeException('fixture-debt'); });
    try { expect(fn () => bpPay($this,1000,$key))->toThrow(RuntimeException::class); }
    finally { \App\Domains\Financial\Models\InstitutionalBonusObligation::flushEventListeners(); }
    expect(bpSpendingState($this))->toEqual($before); expect(bpPay($this,1000,$key)['replayed'])->toBeFalse();
});

test('institutional purchase ledger persistence failure rolls back every financial record', function () {
    $before = bpSpendingState($this);
    BonusLedgerEntry::creating(function ($entry) { if ($entry->reference_type === 'BUDGET_PURCHASE') throw new RuntimeException('fixture-purchase-ledger'); });
    try { expect(fn () => bpPay($this))->toThrow(RuntimeException::class); }
    finally { BonusLedgerEntry::flushEventListeners(); }
    expect(bpSpendingState($this))->toEqual($before);
});

test('institutional purchase partial refund restores the original bonus and reduces debt without wallet credit', function () {
    $purchase = bpPay($this); $refund = bpRefund($this,$purchase,400); $wallet = $this->bpWallet->fresh()->toArray();
    $key = 'refund-'.Str::uuid(); $tx = bpComplete($this,$refund,$key); $before = bpSpendingState($this);
    expect($this->bpBudget->fresh()->committed_amount_cents)->toBe(2400)->and($this->bpBudget->fresh()->consumed_amount_cents)->toBe(600)
        ->and(Bonus::where('public_id',$this->bpIssued['bonus_id'])->firstOrFail()->remaining_amount_cents)->toBe(2400)
        ->and(\App\Domains\Financial\Models\InstitutionalBonusObligation::where('public_id',$purchase['obligation_id'])->firstOrFail()->refunded_amount_cents)->toBe(400)
        ->and($this->bpWallet->fresh()->toArray())->toBe($wallet);
    expect(strtolower(bpComplete($this,$refund,strtoupper($key))->public_id))->toBe(strtolower($tx->public_id));
    expect(bpSpendingState($this))->toEqual($before);
});

test('institutional purchase fully consumed bonus can be restored by approved refund once', function () {
    $purchase = bpPay($this,3000); $refund = bpRefund($this,$purchase,3000); bpComplete($this,$refund);
    expect(Bonus::where('public_id',$this->bpIssued['bonus_id'])->firstOrFail()->remaining_amount_cents)->toBe(3000)
        ->and($this->bpBudget->fresh()->consumed_amount_cents)->toBe(0)->and($this->bpBudget->fresh()->committed_amount_cents)->toBe(3000);
    expect(fn () => bpComplete($this,$refund))->toThrow(ConflictHttpException::class);
});

test('institutional purchase pending refund and revoked refund permission cannot restore funds', function () {
    $purchase = bpPay($this); $refund = bpRefund($this,$purchase,100,false); $before = bpSpendingState($this);
    expect(fn () => bpComplete($this,$refund))->toThrow(ConflictHttpException::class);
    $this->bpConsent->refund = false; expect(fn () => bpComplete($this,$refund))->toThrow(AuthorizationException::class);
    expect(bpSpendingState($this))->toEqual($before);
});

test('institutional purchase expired or cancelled bonus cannot be resurrected by a refund', function () {
    $purchase = bpPay($this); $refund = bpRefund($this,$purchase,100); $bonus = Bonus::where('public_id',$this->bpIssued['bonus_id'])->firstOrFail();
    $this->travelTo($bonus->expires_at); $before = bpSpendingState($this);
    expect(fn () => bpComplete($this,$refund))->toThrow(ConflictHttpException::class); expect(bpSpendingState($this))->toEqual($before);
});

test('institutional purchase refund audit failure rolls back debt budget bonus transaction and permits retry', function () {
    $purchase = bpPay($this); $refund = bpRefund($this,$purchase,200); $before = bpSpendingState($this); $key = 'refund-'.Str::uuid();
    InstitutionalBudgetOperation::creating(function ($op) { if ($op->action === 'REFUND_BECA') throw new RuntimeException('fixture-refund-audit'); });
    try { expect(fn () => bpComplete($this,$refund,$key))->toThrow(RuntimeException::class); }
    finally { InstitutionalBudgetOperation::flushEventListeners(); }
    expect(bpSpendingState($this))->toEqual($before); bpComplete($this,$refund,$key);
});

test('institutional purchase corruption prevents new consumption and replay instead of repairing a debt', function () {
    $key = 'pay-'.Str::uuid(); $purchase = bpPay($this,1000,$key);
    DB::connection('sqlsrv')->table('institutional_bonus_obligations')->where('public_id',$purchase['obligation_id'])->update(['refunded_amount_cents'=>1]);
    $before = bpSpendingState($this);
    expect(fn () => bpPay($this,1000,$key))->toThrow(ConflictHttpException::class);
    expect(bpSpendingState($this))->toEqual($before);
});

test('institutional purchase cancellation releases only the unused amount while preserving net merchant debt', function () {
    $purchase = bpPay($this); $key = 'release-'.Str::uuid();
    app(\App\Domains\Financial\Services\InstitutionalBecaReleaseService::class)->cancel($this->bpActor,$this->bpBudget->public_id,
        $this->bpIssued['bonus_id'],3,'Liberar remanente',$key);
    expect($this->bpBudget->fresh()->committed_amount_cents)->toBe(0)->and($this->bpBudget->fresh()->consumed_amount_cents)->toBe(1000)
        ->and(\App\Domains\Financial\Models\InstitutionalBonusObligation::where('public_id',$purchase['obligation_id'])->firstOrFail()->refunded_amount_cents)->toBe(0);
    $before = bpSpendingState($this);
    app(\App\Domains\Financial\Services\InstitutionalBecaReleaseService::class)->cancel($this->bpActor,$this->bpBudget->public_id,
        $this->bpIssued['bonus_id'],3,'Liberar remanente',$key);
    expect(bpSpendingState($this))->toEqual($before);
});

test('institutional purchase expiry releases the remainder after a partial refund and preserves consumed exposure', function () {
    $purchase = bpPay($this); $refund = bpRefund($this,$purchase,400); bpComplete($this,$refund);
    $this->travelTo(Bonus::where('public_id',$this->bpIssued['bonus_id'])->firstOrFail()->expires_at);
    app(\App\Domains\Financial\Services\InstitutionalBecaReleaseService::class)->expire($this->bpActor,$this->bpBudget->public_id,
        $this->bpIssued['bonus_id'],4,'Vencimiento del remanente','expire-'.Str::uuid());
    expect($this->bpBudget->fresh()->committed_amount_cents)->toBe(0)->and($this->bpBudget->fresh()->consumed_amount_cents)->toBe(600);
});

test('institutional purchase two partial refunds cannot restore more than the original paid amount', function () {
    $purchase = bpPay($this); $first = bpRefund($this,$purchase,400); bpComplete($this,$first);
    $second = bpRefund($this,$purchase,600); bpComplete($this,$second);
    expect($this->bpBudget->fresh()->consumed_amount_cents)->toBe(0)
        ->and(\App\Domains\Financial\Models\InstitutionalBonusObligation::where('public_id',$purchase['obligation_id'])->firstOrFail()->refunded_amount_cents)->toBe(1000);
    $before = bpSpendingState($this);
    expect(fn () => bpRefund($this,$purchase,1))->toThrow(InvalidArgumentException::class);
    expect(bpSpendingState($this))->toEqual($before);
});

test('institutional purchase a new order shares only the same original committed amount', function () {
    bpPay($this,1000);
    bpPay($this,2000,null,[...$this->bpMarket,'order_id'=>'second-order-'.Str::uuid()]);
    expect($this->bpBudget->fresh()->consumed_amount_cents)->toBe(3000)
        ->and($this->bpBudget->fresh()->committed_amount_cents)->toBe(0);
    $before = bpSpendingState($this);
    expect(fn () => bpPay($this,1,null,[...$this->bpMarket,'order_id'=>'third-order-'.Str::uuid()]))->toThrow(ConflictHttpException::class);
    expect(bpSpendingState($this))->toEqual($before);
});

test('institutional purchase refund cannot bypass merchant debt evidence through the legacy flow', function () {
    $purchase = bpPay($this); $refund = bpRefund($this,$purchase,100);
    DB::connection('sqlsrv')->table('institutional_bonus_obligations')->where('public_id',$purchase['obligation_id'])->delete();
    $before = bpSpendingState($this);
    expect(fn () => bpComplete($this,$refund))->toThrow(ConflictHttpException::class);
    expect(bpSpendingState($this))->toEqual($before);
});

test('institutional purchase enforces stored business restrictions before consuming the bonus', function () {
    \App\Domains\Financial\Models\BonusRestriction::create(['public_id'=>(string)Str::uuid(),'bonus_id'=>$this->bpIssued['bonus_id'],
        'restriction_type'=>BonusRestrictionType::NEGOCIO,'target_id'=>'other-approved-business']);
    $before = bpSpendingState($this);
    expect(fn () => bpPay($this))->toThrow(ConflictHttpException::class);
    expect(bpSpendingState($this))->toEqual($before);
});

test('institutional purchase held request lock excludes a second execution', function () {
    $key = 'pay-'.Str::uuid(); $lockKey = 'institution-budget:'.hash('sha256',$key);
    $locks = app(\App\Domains\Financial\Support\FinancialJobLock::class); $token = $locks->acquire($lockKey,180);
    expect($token)->not->toBeNull(); $before = bpSpendingState($this);
    try { expect(fn () => bpPay($this,1000,$key))->toThrow(ConflictHttpException::class); }
    finally { $locks->release($lockKey,$token); }
    expect(bpSpendingState($this))->toEqual($before);
});

test('institutional purchase revoked consent also prevents replay of a previous payment', function () {
    $key = 'pay-'.Str::uuid(); bpPay($this,1000,$key); $before = bpSpendingState($this);
    $this->bpConsent->pay = false;
    expect(fn () => bpPay($this,1000,$key))->toThrow(AuthorizationException::class);
    expect(bpSpendingState($this))->toEqual($before);
});

test('institutional purchase cancelled remainder is not resurrected by a later approved refund', function () {
    $purchase = bpPay($this); $refund = bpRefund($this,$purchase,100);
    app(\App\Domains\Financial\Services\InstitutionalBecaReleaseService::class)->cancel($this->bpActor,$this->bpBudget->public_id,
        $this->bpIssued['bonus_id'],3,'Cancelar remanente','cancel-'.Str::uuid());
    $before = bpSpendingState($this);
    expect(fn () => bpComplete($this,$refund))->toThrow(ConflictHttpException::class);
    expect(bpSpendingState($this))->toEqual($before);
});
