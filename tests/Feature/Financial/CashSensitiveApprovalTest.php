<?php
use App\Domains\Financial\Contracts\CashAuthorizationProvider;
use App\Domains\Financial\Models\CashAdministrativeRequest;
use App\Domains\Financial\Models\CashMovement;
use App\Domains\Financial\Models\CashReceipt;
use App\Domains\Financial\Models\CashRegister;
use App\Domains\Financial\Models\CashRegisterChange;
use App\Domains\Financial\Models\CashShift;
use App\Domains\Financial\Models\FinancialRefundRequest;
use App\Domains\Financial\Models\FinancialWithdrawalRecovery;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Models\Withdrawal;
use App\Domains\Financial\Models\TopUp;
use App\Domains\Financial\Services\CashAdministrativeApprovalService;
use App\Domains\Financial\Services\CashApprovalPolicyService;
use App\Domains\Financial\Services\CashShiftService;
use App\Domains\Financial\Services\CashSettlementService;
use App\Domains\Financial\Services\CashWithdrawalRecoveryService;
use App\Domains\Financial\Services\FinancialAdjustmentService;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\WalletService;
use App\Domains\Financial\Enums\CashMovementType;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\WalletType;
use App\Models\User;
use App\Models\ServiceClient;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
require_once __DIR__.'/Support/FinancialControlHelpers.php';

class TestCashSensitiveProvider implements CashAuthorizationProvider {
    public array $grants = [];
    public function allows(string $actorId, string $associationId, string $action): bool { return in_array($action, $this->grants[$associationId][$actorId] ?? [], true); }
}
beforeEach(function () {
    cashSensitiveCleanup(); $this->withoutMiddleware(PreventRequestForgery::class); $this->withoutVite();
    $this->cashSensitiveProvider = new TestCashSensitiveProvider(); app()->instance(CashAuthorizationProvider::class, $this->cashSensitiveProvider);
    foreach (app('router')->getRoutes() as $route) $route->flushController();
});
afterEach(function () { cashSensitiveCleanup(); });
function cashSensitiveCleanup(): void {
    if (config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing') throw new RuntimeException('Solo base financiera de pruebas.');
    $wallets = Wallet::where('owner_id', 'like', 'f22a00%')->pluck('public_id');
    $refunds = FinancialRefundRequest::whereIn('wallet_id', $wallets)->get();
    $requestIds = $refunds->pluck('request_transaction_id')->merge($refunds->pluck('financial_transaction_id'))->filter();
    CashAdministrativeRequest::where('request_key', 'like', 'test-fc-cash-sensitive-%')->delete();
    FinancialWithdrawalRecovery::whereIn('refund_request_id', $refunds->pluck('public_id'))->delete();
    FinancialRefundRequest::whereIn('public_id', $refunds->pluck('public_id'))->delete();
    $registers = CashRegister::where('association_id', 'like', 'test-fc-cash-sensitive-%')->pluck('id');
    $shifts = CashShift::whereIn('cash_register_id', $registers)->pluck('id');
    $movements = CashMovement::whereIn('cash_shift_id', $shifts)->pluck('id');
    CashReceipt::whereIn('cash_movement_id', $movements)->delete(); CashMovement::whereIn('id', $movements)->delete();
    CashShift::whereIn('id', $shifts)->delete(); CashRegisterChange::whereIn('cash_register_id', $registers)->delete(); CashRegister::whereIn('id', $registers)->delete();
    fcCleanup();
    $ids = LedgerEntry::whereIn('wallet_id', $wallets)->pluck('transaction_id')->merge($requestIds)->unique();
    LedgerEntry::whereIn('wallet_id', $wallets)->delete(); FinancialTransaction::whereIn('public_id', $ids)->delete();
    Withdrawal::whereIn('wallet_id', $wallets)->delete(); TopUp::whereIn('wallet_id', $wallets)->delete(); Wallet::whereIn('public_id', $wallets)->delete();
    ServiceClient::where('client_id', 'like', 'test-fc-cash-sensitive-%')->delete(); User::where('email', 'like', 'test-fc-cash-sensitive-%')->delete();
}
function cashSensitiveUser(): User {
    return User::factory()->create(['_id' => new \MongoDB\BSON\ObjectId('f22a00'.bin2hex(random_bytes(9))),
        'email' => fcKey('cash-sensitive-user').'@example.test', 'email_verified_at' => now()]);
}
function cashSensitiveCase($test, ?string $identity = null): array {
    $operator = cashSensitiveUser(); $student = cashSensitiveUser(); $supervisor = cashSensitiveUser(); $actor = $identity ?? 'user:'.$operator->getKey();
    $wallet = app(WalletService::class)->create('USER', (string) $student->getKey(), WalletType::USUARIO);
    app(LedgerService::class)->credit($wallet, 10000, MovementType::RECARGA, fcKey('cash-sensitive-seed'), 'TEST_SEED', $wallet->public_id);
    $association = fcKey('cash-sensitive-association'); $cash = app(CashShiftService::class);
    $register = $cash->createRegister($association, 'Caja sensible', 'MXN', $actor); $shift = $cash->open($register->public_id, $actor, 10000, fcKey('cash-sensitive-open'));
    $test->cashSensitiveProvider->grants[$association][$actor] = ['read', 'operate', 'adjust', 'recover', 'close', 'approval_manage', 'approval_read', 'approval_review'];
    $test->cashSensitiveProvider->grants[$association]['user:'.$supervisor->getKey()] = ['approval_review', 'approval_read'];
    $test->cashSensitiveProvider->grants[$association]['user:'.$student->getKey()] = ['approval_review'];
    return [$operator, $student, $supervisor, $wallet->fresh(), $shift, $association];
}
function cashSensitivePolicy(CashShift $shift, string $operation = 'ADJUSTMENT', int $threshold = 500, bool $enabled = true, int $version = 0): array {
    return app(CashApprovalPolicyService::class)->configure($shift->cashRegister->association_id, $operation, 'MXN', $enabled, $threshold,
        $version, $shift->agent_id, 'Política de prueba', fcKey('cash-sensitive-policy'));
}
function cashSensitiveRequest(CashShift $shift, int $amount = -500, ?FinancialRefundRequest $refund = null, ?string $key = null): CashAdministrativeRequest {
    return app(CashAdministrativeApprovalService::class)->request($shift->cashRegister->association_id, $shift->public_id,
        $refund ? 'WITHDRAWAL_RECOVERY' : 'ADJUSTMENT', $refund?->amount_cents ?? $amount, $refund?->public_id, $shift->agent_id, 'Registro supervisado', $key ?? fcKey('cash-sensitive-request'));
}
function cashSensitiveReview(CashAdministrativeRequest $p, User $supervisor, bool $approve = true): CashAdministrativeRequest {
    return app(CashAdministrativeApprovalService::class)->review($p->public_id, $p->shift->cashRegister->association_id,
        'user:'.$supervisor->getKey(), $approve, $approve ? 'Revisado' : 'No procede');
}
function cashSensitiveExecute(CashAdministrativeRequest $p, string $key) {
    $shift = $p->shift; $refund = $p->refund;
    return app(CashAdministrativeApprovalService::class)->execute($p->public_id, $shift->cashRegister->association_id, $shift->public_id, $p->operation,
        $p->amount_cents, $p->refund_request_id, $p->operator_id, $p->reason, $key,
        fn ($authorization) => $refund ? app(CashWithdrawalRecoveryService::class)->recover($shift->cashRegister->association_id, $shift->public_id,
            $refund, $key, $p->operator_id, $p->reason, $authorization) : app(CashShiftService::class)->addMovement($shift->public_id,
            CashMovementType::ADJUSTMENT, $p->amount_cents, $key, $p->operator_id, $p->reason, null, null, $authorization?->public_id));
}
function cashSensitiveRefund(CashShift $shift, Wallet $wallet): FinancialRefundRequest {
    $key = fcKey('cash-sensitive-withdraw'); app(CashSettlementService::class)->withdraw($shift->public_id, $wallet, 4000, $key, $shift->agent_id, 'Retiro original');
    $original = FinancialTransaction::where('idempotency_key', $key)->firstOrFail();
    $request = app(FinancialAdjustmentService::class)->refund($original, 2000, fcKey('cash-sensitive-refund'), FC_ACTOR, 'Devolución');
    $refund = FinancialRefundRequest::where('request_transaction_id', $request->public_id)->firstOrFail();
    return app(FinancialAdjustmentService::class)->approveRefund($refund, FC_ACTOR, 'Aprobada');
}
function cashSensitiveToken($test, array $scopes): array {
    $id = fcKey('cash-sensitive-client'); ServiceClient::create(['name' => 'Prueba', 'client_id' => $id, 'secret_hash' => Hash::make('test-secret'), 'scopes' => $scopes, 'active' => true]);
    $token = $test->postJson('/api/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $id, 'client_secret' => 'test-secret', 'scope' => implode(' ', $scopes)])->assertOk()->json('access_token');
    return [['Authorization' => 'Bearer '.$token], 'service:'.$id];
}

test('cash administrative requests use absolute inclusive thresholds preserve signed amounts and freeze policy versions', function () {
    [, , , $wallet, $shift, $association] = cashSensitiveCase($this); cashSensitivePolicy($shift);
    $key = fcKey('cash-sensitive-request'); $negative = cashSensitiveRequest($shift, -500, null, $key); $positive = cashSensitiveRequest($shift, 500); $below = cashSensitiveRequest($shift, -499);
    expect($negative->supervisor_required)->toBeTrue()->and($positive->supervisor_required)->toBeTrue()->and($below->supervisor_required)->toBeFalse()
        ->and($negative->amount_cents)->toBe(-500)->and($below->status)->toBe('READY');
    expect(fcSameId(cashSensitiveRequest($shift, -500, null, $key)->public_id, $negative->public_id))->toBeTrue();
    expect(fn () => cashSensitiveRequest($shift, 500, null, $key))->toThrow(InvalidArgumentException::class);
    cashSensitivePolicy($shift, 'ADJUSTMENT', 1, false, 1);
    expect($negative->fresh()->approval_policy_snapshot['version'])->toBe(1)->and($negative->fresh()->supervisor_required)->toBeTrue()
        ->and(cashSensitiveRequest($shift, -500)->status)->toBe('READY');
    expect(app(CashApprovalPolicyService::class)->snapshot($association, 'ADJUSTMENT', 'USD')['enabled'])->toBeFalse()
        ->and($wallet->fresh()->available_balance_cents)->toBe(10000)->and(CashMovement::where('cash_shift_id', $shift->id)->count())->toBe(0);
});

test('cash adjustment HTTP enforces independent approval records immutable evidence and retries without moving wallet money', function () {
    [$op, , $sup, $wallet, $shift, $assoc] = cashSensitiveCase($this); cashSensitivePolicy($shift); $base = '/finanzas/caja/asociaciones/'.$assoc;
    $url = $base.'/shifts/'.$shift->public_id; $body = ['amount_cents' => -500, 'reason' => 'Registro supervisado']; $key = fcKey('cash-sensitive-adjust');
    $this->actingAs($op)->postJson($url.'/adjustments', $body, ['Idempotency-Key' => $key])->assertConflict();
    $prepare = $this->postJson($url.'/administrative-requests', $body + ['operation' => 'ADJUSTMENT'], ['Idempotency-Key' => fcKey('cash-sensitive-request')])->assertOk();
    $id = $prepare->json('data.id'); $this->postJson($base.'/administrative-approval-requests/'.$id.'/approve', ['reason' => 'Revisado'])->assertForbidden();
    $this->actingAs($sup)->postJson($base.'/administrative-approval-requests/'.$id.'/approve', ['reason' => 'Revisado', 'supervisor_id' => 'forged'])->assertOk()->assertJsonPath('data.supervisor_id', 'user:'.$sup->getKey());
    $this->actingAs($op)->postJson($url.'/adjustments', $body + ['administrative_request_id' => $id], ['Idempotency-Key' => $key])->assertOk();
    $this->postJson($url.'/adjustments', $body + ['administrative_request_id' => $id], ['Idempotency-Key' => $key])->assertOk();
    $this->postJson($url.'/adjustments', $body, ['Idempotency-Key' => $key])->assertConflict();
    $this->postJson($url.'/adjustments', $body + ['administrative_request_id' => $id], ['Idempotency-Key' => fcKey('cash-sensitive-other-key')])->assertConflict();
    $movement = CashMovement::where('idempotency_key', $key)->firstOrFail();
    expect($movement->receipt->snapshot['cash_administrative_request_id'])->toBe($id)->and($movement->receipt->snapshot['supervisor_id'])->toBe('user:'.$sup->getKey())
        ->and($movement->receipt->snapshot['approval_policy']['version'])->toBe(1)->and($wallet->fresh()->available_balance_cents)->toBe(10000)
        ->and(app(CashShiftService::class)->getSummary($shift->public_id)['expected_amount_cents'])->toBe(9500);
});

test('cash administrative authorization cannot be transferred to another amount reason actor shift operation or reference', function () {
    [, , $sup, , $shift, $assoc] = cashSensitiveCase($this); cashSensitivePolicy($shift); $p = cashSensitiveRequest($shift); cashSensitiveReview($p, $sup);
    $service = app(CashAdministrativeApprovalService::class); $called = false;
    foreach ([['ADJUSTMENT', 500, null, $shift->agent_id, $p->reason], ['ADJUSTMENT', -500, null, 'user:other', $p->reason],
        ['ADJUSTMENT', -500, null, $shift->agent_id, 'Otro motivo'], ['WITHDRAWAL_RECOVERY', 500, null, $shift->agent_id, $p->reason]] as [$operation, $amount, $refund, $actor, $reason]) {
        $this->cashSensitiveProvider->grants[$assoc][$actor] = ['adjust', 'recover'];
        expect(fn () => $service->execute($p->public_id, $assoc, $shift->public_id, $operation, $amount, $refund, $actor, $reason, fcKey('cash-sensitive-execute'), function () use (&$called) { $called = true; }))->toThrow(InvalidArgumentException::class);
    }
    expect($called)->toBeFalse()->and(CashMovement::where('cash_shift_id', $shift->id)->count())->toBe(0);
});

test('cash negative adjustments roll back authorization consumption when cash is insufficient and allow the same retry', function () {
    [, , $sup, $wallet, $shift] = cashSensitiveCase($this); cashSensitivePolicy($shift); $p = cashSensitiveRequest($shift, -15000); cashSensitiveReview($p, $sup); $key = fcKey('cash-sensitive-adjust');
    expect(fn () => cashSensitiveExecute($p, $key))->toThrow(InvalidArgumentException::class);
    expect($p->fresh()->status)->toBe('APPROVED')->and($p->fresh()->settlement_key)->toBeNull()->and($wallet->fresh()->available_balance_cents)->toBe(10000);
    app(CashShiftService::class)->addMovement($shift->public_id, CashMovementType::CASH_IN, 6000, fcKey('cash-sensitive-funds'), $shift->agent_id, 'Fondo físico');
    cashSensitiveExecute($p, $key);
    expect($p->fresh()->status)->toBe('CONSUMED')->and(app(CashShiftService::class)->getSummary($shift->public_id)['expected_amount_cents'])->toBe(1000);
});

test('cash administrative rejection cancellation expiration and revoked approval prevent all movement', function () {
    [, , $sup, , $shift, $assoc] = cashSensitiveCase($this); cashSensitivePolicy($shift);
    $rejected = cashSensitiveRequest($shift); cashSensitiveReview($rejected, $sup, false);
    $cancelled = cashSensitiveRequest($shift); app(CashAdministrativeApprovalService::class)->cancel($cancelled->public_id, $shift->agent_id);
    $expired = cashSensitiveRequest($shift); $expired->update(['expires_at' => now()->subSecond()]);
    expect(fn () => cashSensitiveReview($expired, $sup))->toThrow(InvalidArgumentException::class);
    $revoked = cashSensitiveRequest($shift); cashSensitiveReview($revoked, $sup); $this->cashSensitiveProvider->grants[$assoc]['user:'.$sup->getKey()] = [];
    cashSensitivePolicy($shift, 'ADJUSTMENT', 1, false, 1);
    foreach ([$rejected, $cancelled, $expired, $revoked] as $p) expect(fn () => cashSensitiveExecute($p, fcKey('cash-sensitive-execute')))->toThrow(InvalidArgumentException::class);
    expect(CashMovement::where('cash_shift_id', $shift->id)->count())->toBe(0);
});

test('cash recovery obtains its amount from an approved refund and requires supervisor before recording physical cash separately from the wallet refund', function () {
    [$op, $student, $sup, $wallet, $shift, $assoc] = cashSensitiveCase($this); $refund = cashSensitiveRefund($shift, $wallet); cashSensitivePolicy($shift, 'WITHDRAWAL_RECOVERY', 2000);
    $url = '/finanzas/caja/asociaciones/'.$assoc.'/shifts/'.$shift->public_id; $key = fcKey('cash-sensitive-recover');
    $this->actingAs($op)->postJson($url.'/withdrawal-refunds/'.$refund->public_id.'/recover', ['reason' => 'Registro supervisado'], ['Idempotency-Key' => $key])->assertConflict();
    $body = ['operation' => 'WITHDRAWAL_RECOVERY', 'refund_request_id' => $refund->public_id, 'reason' => 'Registro supervisado'];
    $this->postJson($url.'/administrative-requests', $body + ['amount_cents' => 1], ['Idempotency-Key' => fcKey('cash-sensitive-request')])->assertUnprocessable();
    $id = $this->postJson($url.'/administrative-requests', $body, ['Idempotency-Key' => fcKey('cash-sensitive-request')])->assertOk()->assertJsonPath('data.amount_cents', 2000)->json('data.id');
    $review = '/finanzas/caja/asociaciones/'.$assoc.'/administrative-approval-requests/'.$id.'/approve';
    $this->actingAs($student)->postJson($review, ['reason' => 'Propia'])->assertForbidden();
    $this->actingAs($sup)->postJson($review, ['reason' => 'Revisado'])->assertOk();
    $this->actingAs($op)->postJson($url.'/withdrawal-refunds/'.$refund->public_id.'/recover', ['reason' => 'Registro supervisado', 'administrative_request_id' => $id], ['Idempotency-Key' => $key])->assertOk();
    $this->postJson($url.'/withdrawal-refunds/'.$refund->public_id.'/recover', ['reason' => 'Registro supervisado', 'administrative_request_id' => $id], ['Idempotency-Key' => $key])->assertOk();
    expect($wallet->fresh()->available_balance_cents)->toBe(6000)->and($refund->fresh()->status->value)->toBe('APROBADA')
        ->and(app(CashShiftService::class)->getSummary($shift->public_id)['expected_amount_cents'])->toBe(8000);
    app(FinancialAdjustmentService::class)->completeRefund($refund, fcKey('cash-sensitive-complete'), FC_ACTOR);
    expect($wallet->fresh()->available_balance_cents)->toBe(8000)->and(CashMovement::where('cash_shift_id', $shift->id)->where('type', 'WITHDRAWAL_RECOVERY')->count())->toBe(1);
});

test('cash recovery authorization cannot be reused for another refund or foreign association', function () {
    [$op, , $sup, $wallet, $shift, $assoc] = cashSensitiveCase($this); $refund = cashSensitiveRefund($shift, $wallet); cashSensitivePolicy($shift, 'WITHDRAWAL_RECOVERY');
    $p = cashSensitiveRequest($shift, 2000, $refund); cashSensitiveReview($p, $sup);
    $original = FinancialTransaction::where('public_id', $refund->original_transaction_id)->firstOrFail();
    $tx = app(FinancialAdjustmentService::class)->refund($original, 2000, fcKey('cash-sensitive-other-refund'), FC_ACTOR, 'Otra devolución');
    $other = FinancialRefundRequest::where('request_transaction_id', $tx->public_id)->firstOrFail(); app(FinancialAdjustmentService::class)->approveRefund($other, FC_ACTOR, 'Aprobada');
    $this->actingAs($op)->postJson('/finanzas/caja/asociaciones/'.$assoc.'/shifts/'.$shift->public_id.'/withdrawal-refunds/'.$other->public_id.'/recover',
        ['reason' => $p->reason, 'administrative_request_id' => $p->public_id], ['Idempotency-Key' => fcKey('cash-sensitive-recover')])->assertConflict();
    $foreign = fcKey('cash-sensitive-foreign'); $this->cashSensitiveProvider->grants[$foreign][$shift->agent_id] = ['recover', 'approval_read'];
    $register = app(CashShiftService::class)->createRegister($foreign, 'Caja ajena', 'MXN', $shift->agent_id);
    $foreignShift = app(CashShiftService::class)->open($register->public_id, $shift->agent_id, 0, fcKey('cash-sensitive-open'));
    $this->postJson('/finanzas/caja/asociaciones/'.$foreign.'/shifts/'.$foreignShift->public_id.'/administrative-requests',
        ['operation' => 'WITHDRAWAL_RECOVERY', 'refund_request_id' => $refund->public_id, 'reason' => 'Ajena'], ['Idempotency-Key' => fcKey('cash-sensitive-request')])->assertNotFound();
    expect(FinancialWithdrawalRecovery::where('refund_request_id', $refund->public_id)->count())->toBe(0)->and($wallet->fresh()->available_balance_cents)->toBe(6000);
});

test('cash recovery rolls back its authorization movement receipt and recovery together when persistence fails', function () {
    [, , $sup, $wallet, $shift] = cashSensitiveCase($this); $refund = cashSensitiveRefund($shift, $wallet); cashSensitivePolicy($shift, 'WITHDRAWAL_RECOVERY');
    $p = cashSensitiveRequest($shift, 2000, $refund); cashSensitiveReview($p, $sup); $key = fcKey('cash-sensitive-recover');
    FinancialWithdrawalRecovery::creating(function () { throw new RuntimeException('Fallo evidencia de recuperación'); });
    try { expect(fn () => cashSensitiveExecute($p, $key))->toThrow(RuntimeException::class); } finally { FinancialWithdrawalRecovery::flushEventListeners(); }
    expect($p->fresh()->status)->toBe('APPROVED')->and($p->fresh()->settlement_key)->toBeNull()->and(CashMovement::where('idempotency_key', $key)->count())->toBe(0)
        ->and(FinancialWithdrawalRecovery::where('refund_request_id', $refund->public_id)->count())->toBe(0)->and($wallet->fresh()->available_balance_cents)->toBe(6000);
    cashSensitiveExecute($p, $key); cashSensitiveExecute($p, $key);
    expect(CashMovement::where('idempotency_key', $key)->count())->toBe(1)->and($wallet->fresh()->available_balance_cents)->toBe(6000);
    $receipt = CashMovement::where('idempotency_key', $key)->firstOrFail()->receipt;
    expect($receipt->snapshot['cash_administrative_request_id'])->toBe(strtolower($p->public_id))->and($receipt->snapshot['supervisor_id'])->toBe('user:'.$sup->getKey());
});

test('cash administrative queues isolate associations and supervisors cannot approve the operator shift or change decisions', function () {
    [$op, , $sup, , $shift, $assoc] = cashSensitiveCase($this); cashSensitivePolicy($shift); $p = cashSensitiveRequest($shift);
    $base = '/finanzas/caja/asociaciones/'.$assoc; $this->actingAs($op)->getJson($base.'/administrative-approval-requests?per_page=1')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_review', false)->assertJsonPath('meta.pagination.total', 1);
    $this->actingAs($sup)->getJson($base.'/administrative-approval-requests')->assertOk()->assertJsonPath('data.0.can_review', true);
    $foreign = fcKey('cash-sensitive-foreign'); $this->cashSensitiveProvider->grants[$foreign]['user:'.$sup->getKey()] = ['approval_read', 'approval_review'];
    $this->getJson('/finanzas/caja/asociaciones/'.$foreign.'/administrative-approval-requests')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/finanzas/caja/asociaciones/'.$foreign.'/administrative-approval-requests/'.$p->public_id.'/approve', ['reason' => 'Revisado'])->assertNotFound();
    cashSensitiveReview($p, $sup); cashSensitiveReview($p, $sup);
    expect(fn () => cashSensitiveReview($p, $sup, false))->toThrow(InvalidArgumentException::class);
    $this->getJson($base.'/administrative-approval-requests')->assertOk()->assertJsonCount(0, 'data');
});

test('cash administrative API requires the exact adjustment or recovery scope and an independent association grant', function () {
    [$headers, $actor] = cashSensitiveToken($this, ['financial:cash:adjust']); [, , , , $shift, $assoc] = cashSensitiveCase($this, $actor);
    $url = '/api/v1/financial/cash/associations/'.$assoc.'/shifts/'.$shift->public_id.'/administrative-requests';
    $this->postJson($url, ['operation' => 'ADJUSTMENT', 'amount_cents' => -100, 'reason' => 'Prueba'])->assertUnauthorized();
    $headers['Idempotency-Key'] = fcKey('cash-sensitive-request');
    $id = $this->postJson($url, ['operation' => 'ADJUSTMENT', 'amount_cents' => -100, 'reason' => 'Prueba'], $headers)->assertOk()->assertJsonPath('data.status', 'READY')->json('data.id');
    $this->getJson($url.'/'.$id, $headers)->assertOk();
    $this->postJson($url, ['operation' => 'WITHDRAWAL_RECOVERY', 'refund_request_id' => (string) str()->uuid(), 'reason' => 'Prueba'], $headers)->assertForbidden();
    $this->cashSensitiveProvider->grants[$assoc][$actor] = [];
    $this->getJson($url.'/'.$id, $headers)->assertForbidden();
    [$wrong, $other] = cashSensitiveToken($this, ['financial:cash:recover']); $wrong['Idempotency-Key'] = fcKey('cash-sensitive-request');
    $this->cashSensitiveProvider->grants[$assoc][$other] = ['adjust', 'recover'];
    $this->postJson($url, ['operation' => 'ADJUSTMENT', 'amount_cents' => -100, 'reason' => 'Prueba'], $wrong)->assertForbidden();
});

test('cash historical adjustment retries survive newly enabled policies but cannot be retroactively attached to a new authorization', function () {
    [$op, , , , $shift] = cashSensitiveCase($this); $url = '/finanzas/caja/asociaciones/'.$shift->cashRegister->association_id.'/shifts/'.$shift->public_id.'/adjustments';
    $body = ['amount_cents' => -500, 'reason' => 'Registro supervisado']; $key = fcKey('cash-sensitive-historical');
    $this->actingAs($op)->postJson($url, $body, ['Idempotency-Key' => $key])->assertOk(); cashSensitivePolicy($shift);
    $this->postJson($url, $body, ['Idempotency-Key' => $key])->assertOk();
    $this->postJson($url, $body, ['Idempotency-Key' => fcKey('cash-sensitive-new')])->assertConflict();
    $p = cashSensitiveRequest($shift); $sup = cashSensitiveUser(); $this->cashSensitiveProvider->grants[$shift->cashRegister->association_id]['user:'.$sup->getKey()] = ['approval_review']; cashSensitiveReview($p, $sup);
    $this->postJson($url, $body + ['administrative_request_id' => $p->public_id], ['Idempotency-Key' => $key])->assertConflict();
    expect($p->fresh()->status)->toBe('APPROVED')->and($p->fresh()->settlement_key)->toBeNull()->and(CashMovement::where('idempotency_key', $key)->count())->toBe(1);
});

test('cash consumed administrative approval retries after closing expiry and revocation without creating another movement', function () {
    [, , $sup, , $shift, $assoc] = cashSensitiveCase($this); cashSensitivePolicy($shift); $p = cashSensitiveRequest($shift); cashSensitiveReview($p, $sup); $key = fcKey('cash-sensitive-adjust');
    $movement = cashSensitiveExecute($p, $key); $folio = $movement->receipt->folio;
    app(CashShiftService::class)->close($shift->public_id, 9500, fcKey('cash-sensitive-close'), $shift->agent_id, 'Arqueo');
    $p->update(['expires_at' => now()->subSecond()]); $this->cashSensitiveProvider->grants[$assoc]['user:'.$sup->getKey()] = [];
    expect(cashSensitiveExecute($p, $key)->receipt->folio)->toBe($folio)->and(CashMovement::where('idempotency_key', $key)->count())->toBe(1);
    expect(fn () => cashSensitiveExecute($p, fcKey('cash-sensitive-other-key')))->toThrow(InvalidArgumentException::class);
});


test('cash recovery API uses its dedicated scope and server amount through administrative request review and execution', function () {
    [$headers, $actor] = cashSensitiveToken($this, ['financial:cash:recover']); [, , $sup, $wallet, $shift, $assoc] = cashSensitiveCase($this, $actor);
    $refund = cashSensitiveRefund($shift, $wallet); cashSensitivePolicy($shift, 'WITHDRAWAL_RECOVERY', 2000);
    $base = '/api/v1/financial/cash/associations/'.$assoc.'/shifts/'.$shift->public_id;
    $headers['Idempotency-Key'] = fcKey('cash-sensitive-request');
    $id = $this->postJson($base.'/administrative-requests', ['operation' => 'WITHDRAWAL_RECOVERY', 'refund_request_id' => $refund->public_id, 'reason' => 'Registro supervisado'], $headers)
        ->assertOk()->assertJsonPath('data.amount_cents', 2000)->assertJsonPath('data.status', 'PENDING')->json('data.id');
    $this->getJson($base.'/administrative-requests/'.$id, $headers)->assertOk();
    $this->actingAs($sup)->postJson('/finanzas/caja/asociaciones/'.$assoc.'/administrative-approval-requests/'.$id.'/approve', ['reason' => 'Revisado'])->assertOk();
    $headers['Idempotency-Key'] = fcKey('cash-sensitive-recover');
    $this->postJson($base.'/withdrawal-refunds/'.$refund->public_id.'/recover', ['reason' => 'Registro supervisado', 'administrative_request_id' => $id], $headers)->assertOk()->assertJsonPath('data.amount_cents', 2000)->assertJsonPath('data.confirmed_by', $actor);
    expect($wallet->fresh()->available_balance_cents)->toBe(6000);
});
