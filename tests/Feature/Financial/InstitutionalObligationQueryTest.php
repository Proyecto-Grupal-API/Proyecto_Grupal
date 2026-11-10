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
    $this->ioqOperator = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->ioqRecipient = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->ioqActor = (string) $this->ioqOperator->getKey();
    $this->ioqAuthority = new class implements InstitutionalBudgetAuthorization {
        public bool $commit = true;
        public function allows(string $actorId, string $action, ?string $budgetId = null): bool { return $action !== 'budget.commit' || $this->commit; }
    };
    $this->ioqAccess = new class implements FinancialWebAuthorizer {
        public bool $issue = true;
        public function allows(string $userId, string $action, ?string $walletId = null): bool { return in_array($action,['bonus.issue','bonus.cancel'],true) && $this->issue; }
    };
    $this->ioqIssuer = new class implements BonusAuthorizationProvider {
        public bool $allowed = true; public bool $unavailable = false;
        public function canIssueBonus(string $issuerType, string $issuerId, BonusType $type): bool {
            if ($this->unavailable) throw new IdentityFinancialAuthorizationUnavailable('FIXTURE_UNAVAILABLE');
            return $this->allowed && $issuerType === 'USER' && $type === BonusType::BECA;
        }
    };
    app()->instance(InstitutionalBudgetAuthorization::class, $this->ioqAuthority);
    app()->instance(FinancialWebAuthorizer::class, $this->ioqAccess);
    app()->instance(BonusAuthorizationProvider::class, $this->ioqIssuer);
    $this->ioqService = app(InstitutionalBecaIssuanceService::class);
    $data = ['institution_reference' => 'fixture-institution', 'approval_reference' => 'approval-'.Str::uuid(),
        'payer_reference' => 'fixture-approved-payer', 'purpose' => 'Programa BECA', 'approved_amount_cents' => 10000,
        'currency' => 'MXN', 'reason' => 'Aprobacion de prueba', 'valid_from' => now()->subDay()->toISOString(), 'expires_at' => now()->addMonth()->toISOString()];
    $r = app(InstitutionalBudgetService::class)->approve($this->ioqActor, $data, 'approve-'.Str::uuid());
    $this->ioqBudget = InstitutionalBonusBudget::where('public_id', $r['operation']->budget_id)->firstOrFail();
    $this->ioqBuyer = (string)$this->ioqRecipient->getKey();
    $this->ioqWallet = app(\App\Domains\Financial\Services\WalletService::class)->create('USER',$this->ioqBuyer,\App\Domains\Financial\Enums\WalletType::USUARIO);
    $this->ioqConsent = new class implements \App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization {
        public bool $pay = true; public bool $refund = true; public bool $unavailable = false;
        public function allows(string $actorId,string $action,array $context): bool {
            if ($this->unavailable) throw new RuntimeException('fixture-consent-unavailable');
            return $action === 'purchase.pay' ? $this->pay && ($context['authorization_reference'] ?? '') === 'fixture-consent' : $this->refund;
        }
    };
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization::class,$this->ioqConsent);
    $this->ioqIssued = ioqIssue($this);
    $this->ioqMarket = ['business_id'=>'fixture-approved-business','order_id'=>'fixture-order-'.Str::uuid(),'items'=>[]];

});
afterEach(function () {
    InstitutionalBudgetOperation::flushEventListeners(); BonusBudgetCommitment::flushEventListeners(); BonusLedgerEntry::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusObligation::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusRefund::flushEventListeners();
    while (DB::connection('sqlsrv')->transactionLevel() > 0) DB::connection('sqlsrv')->rollBack();
});
function ioqIssuePayload($test): array
{
    return ['award_reference' => 'fixture-award-'.Str::uuid(), 'beneficiary_id' => (string) $test->ioqRecipient->getKey(), 'type' => 'BECA', 'amount_cents' => 3000,
        'valid_from' => now()->subMinute()->toISOString(), 'expires_at' => now()->addDay()->toISOString(),
        'combinable' => true, 'allows_partial_use' => true, 'reason' => 'Beca de prueba con presupuesto',
        'external_reference' => 'test-budget-issue-'.Str::uuid(), 'restrictions' => []];
}
function ioqIssue($test, ?array $data = null, ?string $key = null, int $version = 1): array
{
    return $test->ioqService->issue($test->ioqActor, $test->ioqBudget->public_id, $version,
        $data ?? ioqIssuePayload($test), $key ?? 'issue-'.Str::uuid());
}
function ioqState($test): array
{
    return [$test->ioqBudget->fresh()->toArray(), Bonus::count(), BonusLedgerEntry::count(), BonusAdministrativeOperation::count(),
        BonusBudgetCommitment::count(), InstitutionalBudgetOperation::count(),
        Wallet::orderBy('id')->get(['public_id','available_balance_cents','held_balance_cents'])->toArray()];
}


function ioqPay($test, mixed $amount = 1000, ?string $key = null, ?array $market = null, string $proof = 'fixture-consent'): array
{
    return app(\App\Domains\Financial\Services\PurchasePaymentService::class)->payWithInstitutionalBeca($test->ioqWallet,
        $test->ioqIssued['bonus_id'],$amount,$key ?? 'pay-'.Str::uuid(),$test->ioqBuyer,$proof,$market ?? $test->ioqMarket);
}
function ioqRefund($test, array $purchase, int $amount, bool $approve = true): \App\Domains\Financial\Models\PurchaseRefundRequest
{
    $service = app(\App\Domains\Financial\Services\PurchaseRefundService::class);
    $payment = \App\Domains\Financial\Models\PurchasePayment::where('public_id',$purchase['purchase_payment_id'])->firstOrFail();
    $request = $service->request($payment,0,[['bonus_id'=>$test->ioqIssued['bonus_id'],'amount_cents'=>$amount]],
        'request-'.Str::uuid(),$test->ioqBuyer,'Devolucion de prueba institucional');
    return $approve ? $service->approve($request,$test->ioqActor,'Revision de prueba') : $request;
}
function ioqComplete($test,$refund,?string $key = null) {
    return app(\App\Domains\Financial\Services\PurchaseRefundService::class)->complete($refund,$key ?? 'refund-'.Str::uuid(),$test->ioqActor);
}
function ioqSpendingState($test): array {
    return [ioqState($test),
        \App\Domains\Financial\Models\InstitutionalBonusObligation::orderBy('id')->get()->toArray(),
        \App\Domains\Financial\Models\InstitutionalBonusRefund::orderBy('id')->get()->toArray(),
        \App\Domains\Financial\Models\PurchasePayment::count(),\App\Domains\Financial\Models\FinancialTransaction::count(),
        \App\Domains\Financial\Models\LedgerEntry::count(),DB::connection('sqlsrv')->table('financial_team4_outbox')->orderBy('id')->get()->toArray()];
}


function ioqReport($test, ?string $business = null, int $page = 1, int $size = 10, bool $details = false): array {
    return app(\App\Domains\Financial\Services\InstitutionalObligationQueryService::class)->report($test->ioqBudget->public_id,$business,$page,$size,$details);
}
function ioqCodes(array $report): array { return array_column($report['budgets'][0]['findings'],'code'); }

test('institutional obligation report reconciles actual purchase refund and remainder without writing evidence',function () {
    $p=ioqPay($this); $r=ioqRefund($this,$p,400); ioqComplete($this,$r);
    $before=ioqSpendingState($this); $report=ioqReport($this);
    expect($report['consistent'])->toBeTrue()->and($report['status'])->toBe('CONSISTENT')
        ->and($report['budgets'][0]['obligations']['purchase_amount_cents'])->toBe('1000')
        ->and($report['budgets'][0]['obligations']['refunded_amount_cents'])->toBe('400')
        ->and($report['budgets'][0]['obligations']['pending_amount_cents'])->toBe('600')
        ->and($report['entries'][0]['pending_amount_cents'])->toBe('600')->and($report['money_moved'])->toBeFalse();
    expect(ioqSpendingState($this))->toEqual($before); Http::assertNothingSent();
});

test('institutional obligation report pending approval does not reduce the debt and cancellation releases only remainder',function () {
    $p=ioqPay($this); ioqRefund($this,$p,400);
    $report=ioqReport($this); expect($report['consistent'])->toBeTrue()->and($report['entries'][0]['pending_amount_cents'])->toBe('1000');
    app(\App\Domains\Financial\Services\InstitutionalBecaReleaseService::class)->cancel($this->ioqActor,$this->ioqBudget->public_id,
        $this->ioqIssued['bonus_id'],3,'Liberar remanente','release-'.Str::uuid());
    $report=ioqReport($this); expect($report['consistent'])->toBeTrue()->and($report['budgets'][0]['committed_amount_cents'])->toBe('0')
        ->and($report['budgets'][0]['consumed_amount_cents'])->toBe('1000');
});

test('institutional obligation report detects a budget counter mismatch instead of correcting it',function () {
    ioqPay($this); $this->ioqBudget->update(['committed_amount_cents'=>1999]); $before=ioqSpendingState($this);
    $report=ioqReport($this);
    expect($report['consistent'])->toBeFalse()->and(ioqCodes($report))->toContain('COMMITTED_VS_BONUS_BALANCES');
    expect(ioqSpendingState($this))->toEqual($before);
});

test('institutional obligation report finds a purchase without its obligation',function () {
    $p=ioqPay($this);
    DB::connection('sqlsrv')->table('institutional_bonus_obligations')->where('public_id',$p['obligation_id'])->delete();
    $before=ioqSpendingState($this); $report=ioqReport($this);
    expect(ioqCodes($report))->toContain('PURCHASE_WITHOUT_OBLIGATION','CONSUMED_VS_OBLIGATIONS','LEDGER_WITHOUT_EVIDENCE');
    expect(ioqSpendingState($this))->toEqual($before);
});

test('institutional obligation report does not hide a broken marker when original commitment still exists',function () {
    ioqPay($this); DB::connection('sqlsrv')->table('bonuses')->where('public_id',$this->ioqIssued['bonus_id'])->update(['institutional_budget_id'=>null]);
    $report=ioqReport($this); expect($report['consistent'])->toBeFalse()->and(ioqCodes($report))->toContain('BONUS_BINDING','OBLIGATION_EVIDENCE');
});

test('institutional obligation report detects a missing movement and incorrect running balance',function () {
    ioqPay($this); DB::connection('sqlsrv')->table('bonus_ledger_entries')->where('bonus_id',$this->ioqIssued['bonus_id'])->where('reference_type','BUDGET_PURCHASE')->delete();
    $report=ioqReport($this); expect(ioqCodes($report))->toContain('OBLIGATION_WITHOUT_MOVEMENT','BONUS_VS_LEDGER_BALANCE','CONSUMED_VS_LEDGER');
});

test('institutional obligation report distinguishes a refund counter from its immutable evidence',function () {
    $p=ioqPay($this); DB::connection('sqlsrv')->table('institutional_bonus_obligations')->where('public_id',$p['obligation_id'])->update(['refunded_amount_cents'=>1]);
    expect(ioqCodes(ioqReport($this)))->toContain('REFUND_COUNTER_VS_EVIDENCE','OBLIGATION_EVIDENCE');
});

test('institutional obligation report validates recorded business against the original purchase',function () {
    $p=ioqPay($this); DB::connection('sqlsrv')->table('institutional_bonus_obligations')->where('public_id',$p['obligation_id'])->update(['business_id'=>'altered-business']);
    expect(ioqCodes(ioqReport($this)))->toContain('OBLIGATION_EVIDENCE');
});

test('institutional obligation report business pagination does not filter the whole budget reconciliation',function () {
    ioqPay($this,1000); ioqPay($this,500,null,[...$this->ioqMarket,'business_id'=>'second-business','order_id'=>'second-'.Str::uuid()]);
    $report=ioqReport($this,null,1,1); expect($report['has_more'])->toBeTrue()->and(count($report['entries']))->toBe(1);
    $filtered=ioqReport($this,'second-business');
    expect($filtered['entries'][0]['pending_amount_cents'])->toBe('500')
        ->and($filtered['budgets'][0]['obligations']['pending_amount_cents'])->toBe('1500')->and($filtered['consistent'])->toBeTrue();
    $this->ioqBudget->update(['consumed_amount_cents'=>1499]);
    expect(ioqReport($this,'nonexistent-business')['consistent'])->toBeFalse();
});

test('institutional obligation report details expose operation references without identity or credential fields',function () {
    $p=ioqPay($this); $report=ioqReport($this,null,1,10,true);
    expect($report['entries'][0]['purchase_payment_id'])->toBe($p['purchase_payment_id'])
        ->and($report['entries'][0]['obligation_id'])->toBe($p['obligation_id']);
    $json=json_encode($report,JSON_THROW_ON_ERROR);
    expect($json)->not->toContain($this->ioqBuyer,$this->ioqActor,'secret_hash','access_token','email','authorization_reference');
});

test('institutional obligation report input rejects before SQL and an absent budget produces no data',function () {
    $service=app(\App\Domains\Financial\Services\InstitutionalObligationQueryService::class);
    $before=ioqSpendingState($this);
    expect(fn ()=>$service->report('invalid'))->toThrow(InvalidArgumentException::class);
    expect(fn ()=>$service->report(null,'business'))->toThrow(InvalidArgumentException::class);
    expect(fn ()=>$service->report(null,null,0))->toThrow(InvalidArgumentException::class);
    expect(fn ()=>$service->report(null,null,1,21))->toThrow(InvalidArgumentException::class);
    expect(fn ()=>$service->report(null,null,1,10,true))->toThrow(InvalidArgumentException::class);
    $r=$service->report((string)Str::uuid()); expect($r['status'])->toBe('NO_DATA')->and($r['consistent'])->toBeNull();
    expect(ioqSpendingState($this))->toEqual($before);
});

test('institutional obligation report command emits JSON and returns differences separately from command failure',function () {
    ioqPay($this);
    $this->artisan('financial:institutional-obligations',['--budget'=>$this->ioqBudget->public_id])->assertExitCode(0);
    $this->ioqBudget->update(['consumed_amount_cents'=>999]);
    $this->artisan('financial:institutional-obligations',['--budget'=>$this->ioqBudget->public_id])->assertExitCode(2);
    $this->artisan('financial:institutional-obligations',['--page'=>'1.5'])->assertExitCode(1);
});

test('institutional obligation report detects wrong completed refund transaction evidence',function () {
    $p=ioqPay($this); $refund=ioqRefund($this,$p,400); $tx=ioqComplete($this,$refund);
    DB::connection('sqlsrv')->table('financial_transactions')->where('public_id',$tx->public_id)->update(['reference_type'=>'OTHER']);
    expect(ioqCodes(ioqReport($this)))->toContain('REFUND_EVIDENCE');
});

test('institutional obligation report only executes reads and uses shared budget locks',function () {
    ioqPay($this); $queries=[];
    DB::connection('sqlsrv')->listen(function ($event) use (&$queries) { $queries[]=$event->sql; });
    ioqReport($this);
    expect($queries)->not->toBeEmpty(); $shared=false;
    foreach ($queries as $sql) {
        expect(preg_match('/\b(insert|update|delete|merge|create|alter|drop)\b/i',$sql))->toBe(0);
        if (stripos($sql,'holdlock')!==false) $shared=true;
    }
    expect($shared)->toBeTrue();
});

test('institutional obligation report preserves exact aggregates above PHP integer range and marks corrupt evidence',function () {
    for ($i=0;$i<2;$i++) {
        $op=InstitutionalBudgetOperation::create(['public_id'=>(string)Str::uuid(),'budget_id'=>$this->ioqBudget->public_id,
            'action'=>'CONSUME_BECA','actor_id'=>$this->ioqBuyer,'reason'=>'Fixture inconsistente para precision',
            'idempotency_key'=>'precision-'.Str::uuid(),'request_hash'=>str_repeat('a',64),'before_data'=>[], 'after_data'=>[], 'created_at'=>now()]);
        $p=\App\Domains\Financial\Models\PurchasePayment::create(['public_id'=>(string)Str::uuid(),'wallet_id'=>$this->ioqWallet->public_id,
            'bonus_id'=>$this->ioqIssued['bonus_id'],'business_id'=>'precision-business','currency'=>'MXN',
            'total_amount_cents'=>6000000000000000000,'bonus_amount_cents'=>6000000000000000000,'wallet_amount_cents'=>0,
            'idempotency_key'=>'precision-purchase-'.Str::uuid(),'status'=>'COMPLETADO']);
        \App\Domains\Financial\Models\InstitutionalBonusObligation::create(['public_id'=>(string)Str::uuid(),'purchase_payment_id'=>$p->public_id,
            'bonus_id'=>$this->ioqIssued['bonus_id'],'budget_id'=>$this->ioqBudget->public_id,'operation_id'=>$op->public_id,
            'amount_cents'=>6000000000000000000,'refunded_amount_cents'=>0,'currency'=>'MXN','business_id'=>'precision-business',
            'payer_reference'=>'fixture-approved-payer','status'=>'OPEN']);
    }
    $report=ioqReport($this);
    expect($report['consistent'])->toBeFalse()->and($report['budgets'][0]['obligations']['purchase_amount_cents'])->toBe('12000000000000000000')
        ->and($report['entries'][0]['pending_amount_cents'])->toBe('12000000000000000000');
});

test('institutional obligation report excludes historical bonuses without institutional links',function () {
    $legacy=app(BonusService::class)->issue('USER',$this->ioqBuyer,'USER',$this->ioqActor,BonusType::BECA,100,
        now()->subMinute(),now()->addDay(),true,true);
    app(BonusService::class)->consume($legacy->public_id,10);
    $report=ioqReport($this);
    expect($report['consistent'])->toBeTrue()->and($report['budgets'][0]['observed_ledger_consumed_cents'])->toBe('0');
});
