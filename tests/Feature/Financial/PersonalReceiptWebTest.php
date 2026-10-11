<?php
use App\Models\User;
use App\Domains\Financial\Enums\{WalletType, MovementType};
use App\Domains\Financial\Models\{Wallet, PurchasePayment, PurchasePaymentBonus, FinancialTransaction, LedgerEntry};
use App\Domains\Financial\Services\{WalletService, LedgerService, PersonalReceiptQueryService};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    if (!app()->environment('testing') || config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing'
        || config('database.connections.mongodb.database') !== 'campus_virtual_testing') throw new RuntimeException('Solo bases de pruebas.');
    $this->pr29Users = []; $this->withoutVite();
    DB::connection('sqlsrv')->beginTransaction();
});
afterEach(function () {
    while (DB::connection('sqlsrv')->transactionLevel() > 0) DB::connection('sqlsrv')->rollBack();
    foreach ($this->pr29Users ?? [] as $id) User::where('_id', $id)->delete();
});
function pr29User($test, int $balance = 0): array {
    $user = User::factory()->create();
    $user->forceFill(['email_verified_at' => now(), 'account_activation_pending' => false])->save();
    $test->pr29Users[] = (string) $user->getKey();
    $wallet = app(WalletService::class)->create('USER', (string) $user->getKey(), WalletType::USUARIO);
    $transaction = $balance ? app(LedgerService::class)->credit($wallet, $balance, MovementType::RECARGA, 'pr29-'.Str::uuid(), 'TEST', (string) Str::uuid()) : null;
    return [$user, $wallet, $transaction];
}
function pr29Purchase(Wallet $wallet, int $walletAmount = 0): PurchasePayment {
    return PurchasePayment::create(['public_id' => (string) Str::uuid(), 'idempotency_key' => 'pr29-'.Str::uuid(),
        'wallet_id' => $wallet->public_id, 'bonus_id' => (string) Str::uuid(), 'currency' => 'MXN',
        'total_amount_cents' => 1000, 'bonus_amount_cents' => 1000-$walletAmount, 'wallet_amount_cents' => $walletAmount,
        'status' => 'COMPLETADO']);
}
test('personal receipts require authentication', function () {
    $this->getJson('/finanzas/comprobantes')->assertUnauthorized();
    $this->getJson('/finanzas/comprobantes/transaction/'.Str::uuid())->assertUnauthorized();
});
test('personal receipt listing is paginated isolated and ignores forged owner parameters', function () {
    [$u,$w,$t] = pr29User($this, 5000); [$other,$ow,$ot] = pr29User($this, 1000);
    $p=pr29Purchase($w);
    $this->actingAs($u)->get('/finanzas/comprobantes?per_page=1&owner_id='.$other->getKey())
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Financial/PersonalReceipts')
            ->where('receipts.total', 2)->has('receipts.data', 1));
    $rows=app(PersonalReceiptQueryService::class)->listing($w,50)->items();
    expect(array_column($rows,'operation_id'))->not->toContain($ot->public_id);
    $this->getJson('/finanzas/comprobantes?per_page=51')->assertUnprocessable();
});
test('foreign receipt and absent receipt are hidden even from embedded admin roles', function () {
    [$u] = pr29User($this); [$other,$ow,$ot] = pr29User($this,1000); $p=pr29Purchase($ow);
    $u->assignRole('admin');
    $this->actingAs($u)->getJson('/finanzas/comprobantes/transaction/'.$ot->public_id)->assertNotFound();
    $this->getJson('/finanzas/comprobantes/purchase/'.$p->public_id)->assertNotFound();
    $this->getJson('/finanzas/comprobantes/transaction/'.Str::uuid())->assertNotFound();
});
test('personal transfer receipt exposes only its owners entries and does not change balances', function () {
    [$u,$w] = pr29User($this,5000); [$other,$ow] = pr29User($this);
    $t=app(LedgerService::class)->transfer($w,$ow,1000,'pr29-'.Str::uuid());
    $before=[$w->fresh()->available_balance_cents,$ow->fresh()->available_balance_cents,LedgerEntry::count(),FinancialTransaction::count()];
    $this->actingAs($u)->get('/finanzas/comprobantes/transaction/'.$t->public_id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Financial/PersonalReceipt')
            ->has('receipt.entries',1)->where('receipt.entries.0.amount_cents',-1000)
            ->missing('receipt.entries.0.wallet_id')->missing('receipt.metadata'));
    $recipient=app(PersonalReceiptQueryService::class)->transaction($ow,$t->public_id);
    expect($recipient['entries'][0]['amount_cents'])->toBe(1000)
        ->and([$w->fresh()->available_balance_cents,$ow->fresh()->available_balance_cents,LedgerEntry::count(),FinancialTransaction::count()])->toBe($before);
});
test('fully bonus funded purchase is visible without inventing a wallet transaction', function () {
    [$u,$w]=pr29User($this); $p=pr29Purchase($w); $count=FinancialTransaction::count();
    $this->actingAs($u)->get('/finanzas/comprobantes/purchase/'.$p->public_id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('receipt.purchase.total_amount_cents',1000)
            ->where('receipt.purchase.wallet_amount_cents',0)->where('receipt.purchase.bonus_amount_cents',1000)
            ->has('receipt.purchase.bonuses',1)->has('receipt.entries',0));
    expect(FinancialTransaction::count())->toBe($count);
});
test('mixed purchase is listed once and its stored multiple bonus allocations are included', function () {
    [$u,$w]=pr29User($this,5000); $p=pr29Purchase($w,400);
    foreach ([250,350] as $amount) PurchasePaymentBonus::create(['purchase_payment_id'=>$p->public_id,'bonus_id'=>(string)Str::uuid(),'amount_cents'=>$amount]);
    $t=app(LedgerService::class)->debit($w,400,MovementType::PAGO,$p->idempotency_key,'PURCHASE',null);
    $service=app(PersonalReceiptQueryService::class);
    expect($service->listing($w)->total())->toBe(2);
    $receipt=$service->transaction($w,$t->public_id);
    expect($receipt['source'])->toBe('PURCHASE')->and($receipt['purchase']['bonuses'])->toHaveCount(2)
        ->and($receipt['entries'][0]['amount_cents'])->toBe(-400);
});
test('missing wallet evidence and inconsistent stored bonus breakdown return conflict', function () {
    [$u,$w]=pr29User($this); $p=pr29Purchase($w,400);
    $this->actingAs($u)->getJson('/finanzas/comprobantes/purchase/'.$p->public_id)->assertConflict();
    $p->update(['wallet_amount_cents'=>0,'bonus_amount_cents'=>1000]);
    PurchasePaymentBonus::create(['purchase_payment_id'=>$p->public_id,'bonus_id'=>(string)Str::uuid(),'amount_cents'=>1]);
    $this->getJson('/finanzas/comprobantes/purchase/'.$p->public_id)->assertConflict();
});
test('no wallet shows an empty receipt screen without creating a wallet', function () {
    [$u,$w]=pr29User($this); $w->delete();
    $this->actingAs($u)->get('/finanzas/comprobantes')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('hasWallet',false)->has('receipts.data',0));
    expect(Wallet::where('owner_id',(string)$u->getKey())->exists())->toBeFalse();
});
test('cash receipt lookup reuses its folio without exposing administrative snapshot or issuing another receipt', function () {
    [$u,$w,$t]=pr29User($this,1000);
    $service=app(\App\Domains\Financial\Services\CashShiftService::class);
    $register=$service->createRegister('pr29-'.Str::uuid(),'Caja prueba','MXN',(string)$u->getKey());
    $shift=$service->open($register->public_id,(string)$u->getKey(),0,'pr29-'.Str::uuid());
    $movement=\App\Domains\Financial\Models\CashMovement::create(['public_id'=>(string)Str::uuid(),
        'cash_shift_id'=>$shift->id,'type'=>\App\Domains\Financial\Enums\CashMovementType::TOPUP,
        'amount_cents'=>1000,'reference_type'=>$t->reference_type,'reference_id'=>$t->reference_id,
        'reason'=>'privado','actor_id'=>'actor-privado','idempotency_key'=>$t->idempotency_key,
        'request_hash'=>hash('sha256','fixture'),'wallet_id'=>$w->public_id]);
    $cash=\App\Domains\Financial\Models\CashReceipt::create(['public_id'=>(string)Str::uuid(),
        'cash_movement_id'=>$movement->id,'folio'=>'CAJ-PR29-'.Str::uuid(),'issued_at'=>now(),
        'snapshot'=>['financial_transaction_id'=>strtolower($t->public_id),'reason'=>'privado','supervisor_id'=>'privado']]);
    $count=\App\Domains\Financial\Models\CashReceipt::count();
    foreach (range(1,2) as $_) $this->actingAs($u)->get('/finanzas/comprobantes/transaction/'.$t->public_id)
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('receipt.cash_receipts.0.folio',$cash->folio)
            ->missing('receipt.cash_receipts.0.snapshot')->missing('receipt.cash_receipts.0.actor_id'));
    expect(\App\Domains\Financial\Models\CashReceipt::count())->toBe($count)->and($cash->fresh()->folio)->toBe($cash->folio);
});
