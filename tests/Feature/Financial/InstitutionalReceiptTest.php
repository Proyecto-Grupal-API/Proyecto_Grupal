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
    DB::connection('sqlsrv')->beginTransaction(); Http::preventStrayRequests(); $this->freezeTime(); $this->travelTo(now()->startOfSecond()); $this->withoutVite();
    $this->ircOperator = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->ircRecipient = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->ircActor = (string) $this->ircOperator->getKey();
    $this->ircAuthority = new class implements InstitutionalBudgetAuthorization {
        public bool $commit = true;
        public function allows(string $actorId, string $action, ?string $budgetId = null): bool { return $action !== 'budget.commit' || $this->commit; }
    };
    $this->ircAccess = new class implements FinancialWebAuthorizer {
        public bool $issue = true;
        public function allows(string $userId, string $action, ?string $walletId = null): bool { return in_array($action,['bonus.issue','bonus.cancel'],true) && $this->issue; }
    };
    $this->ircIssuer = new class implements BonusAuthorizationProvider {
        public bool $allowed = true; public bool $unavailable = false;
        public function canIssueBonus(string $issuerType, string $issuerId, BonusType $type): bool {
            if ($this->unavailable) throw new IdentityFinancialAuthorizationUnavailable('FIXTURE_UNAVAILABLE');
            return $this->allowed && $issuerType === 'USER' && $type === BonusType::BECA;
        }
    };
    app()->instance(InstitutionalBudgetAuthorization::class, $this->ircAuthority);
    app()->instance(FinancialWebAuthorizer::class, $this->ircAccess);
    app()->instance(BonusAuthorizationProvider::class, $this->ircIssuer);
    $this->ircService = app(InstitutionalBecaIssuanceService::class);
    $data = ['institution_reference' => 'fixture-institution', 'approval_reference' => 'approval-'.Str::uuid(),
        'payer_reference' => 'fixture-approved-payer', 'purpose' => 'Programa BECA', 'approved_amount_cents' => 10000,
        'currency' => 'MXN', 'reason' => 'Aprobacion de prueba', 'valid_from' => now()->subDay()->toISOString(), 'expires_at' => now()->addMonth()->toISOString()];
    $r = app(InstitutionalBudgetService::class)->approve($this->ircActor, $data, 'approve-'.Str::uuid());
    $this->ircBudget = InstitutionalBonusBudget::where('public_id', $r['operation']->budget_id)->firstOrFail();
    $this->ircBuyer = (string)$this->ircRecipient->getKey();
    $this->ircWallet = app(\App\Domains\Financial\Services\WalletService::class)->create('USER',$this->ircBuyer,\App\Domains\Financial\Enums\WalletType::USUARIO);
    $this->ircConsent = new class implements \App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization {
        public bool $pay = true; public bool $refund = true; public bool $unavailable = false;
        public function allows(string $actorId,string $action,array $context): bool {
            if ($this->unavailable) throw new RuntimeException('fixture-consent-unavailable');
            return $action === 'purchase.pay' ? $this->pay && ($context['authorization_reference'] ?? '') === 'fixture-consent' : $this->refund;
        }
    };
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalPurchaseAuthorization::class,$this->ircConsent);
    $this->ircIssued = ircIssue($this);
    $this->ircMarket = ['business_id'=>'fixture-approved-business','order_id'=>'fixture-order-'.Str::uuid(),'items'=>[]];
    $this->ircPurchase = ircPay($this);

});
afterEach(function () {
    InstitutionalBudgetOperation::flushEventListeners(); BonusBudgetCommitment::flushEventListeners(); BonusLedgerEntry::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusObligation::flushEventListeners();
    \App\Domains\Financial\Models\InstitutionalBonusRefund::flushEventListeners();
    while (DB::connection('sqlsrv')->transactionLevel() > 0) DB::connection('sqlsrv')->rollBack();
});
function ircIssuePayload($test): array
{
    return ['award_reference' => 'fixture-award-'.Str::uuid(), 'beneficiary_id' => (string) $test->ircRecipient->getKey(), 'type' => 'BECA', 'amount_cents' => 3000,
        'valid_from' => now()->subMinute()->toISOString(), 'expires_at' => now()->addDay()->toISOString(),
        'combinable' => true, 'allows_partial_use' => true, 'reason' => 'Beca de prueba con presupuesto',
        'external_reference' => 'test-budget-issue-'.Str::uuid(), 'restrictions' => []];
}
function ircIssue($test, ?array $data = null, ?string $key = null, int $version = 1): array
{
    return $test->ircService->issue($test->ircActor, $test->ircBudget->public_id, $version,
        $data ?? ircIssuePayload($test), $key ?? 'issue-'.Str::uuid());
}
function ircPay($test, mixed $amount = 1000, ?string $key = null, ?array $market = null, string $proof = 'fixture-consent'): array
{
    return app(\App\Domains\Financial\Services\PurchasePaymentService::class)->payWithInstitutionalBeca($test->ircWallet,
        $test->ircIssued['bonus_id'],$amount,$key ?? 'pay-'.Str::uuid(),$test->ircBuyer,$proof,$market ?? $test->ircMarket);
}
function ircRefund($test, array $purchase, int $amount, bool $approve = true): \App\Domains\Financial\Models\PurchaseRefundRequest
{
    $service = app(\App\Domains\Financial\Services\PurchaseRefundService::class);
    $payment = \App\Domains\Financial\Models\PurchasePayment::where('public_id',$purchase['purchase_payment_id'])->firstOrFail();
    $request = $service->request($payment,0,[['bonus_id'=>$test->ircIssued['bonus_id'],'amount_cents'=>$amount]],
        'request-'.Str::uuid(),$test->ircBuyer,'Devolucion de prueba institucional');
    return $approve ? $service->approve($request,$test->ircActor,'Revision de prueba') : $request;
}
function ircComplete($test,$refund,?string $key = null) {
    return app(\App\Domains\Financial\Services\PurchaseRefundService::class)->complete($refund,$key ?? 'refund-'.Str::uuid(),$test->ircActor);
}

function ircReceipt($t): array {
    return app(\App\Domains\Financial\Services\PersonalReceiptQueryService::class)->purchase($t->ircWallet,$t->ircPurchase['purchase_payment_id']);
}
function ircReadState($t): array {
    $sql=DB::connection('sqlsrv');
    $state=[$t->ircBudget->fresh()->toArray(),Bonus::where('public_id',$t->ircIssued['bonus_id'])->firstOrFail()->toArray(),$t->ircWallet->fresh()->toArray()];
    foreach(['institutional_budget_operations','institutional_bonus_obligations','institutional_bonus_refunds',
        'institutional_bonus_settlements','bonus_ledger_entries','financial_transactions','ledger_entries',
        'purchase_payments','purchase_refund_requests','financial_team4_outbox'] as $table) {
        $state[$table]=$sql->table($table)->orderBy('id')->get()->toArray();
    }
    return $state;
}
function ircHtml(array $receipt): string {
    return view('financial.receipt-pdf',['receipt'=>$receipt,
        'money'=>static fn($v,$currency)=>$currency.' '.number_format($v/100,2,'.',','),
        'date'=>static fn($v)=>(string)$v,'generated_at'=>now()->toISOString()])->render();
}

test('institutional receipt identifies BECA without changing evidence or exposing internal funding identities',function(){
    $before=ircReadState($this);$r=ircReceipt($this);$s=$r['purchase']['institutional'];
    expect($s['funding_type'])->toBe('INSTITUTIONAL_BECA')->and($s['covered_amount_cents'])->toBe(1000)
        ->and($s['refunded_amount_cents'])->toBe(0)->and($s['net_amount_cents'])->toBe(1000)
        ->and($s['restored_bonus_amount_cents'])->toBe(0)->and($s['released_budget_amount_cents'])->toBe(0)
        ->and($r['entries'])->toBe([])->and(ircReadState($this))->toEqual($before);
    $json=json_encode($r,JSON_THROW_ON_ERROR);
    expect($json)->not->toContain(strtolower($this->ircBudget->public_id),$this->ircActor,
        'fixture-approved-payer','fixture-institution','approval_reference','settled_amount_cents','recipient_reference');
    Http::assertNothingSent();
});

test('institutional receipt web shows the same safe projection and keeps a single original purchase',function(){
    $before=ircReadState($this);
    $this->actingAs($this->ircRecipient)->get('/finanzas/comprobantes/purchase/'.$this->ircPurchase['purchase_payment_id'])
        ->assertOk()->assertInertia(fn(\Inertia\Testing\AssertableInertia $p)=>$p->component('Financial/PersonalReceipt')
            ->where('receipt.purchase.institutional.funding_type','INSTITUTIONAL_BECA')
            ->where('receipt.purchase.total_amount_cents',1000)->where('receipt.purchase.wallet_amount_cents',0));
    expect(app(\App\Domains\Financial\Services\PersonalReceiptQueryService::class)->listing($this->ircWallet)->total())->toBe(1)
        ->and(ircReadState($this))->toEqual($before);
});

test('institutional receipt excludes pending approved and rejected refunds until completion',function(){
    $service=app(\App\Domains\Financial\Services\PurchaseRefundService::class);
    $request=ircRefund($this,$this->ircPurchase,400,false);
    expect(ircReceipt($this)['purchase']['institutional']['refunded_amount_cents'])->toBe(0);
    $request=$service->approve($request,$this->ircActor,'Aprobar solicitud de prueba');
    expect(ircReceipt($this)['purchase']['institutional']['refunded_amount_cents'])->toBe(0);
    ircComplete($this,$request);$before=ircReadState($this);$r=ircReceipt($this);
    expect($r['purchase']['total_amount_cents'])->toBe(1000)
        ->and($r['purchase']['institutional']['refunded_amount_cents'])->toBe(400)
        ->and($r['purchase']['institutional']['restored_bonus_amount_cents'])->toBe(400)
        ->and($r['purchase']['institutional']['released_budget_amount_cents'])->toBe(0)
        ->and($r['purchase']['institutional']['net_amount_cents'])->toBe(600)->and(ircReadState($this))->toEqual($before);
    $rejected=ircRefund($this,$this->ircPurchase,100,false);$service->reject($rejected,$this->ircActor,'Rechazo de prueba');
    expect(ircReceipt($this)['purchase']['institutional']['refunded_amount_cents'])->toBe(400);
});

test('institutional receipt distinguishes closed BECA refunds released to the budget without reactivation',function(){
    app(\App\Domains\Financial\Services\InstitutionalBecaReleaseService::class)->cancel($this->ircActor,
        $this->ircBudget->public_id,$this->ircIssued['bonus_id'],3,'Cancelar remanente','close-'.Str::uuid());
    $request=ircRefund($this,$this->ircPurchase,400);
    app(\App\Domains\Financial\Services\InstitutionalBecaPurchaseService::class)->completeClosedRefund($request,'closed-refund-'.Str::uuid(),$this->ircActor);
    $before=ircReadState($this);$r=ircReceipt($this);$s=$r['purchase']['institutional'];
    expect($s['refunded_amount_cents'])->toBe(400)->and($s['net_amount_cents'])->toBe(600)
        ->and($s['restored_bonus_amount_cents'])->toBe(0)->and($s['released_budget_amount_cents'])->toBe(400)
        ->and(Bonus::where('public_id',$this->ircIssued['bonus_id'])->firstOrFail()->status->value)->toBe('CANCELADO')
        ->and(ircHtml($r))->toContain('Devuelto al presupuesto institucional','no se agregó a tu wallet')
        ->and(ircReadState($this))->toEqual($before);
});

test('institutional receipt full refund preserves original amount and labels zero net purchase',function(){
    ircComplete($this,ircRefund($this,$this->ircPurchase,1000));$r=ircReceipt($this);
    expect($r['purchase']['total_amount_cents'])->toBe(1000)->and($r['purchase']['institutional']['net_amount_cents'])->toBe(0)
        ->and($r['purchase']['institutional']['refunded_amount_cents'])->toBe(1000);
});

test('institutional receipt does not expose merchant settlement references or confuse settlement with buyer refund',function(){
    $this->travel(1)->seconds();
    app()->instance(\App\Domains\Financial\Contracts\InstitutionalSettlementAuthorization::class,
        new class implements \App\Domains\Financial\Contracts\InstitutionalSettlementAuthorization {
            public function allows(string $actorId,string $action,array $context):bool{return $action==='settlement.record';}
        });
    $summary=ircReceipt($this)['purchase']['institutional'];
    $reference='private-bank-'.Str::uuid();
    app(\App\Domains\Financial\Services\InstitutionalSettlementService::class)->record($this->ircActor,
        $this->ircPurchase['obligation_id'],3,['amount_cents'=>600,'currency'=>'MXN',
            'payment_provider'=>'private-provider','payment_reference'=>$reference,'recipient_reference'=>'private-recipient',
            'paid_at'=>now()->toISOString(),'reason'=>'Liquidacion exclusiva de prueba'],'settle-'.Str::uuid());
    $before=ircReadState($this);$receipt=ircReceipt($this);
    expect($receipt['purchase']['institutional'])->toEqual($summary)
        ->and(json_encode($receipt,JSON_THROW_ON_ERROR))->not->toContain($reference,'private-provider','private-recipient')
        ->and(ircReadState($this))->toEqual($before);
});

test('institutional receipt hides foreign web and pdf before inspecting corrupted internal evidence',function(){
    DB::connection('sqlsrv')->table('institutional_bonus_obligations')->where('public_id',$this->ircPurchase['obligation_id'])->update(['refunded_amount_cents'=>1]);
    $before=ircReadState($this);
    $this->actingAs($this->ircOperator)->getJson('/finanzas/comprobantes/purchase/'.$this->ircPurchase['purchase_payment_id'])->assertNotFound();
    $this->getJson('/finanzas/comprobantes/purchase/'.$this->ircPurchase['purchase_payment_id'].'/pdf')->assertNotFound();
    expect(ircReadState($this))->toEqual($before);
});

test('institutional receipt rejects inconsistent binding counters and beneficiary without repairing records',function(string $corruption){
    $sql=DB::connection('sqlsrv');
    match($corruption){
        'marker'=>$sql->table('bonuses')->where('public_id',$this->ircIssued['bonus_id'])->update(['institutional_budget_id'=>null]),
        'debt'=>$sql->table('institutional_bonus_obligations')->where('public_id',$this->ircPurchase['obligation_id'])->delete(),
        'refund'=>$sql->table('institutional_bonus_obligations')->where('public_id',$this->ircPurchase['obligation_id'])->update(['refunded_amount_cents'=>1]),
        'settlement'=>$sql->table('institutional_bonus_obligations')->where('public_id',$this->ircPurchase['obligation_id'])->update(['settled_amount_cents'=>1]),
        'beneficiary'=>$sql->table('bonuses')->where('public_id',$this->ircIssued['bonus_id'])->update(['beneficiary_id'=>$this->ircActor]),
        'budget'=>$sql->table('institutional_bonus_budgets')->where('public_id',$this->ircBudget->public_id)->update(['consumed_amount_cents'=>1001]),
    };
    $before=ircReadState($this);
    expect(fn()=>ircReceipt($this))->toThrow(ConflictHttpException::class);
    expect(ircReadState($this))->toEqual($before);
})->with(['marker','debt','refund','settlement','beneficiary','budget']);

test('institutional receipt pdf is a real file with the same safe funding summary and no financial changes',function(){
    $r=ircReceipt($this);$before=ircReadState($this);
    expect(ircHtml($r))->toContain('Compra cubierta con BECA institucional','Devoluciones completadas','Importe neto de la compra');
    $response=app(\App\Domains\Financial\Services\ReceiptPdfService::class)->download($r);
    expect($response->getStatusCode())->toBe(200)->and($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(substr($response->getContent(),0,5))->toBe('%PDF-')->and(ircReadState($this))->toEqual($before);
    Http::assertNothingSent();
});

test('institutional receipt leaves historical bonus only purchases without institutional labels',function(){
    $p=\App\Domains\Financial\Models\PurchasePayment::create(['public_id'=>(string)Str::uuid(),
        'idempotency_key'=>'legacy-receipt-'.Str::uuid(),'wallet_id'=>$this->ircWallet->public_id,
        'bonus_id'=>(string)Str::uuid(),'currency'=>'MXN','total_amount_cents'=>100,'bonus_amount_cents'=>100,
        'wallet_amount_cents'=>0,'status'=>'COMPLETADO']);
    $r=app(\App\Domains\Financial\Services\PersonalReceiptQueryService::class)->purchase($this->ircWallet,$p->public_id);
    expect($r['purchase']['institutional'])->toBeNull()->and(ircHtml($r))->not->toContain('BECA institucional y devoluciones');
});
