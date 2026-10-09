<?php

use App\Models\User;
use App\Domains\Financial\Contracts\FinancialWebAuthorizer;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Models\FinancialRefundRequest;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\FinancialWithdrawalRecovery;
use App\Domains\Financial\Models\PurchasePayment;
use App\Domains\Financial\Models\PurchasePaymentBonus;
use App\Domains\Financial\Models\PurchaseRefundRequest;
use App\Domains\Financial\Models\PurchaseRefundBonus;
use App\Domains\Financial\Models\WalletHoldPolicy;
use App\Domains\Financial\Services\FinancialAdjustmentService;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\WalletHoldService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__ . '/Support/FinancialControlHelpers.php';
beforeEach(function () {
    $GLOBALS['ui27WalletIds'] = [];
    $GLOBALS['ui27PolicyIds'] = [];
    ui27Cleanup();
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->withoutVite();
});
afterEach(function () {
    ui27Cleanup();
});

function ui27Cleanup(): void
{
    if (config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing') {
        throw new RuntimeException('La limpieza de fixtures UI solo se permite en testing.');
    }
    // Recuperar únicamente fixtures UI marcados, incluidos los del intento fallido.
    $transactionIds = FinancialTransaction::where('idempotency_key', 'like', 'test-fc-ui27-%')->pluck('public_id');
    $ids = collect($GLOBALS['ui27WalletIds'] ?? [])
        ->merge(\App\Domains\Financial\Models\LedgerEntry::whereIn('transaction_id', $transactionIds)->pluck('wallet_id'))
        ->merge(PurchasePayment::where('idempotency_key', 'like', 'test-fc-ui27-%')->pluck('wallet_id'))
        ->merge(Wallet::where('owner_id', 'like', 'test-fc-ui27-%')->pluck('public_id'))
        ->unique()->values()->all();
    $refundIds = FinancialRefundRequest::whereIn('wallet_id', $ids)->pluck('public_id');
    FinancialWithdrawalRecovery::whereIn('refund_request_id', $refundIds)->delete();
    FinancialRefundRequest::whereIn('wallet_id', $ids)->delete();
    $purchaseRefundIds = PurchaseRefundRequest::whereIn('wallet_id', $ids)->pluck('public_id');
    PurchaseRefundBonus::whereIn('refund_request_id', $purchaseRefundIds)->delete();
    PurchaseRefundRequest::whereIn('wallet_id', $ids)->delete();
    $paymentIds = PurchasePayment::whereIn('wallet_id', $ids)->pluck('public_id');
    PurchasePaymentBonus::whereIn('purchase_payment_id', $paymentIds)->delete();
    PurchasePayment::whereIn('wallet_id', $ids)->delete();
    foreach ($ids as $id) {
        Wallet::where('public_id', $id)->update(['owner_id' => 'test-fc-ui27-cleanup-' . strtolower($id)]);
    }
    $bonuses = \App\Domains\Financial\Models\Bonus::where('external_reference', 'like', 'test-fc-ui27-%')->get();
    foreach ($bonuses as $bonus) {
        \App\Domains\Financial\Models\BonusLedgerEntry::where('bonus_id', $bonus->public_id)->delete();
        $bonus->restrictions()->delete(); $bonus->delete();
    }
    fcCleanup();
    $policyIds = collect($GLOBALS['ui27PolicyIds'] ?? [])
        ->merge(WalletHoldPolicy::where('operation_type', 'TEST_UI27_POLICY')->pluck('public_id'));
    \App\Domains\Financial\Models\WalletHoldPolicyChange::whereIn('policy_id', $policyIds)->delete();
    WalletHoldPolicy::whereIn('public_id', $policyIds)->delete();
}
function ui27User(): User
{
    $user = User::factory()->create();
    $user->forceFill(['email_verified_at' => now()])->save();
    return $user;
}
function ui27Wallet(User $user): Wallet
{
    $wallet = fcWallet('ui27-' . str()->uuid(), 10000);
    // Marcar también el crédito inicial para recuperar un fixture interrumpido.
    FinancialTransaction::where('reference_type', 'TEST_SEED')->where('reference_id', $wallet->public_id)
        ->update(['idempotency_key' => fcKey('ui27-seed')]);
    $wallet->update(['owner_id' => (string) $user->getKey()]);
    $GLOBALS['ui27WalletIds'][] = $wallet->public_id;
    return $wallet->fresh();
}
function ui27Payment(Wallet $wallet): FinancialTransaction
{
    return app(LedgerService::class)->debit($wallet, 3000, MovementType::PAGO, fcKey('ui27-payment'), 'TEST_PAYMENT', fcKey('order'));
}
function ui27Allow(array $actions): void
{
    app()->instance(FinancialWebAuthorizer::class, new class($actions) implements FinancialWebAuthorizer {
        public function __construct(private readonly array $actions) {}
        public function allows(string $userId, string $action, ?string $walletId = null): bool
        {
            return in_array($action, $this->actions, true);
        }
    });
}

test('financial adjustments web routes require a signed in session', function () {
    $this->get('/finanzas/ajustes')->assertRedirect('/login');
    $this->postJson('/finanzas/ajustes/refunds', [])->assertUnauthorized();
    $this->postJson('/finanzas/ajustes/policies', [])->assertUnauthorized();
});

test('financial adjustments screen shows only own wallet and denies legacy admin roles', function () {
    $user = ui27User();
    User::whereKey($user->getKey())->update(['roles' => [['name' => 'admin', 'scope_type' => null, 'scope_id' => null]]]);
    $wallet = ui27Wallet($user); $other = ui27Wallet(ui27User());
    fcHoldPolicy();
    app(WalletHoldService::class)->create($wallet, 1000, 'TEST_CHECKOUT', now()->addMinutes(5), fcKey('ui27-hold'), FC_ACTOR, 'Reserva', 'TEST_ORDER', 'own');
    app(WalletHoldService::class)->create($other, 2000, 'TEST_CHECKOUT', now()->addMinutes(5), fcKey('ui27-other-hold'), FC_ACTOR, 'Otra reserva', 'TEST_ORDER', 'other');
    $this->actingAs($user->fresh())->get('/finanzas/ajustes')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Financial/Adjustments')->where('wallet.id', strtolower($wallet->public_id))
        ->has('holds', 1)->where('holds.0.amount_cents', 1000)
        ->where('permissions', fn ($value) => $value['refund.review'] === false && $value['policy.manage'] === false)->has('policies', 0)->where('canRequest', true));
    $this->get('/finanzas/ajustes?wallet_id=' . $other->public_id)->assertForbidden();
});

test('financial adjustments screen supports accounts without a wallet', function () {
    $this->actingAs(ui27User())->get('/finanzas/ajustes')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Financial/Adjustments')->where('wallet', null)->where('canRequest', false)->has('holds', 0));
});

test('web refund requests use the session actor and retry without moving money', function () {
    $user = ui27User(); $wallet = ui27Wallet($user); $payment = ui27Payment($wallet);
    $this->actingAs($user);
    $payload = ['original_transaction_id' => $payment->public_id, 'amount_cents' => 1000, 'reason' => 'Compra cancelada', 'requested_by' => 'forged'];
    $headers = ['Idempotency-Key' => fcKey('ui27-refund')];
    $first = $this->postJson('/finanzas/ajustes/refunds', $payload, $headers)->assertOk();
    $this->postJson('/finanzas/ajustes/refunds', $payload, $headers)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
    $refund = FinancialRefundRequest::where('wallet_id', $wallet->public_id)->sole();
    expect($refund->requested_by)->toBe('user:' . $user->getKey())
        ->and($wallet->fresh()->available_balance_cents)->toBe(7000)
        ->and(FinancialRefundRequest::where('wallet_id', $wallet->public_id)->count())->toBe(1);
    $this->postJson('/finanzas/ajustes/refunds', $payload)->assertUnprocessable();
});

test('web users cannot request a refund from another owners payment or purchase', function () {
    $user = ui27User(); $other = ui27Wallet(ui27User()); $payment = ui27Payment($other);
    $purchase = PurchasePayment::create(['public_id' => (string) str()->uuid(), 'wallet_id' => $other->public_id,
        'idempotency_key' => fcKey('ui27-other-purchase'), 'total_amount_cents' => 3000, 'wallet_amount_cents' => 3000,
        'bonus_amount_cents' => 0, 'bonus_id' => (string) str()->uuid(), 'currency' => 'MXN', 'status' => 'COMPLETADO']);
    $this->actingAs($user);
    $headers = ['Idempotency-Key' => fcKey('ui27-foreign')];
    $this->postJson('/finanzas/ajustes/refunds', ['original_transaction_id' => $payment->public_id,
        'amount_cents' => 1000, 'reason' => 'Motivo'], $headers)->assertNotFound();
    $this->postJson('/finanzas/ajustes/purchase-refunds', ['purchase_payment_id' => $purchase->public_id,
        'wallet_amount_cents' => 1000, 'reason' => 'Motivo'], $headers)->assertNotFound();
    expect(FinancialRefundRequest::where('wallet_id', $other->public_id)->count())->toBe(0)
        ->and(PurchaseRefundRequest::where('wallet_id', $other->public_id)->count())->toBe(0);
});

test('web administrative actions stay forbidden even if submitted outside the interface', function () {
    $user = ui27User(); $wallet = ui27Wallet($user); $payment = ui27Payment($wallet);
    $requestTransaction = app(FinancialAdjustmentService::class)->refund($payment, 1000, fcKey('ui27-request'), 'user:' . $user->getKey(), 'Motivo');
    $refund = FinancialRefundRequest::where('request_transaction_id', $requestTransaction->public_id)->firstOrFail();
    $policy = fcHoldPolicy();
    $hold = app(WalletHoldService::class)->create($wallet, 1000, 'TEST_CHECKOUT', now()->addMinutes(5), fcKey('ui27-hold-denied'), FC_ACTOR, 'Reserva', 'TEST_ORDER', 'own');
    $this->actingAs($user); $headers = ['Idempotency-Key' => fcKey('ui27-denied')];
    foreach (['approve', 'reject', 'complete', 'recover'] as $action) {
        $this->postJson('/finanzas/ajustes/refunds/' . $refund->public_id . '/' . $action, ['reason' => 'Motivo'], $headers)->assertForbidden();
    }
    foreach (['release', 'capture'] as $action) {
        $this->postJson('/finanzas/ajustes/holds/' . $hold->public_id . '/' . $action, ['reason' => 'Motivo'], $headers)->assertForbidden();
    }
    $this->postJson('/finanzas/ajustes/transactions/' . $payment->public_id . '/reverse', ['reason' => 'Motivo'], $headers)->assertForbidden();
    $this->postJson('/finanzas/ajustes/policies', [])->assertForbidden();
    $this->patchJson('/finanzas/ajustes/policies/' . $policy->public_id, [])->assertForbidden();
    $this->getJson('/finanzas/ajustes/policies/' . $policy->public_id . '/history')->assertForbidden();
    expect($wallet->fresh()->available_balance_cents)->toBe(6000)->and($wallet->fresh()->held_balance_cents)->toBe(1000);
});

test('a trusted authorization adapter enables policy changes without replacing the interface', function () {
    $user = ui27User(); $this->actingAs($user);
    ui27Allow(['policy.view', 'policy.manage']);
    $first = $this->postJson('/finanzas/ajustes/policies', ['operation_type' => 'TEST_UI27_POLICY',
        'max_duration_seconds' => 900, 'active' => true, 'reason' => 'Política de prueba', 'actor_id' => 'forged'])->assertOk();
    $id = $first->json('data.id');
    $policy = WalletHoldPolicy::where('public_id', $id)->firstOrFail();
    $GLOBALS['ui27PolicyIds'][] = $policy->public_id;
    expect($policy->created_by)->toBe('user:' . $user->getKey());
    $this->patchJson('/finanzas/ajustes/policies/' . $id, ['expected_version' => 1,
        'max_duration_seconds' => 600, 'active' => false, 'reason' => 'Reducir plazo'])->assertOk();
    $this->patchJson('/finanzas/ajustes/policies/' . $id, ['expected_version' => 1,
        'max_duration_seconds' => 300, 'active' => true, 'reason' => 'Edición obsoleta'])->assertConflict();
    $this->getJson('/finanzas/ajustes/policies/' . $id . '/history')->assertOk()->assertJsonPath('meta.pagination.total', 2);
    $this->get('/finanzas/ajustes')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Financial/Adjustments')->where('permissions', fn ($value) => $value['policy.manage'] === true)->has('policies', 1));
});

test('trusted web authorization can approve and complete a refund once with the real session actor', function () {
    $requester = ui27User(); $wallet = ui27Wallet($requester); $payment = ui27Payment($wallet);
    $service = app(FinancialAdjustmentService::class);
    $requestTransaction = $service->refund($payment, 1000, fcKey('ui27-admin-request'), 'user:' . $requester->getKey(), 'Compra cancelada');
    $refund = FinancialRefundRequest::where('request_transaction_id', $requestTransaction->public_id)->firstOrFail();
    $admin = ui27User(); $this->actingAs($admin);
    ui27Allow(['refund.review', 'refund.execute']);
    $this->postJson('/finanzas/ajustes/refunds/' . $refund->public_id . '/approve', ['reason' => 'Aprobado', 'reviewed_by' => 'forged'])->assertOk();
    $headers = ['Idempotency-Key' => fcKey('ui27-admin-complete')];
    $first = $this->postJson('/finanzas/ajustes/refunds/' . $refund->public_id . '/complete', ['reason' => 'Ejecutar'], $headers)->assertOk();
    $this->postJson('/finanzas/ajustes/refunds/' . $refund->public_id . '/complete', ['reason' => 'Ejecutar'], $headers)
        ->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
    expect($wallet->fresh()->available_balance_cents)->toBe(8000)
        ->and($refund->fresh()->reviewed_by)->toBe('user:' . $admin->getKey());
    $transaction = FinancialTransaction::where('public_id', $first->json('data.id'))->firstOrFail();
    expect($transaction->metadata['executed_by'])->toBe('user:' . $admin->getKey())
        ->and($transaction->metadata['web_action_reason'])->toBe('Ejecutar');
});

test('web mixed purchase refunds preserve the original bonus and wallet allocation', function () {
    $authorization = $this->createMock(\App\Domains\Financial\Contracts\BonusAuthorizationProvider::class);
    $authorization->method('canIssueBonus')->willReturn(true);
    $this->app->instance(\App\Domains\Financial\Contracts\BonusAuthorizationProvider::class, $authorization);
    $user = ui27User(); $wallet = ui27Wallet($user);
    $bonus = app(\App\Domains\Financial\Services\BonusService::class)->issue(
        beneficiaryType: 'STUDENT', beneficiaryId: (string) $user->getKey(), issuerType: 'SYSTEM', issuerId: 'test-fixture',
        type: \App\Domains\Financial\Enums\BonusType::BENEFICIO, amountCents: 2000,
        validFrom: now()->subDay(), expiresAt: now()->addDay(), combinable: true, allowsPartialUse: true,
        externalReference: fcKey('ui27-bonus')
    );
    $paymentKey = fcKey('ui27-purchase');
    app(\App\Domains\Financial\Services\PurchasePaymentService::class)->pay($wallet, 5000, $bonus->public_id, $paymentKey);
    $purchase = PurchasePayment::where('idempotency_key', $paymentKey)->firstOrFail();
    $this->actingAs($user)->get('/finanzas/ajustes')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Financial/Adjustments')->has('purchases', 1)->has('purchases.0.bonuses', 1)
        ->where('purchases.0.bonuses.0.bonus_id', strtolower($bonus->public_id)));
    $headers = ['Idempotency-Key' => fcKey('ui27-mixed-refund')];
    $payload = ['purchase_payment_id' => $purchase->public_id, 'wallet_amount_cents' => 1000,
        'bonus_refunds' => [['bonus_id' => $bonus->public_id, 'amount_cents' => 500]], 'reason' => 'Devolución parcial'];
    $first = $this->postJson('/finanzas/ajustes/purchase-refunds', $payload, $headers)->assertOk();
    $this->postJson('/finanzas/ajustes/purchase-refunds', $payload, $headers)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
    $refund = PurchaseRefundRequest::where('public_id', $first->json('data.id'))->firstOrFail();
    expect($refund->wallet_amount_cents)->toBe(1000)->and($refund->bonus_amount_cents)->toBe(500)
        ->and($wallet->fresh()->available_balance_cents)->toBe(7000)->and($bonus->fresh()->remaining_amount_cents)->toBe(0);
    foreach (['approve', 'reject', 'complete'] as $action) {
        $this->postJson('/finanzas/ajustes/purchase-refunds/' . $refund->public_id . '/' . $action,
            ['reason' => 'Intento sin permiso'], $headers)->assertForbidden();
    }
});
