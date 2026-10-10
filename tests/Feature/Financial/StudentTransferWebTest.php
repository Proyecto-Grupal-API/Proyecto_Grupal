<?php
use App\Domains\Financial\Contracts\IdentityProvider;
use App\Domains\Financial\Contracts\TransferAuthorizationProvider;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\FinancialLimit;
use App\Domains\Financial\Models\FinancialLimitChange;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\StudentTransfer;
use App\Domains\Financial\Models\StudentTransferConfirmation;
use App\Domains\Financial\Models\StudentTransferPolicy;
use App\Domains\Financial\Models\StudentTransferPolicyChange;
use App\Domains\Financial\Models\TransactionAlert;
use App\Domains\Financial\Models\TransactionAlertStatusChange;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\FinancialLimitService;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\StudentTransferPolicyService;
use App\Domains\Financial\Services\WalletService;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    if (config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing') { throw new RuntimeException('Solo se permite la base financiera de pruebas.'); }
    $this->tfPolicy = StudentTransferPolicy::where('policy_key', 'STUDENT_MXN')->firstOrFail()->getAttributes();
    $this->tfUsers = []; $this->tfWallets = []; $this->tfLimits = [];
    $this->withoutVite(); $this->withoutMiddleware(PreventRequestForgery::class); tfGrant();
});
afterEach(function () {
    $this->travelBack();
    $ids = $this->tfWallets;
    $alerts = TransactionAlert::whereIn('wallet_id', $ids)->pluck('public_id');
    TransactionAlertStatusChange::whereIn('alert_id', $alerts)->delete(); TransactionAlert::whereIn('public_id', $alerts)->delete();
    StudentTransferConfirmation::whereIn('sender_id', $this->tfUsers)->delete();
    $transactions = LedgerEntry::whereIn('wallet_id', $ids)->pluck('transaction_id');
    StudentTransfer::whereIn('source_wallet_id', $ids)->orWhereIn('destination_wallet_id', $ids)->delete();
    LedgerEntry::whereIn('transaction_id', $transactions)->delete(); FinancialTransaction::whereIn('public_id', $transactions)->delete(); Wallet::whereIn('public_id', $ids)->delete();
    FinancialLimitChange::whereIn('limit_id', $this->tfLimits)->delete(); FinancialLimit::whereIn('public_id', $this->tfLimits)->delete();
    StudentTransferPolicyChange::whereIn('actor_id', array_map(fn ($id) => 'user:' . $id, $this->tfUsers))->delete();
    StudentTransferPolicy::where('policy_key', 'STUDENT_MXN')->update(collect($this->tfPolicy)->except('id')->all());
    StudentProfile::whereIn('user_id', $this->tfUsers)->delete(); User::whereIn('_id', $this->tfUsers)->delete();
});
function tfGrant(bool $send = true, bool $receive = true, bool $manage = true): void
{
    if (!app()->bound('test.tf-grants')) {
        $state = new \stdClass(); app()->instance('test.tf-grants', $state);
        app()->instance(TransferAuthorizationProvider::class, new class($state) implements TransferAuthorizationProvider {
            public function __construct(private object $state) {}
            public function canSend(string $userId, Wallet $wallet): bool { return $this->state->send; }
            public function canReceive(string $userId, Wallet $wallet): bool { return $this->state->receive; }
            public function canManagePolicy(string $userId): bool { return $this->state->manage; }
        });
    }
    $state = app('test.tf-grants'); $state->send = $send; $state->receive = $receive; $state->manage = $manage;
}
function tfUser($test, int $balance = 0): array
{
    $u = User::factory()->create(['email' => 'test-transfer-' . Str::uuid() . '@example.test']); $u->forceFill(['email_verified_at' => now(), 'account_activation_pending' => false])->save(); $test->tfUsers[] = (string) $u->getKey();
    $w = app(WalletService::class)->create('USER', (string) $u->getKey(), WalletType::USUARIO); $test->tfWallets[] = $w->public_id;
    if ($balance) app(LedgerService::class)->credit($w, $balance, MovementType::RECARGA, 'test-tf-seed-' . Str::uuid(), 'TEST_SEED', $w->public_id);
    return [$u, $w->fresh()];
}
function tfKey(): string { return 'test-tf-' . Str::uuid(); }
function tfPayload(User $u): array
{
    return ['identity_method' => 'USER_ID', 'recipient' => (string) $u->getKey(), 'amount_cents' => 10000, 'kind' => 'TRANSFERENCIA', 'concept' => 'Material escolar'];
}
function tfPrepare($test, User $sender, User $recipient, ?array $payload = null, ?string $key = null): string
{
    return $test->actingAs($sender)->postJson('/finanzas/transferencias/confirmations', $payload ?? tfPayload($recipient), ['Idempotency-Key' => $key ?? tfKey()])->assertCreated()->json('data.id');
}
function tfConfirmUrl(string $id): string { return '/finanzas/transferencias/confirmations/' . $id . '/confirm'; }

test('transfer web requires authentication and the pending role adapter keeps send and policy editing closed', function () {
    $this->postJson('/finanzas/transferencias/confirmations', [])->assertUnauthorized();
    [$u] = tfUser($this, 20000); $u->assignRole('admin');
    app()->instance(TransferAuthorizationProvider::class, new \App\Domains\Financial\Adapters\PendingTransferAuthorizationProvider());
    $this->actingAs($u)->get('/finanzas/transferencias')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Financial/Transfers')->where('permissions.send', false)->where('permissions.manage', false)->where('policy.confirmation_seconds', 300));
    $this->postJson('/finanzas/transferencias/confirmations', [])->assertForbidden();
    $this->patchJson('/finanzas/transferencias/policy', [])->assertForbidden(); $this->getJson('/finanzas/transferencias/policy/history')->assertForbidden();
});
test('preparation shows a bound recipient amount and concept without moving money and retries once', function () {
    [$sender, $from] = tfUser($this, 20000); [$recipient, $to] = tfUser($this); $key = tfKey(); $payload = tfPayload($recipient); $payload['sender_id'] = 'forged';
    $first = $this->actingAs($sender)->postJson('/finanzas/transferencias/confirmations', $payload, ['Idempotency-Key' => $key])->assertCreated()
        ->assertJsonPath('data.recipient.name', $recipient->name)->assertJsonPath('data.recipient.user_id', (string) $recipient->getKey())->assertJsonPath('data.amount_cents', 10000);
    $this->postJson('/finanzas/transferencias/confirmations', $payload, ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
    $payload['amount_cents'] = 20000; $this->postJson('/finanzas/transferencias/confirmations', $payload, ['Idempotency-Key' => $key])->assertConflict();
    expect($from->fresh()->available_balance_cents)->toBe(20000)->and($to->fresh()->available_balance_cents)->toBe(0)
        ->and(StudentTransfer::where('sender_id', (string) $sender->getKey())->count())->toBe(0);
});
test('enrollment resolves exactly one academic account and confirmation displays its enrollment', function () {
    [$sender] = tfUser($this, 20000); [$recipient] = tfUser($this);
    StudentProfile::create(['user_id' => (string) $recipient->getKey(), 'enrollment_number' => 'TEST-TF-2026', 'academic_status' => 'active']);
    $payload = tfPayload($recipient); $payload['identity_method'] = 'ENROLLMENT'; $payload['recipient'] = 'TEST-TF-2026';
    $id = tfPrepare($this, $sender, $recipient, $payload);
    $this->getJson('/finanzas/transferencias/confirmations/' . $id)->assertOk()->assertJsonPath('data.recipient.enrollment_number', 'TEST-TF-2026');
    [$other] = tfUser($this); StudentProfile::create(['user_id' => (string) $other->getKey(), 'enrollment_number' => 'TEST-TF-2026', 'academic_status' => 'active']);
    $this->postJson('/finanzas/transferencias/confirmations', $payload, ['Idempotency-Key' => tfKey()])->assertConflict();
});
test('qr uses the identity contract only once on preparation replay and exposes no raw code', function () {
    [$sender, $from] = tfUser($this, 20000); [$recipient] = tfUser($this); $calls = new \stdClass(); $calls->count = 0;
    app()->instance(IdentityProvider::class, new class((string) $recipient->getKey(), $calls) implements IdentityProvider {
        public function __construct(private string $id, private object $calls) {}
        public function validateQr(string $code, ?string $validatedByUserId = null, ?string $context = null, ?string $ipAddress = null): array {
            $this->calls->count++; expect($context)->toBe('FINANCIAL_TRANSFER_RECIPIENT'); return ['ok' => true, 'identity' => ['user_id' => $this->id]];
        }
    });
    $payload = tfPayload($recipient); $payload['identity_method'] = 'QR'; $payload['recipient'] = 'TEST-QR-SECRET'; $key = tfKey();
    $first = $this->actingAs($sender)->postJson('/finanzas/transferencias/confirmations', $payload, ['Idempotency-Key' => $key])->assertCreated();
    $this->postJson('/finanzas/transferencias/confirmations', $payload, ['Idempotency-Key' => $key])->assertOk();
    $row = StudentTransferConfirmation::where('public_id', $first->json('data.id'))->firstOrFail();
    expect($calls->count)->toBe(1)->and(json_encode($row->toArray()))->not->toContain('TEST-QR-SECRET')->and($from->fresh()->available_balance_cents)->toBe(20000);
});
test('unavailable or malformed identity qr rejects the preview without financial changes', function () {
    [$sender, $from] = tfUser($this, 20000); [$recipient] = tfUser($this); $payload = tfPayload($recipient); $payload['identity_method'] = 'QR';
    $state = new \stdClass(); $state->malformed = false;
    app()->instance(IdentityProvider::class, new class($state) implements IdentityProvider {
        public function __construct(private object $state) {}
        public function validateQr(string $code, ?string $validatedByUserId = null, ?string $context = null, ?string $ipAddress = null): array {
            if (!$this->state->malformed) throw new RuntimeException('test-tf-identity-down'); return ['ok' => true, 'identity' => ['name' => 'Sin ID']];
        }
    });
    foreach ([false, true] as $malformed) {
        $state->malformed = $malformed;
        $this->actingAs($sender)->postJson('/finanzas/transferencias/confirmations', $payload, ['Idempotency-Key' => tfKey()])->assertStatus(503);
    }
    expect($from->fresh()->available_balance_cents)->toBe(20000)->and(StudentTransferConfirmation::where('sender_id', (string) $sender->getKey())->count())->toBe(0);
});
test('explicit immutable confirmation posts a gift once and returns the same operation on retry', function () {
    [$sender, $from] = tfUser($this, 20000); [$recipient, $to] = tfUser($this); $payload = tfPayload($recipient); $payload['kind'] = 'REGALO';
    $id = tfPrepare($this, $sender, $recipient, $payload); $key = tfKey();
    $this->postJson(tfConfirmUrl($id), ['confirmed' => false], ['Idempotency-Key' => $key])->assertUnprocessable();
    $this->postJson(tfConfirmUrl($id), ['confirmed' => true, 'amount_cents' => 1], ['Idempotency-Key' => $key])->assertUnprocessable();
    $first = $this->postJson(tfConfirmUrl($id), ['confirmed' => true], ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('data.kind', 'REGALO');
    $this->postJson(tfConfirmUrl($id), ['confirmed' => true], ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('data.id', $first->json('data.id'))->assertJsonPath('replayed', true);
    expect($from->fresh()->available_balance_cents)->toBe(10000)->and($to->fresh()->available_balance_cents)->toBe(10000);
    $this->postJson('/finanzas/transferencias/confirmations/' . $id . '/cancel')->assertConflict();
});
test('confirmation and history are isolated to their owner or financial participants', function () {
    [$sender] = tfUser($this, 20000); [$recipient] = tfUser($this); [$other] = tfUser($this);
    $id = tfPrepare($this, $sender, $recipient); $key = tfKey();
    $this->actingAs($other)->getJson('/finanzas/transferencias/confirmations/' . $id)->assertNotFound();
    $this->postJson(tfConfirmUrl($id), ['confirmed' => true], ['Idempotency-Key' => $key])->assertNotFound();
    $this->postJson('/finanzas/transferencias/confirmations/' . $id . '/cancel')->assertNotFound();
    $transfer = $this->actingAs($sender)->postJson(tfConfirmUrl($id), ['confirmed' => true], ['Idempotency-Key' => $key])->assertCreated()->json('data.id');
    $this->actingAs($recipient)->getJson('/finanzas/transferencias/' . $transfer)->assertOk()->assertJsonPath('data.direction', 'ENTRADA');
    $this->actingAs($other)->getJson('/finanzas/transferencias/' . $transfer)->assertNotFound();
    $this->getJson('/finanzas/transferencias/records')->assertOk()->assertJsonCount(0, 'data');
});
test('expired or cancelled preparations cannot move money and cancellation is idempotent', function () {
    [$sender, $from] = tfUser($this, 20000); [$recipient, $to] = tfUser($this);
    $id = tfPrepare($this, $sender, $recipient); StudentTransferConfirmation::where('public_id', $id)->update(['expires_at' => now()->subSecond()]);
    $this->postJson(tfConfirmUrl($id), ['confirmed' => true], ['Idempotency-Key' => tfKey()])->assertConflict();
    $this->getJson('/finanzas/transferencias/confirmations/' . $id)->assertOk()->assertJsonPath('data.status', 'VENCIDA');
    $cancelled = tfPrepare($this, $sender, $recipient);
    foreach ([1, 2] as $attempt) $this->postJson('/finanzas/transferencias/confirmations/' . $cancelled . '/cancel')->assertOk()->assertJsonPath('data.status', 'CANCELADA');
    $this->postJson(tfConfirmUrl($cancelled), ['confirmed' => true], ['Idempotency-Key' => tfKey()])->assertConflict();
    expect($from->fresh()->available_balance_cents)->toBe(20000)->and($to->fresh()->available_balance_cents)->toBe(0);
});
test('confirmation rechecks changed account grants balances and policy rather than trusting the preview', function () {
    [$sender, $from] = tfUser($this, 20000); [$recipient, $to] = tfUser($this); $id = tfPrepare($this, $sender, $recipient); $key = tfKey();
    tfGrant(false); $this->postJson(tfConfirmUrl($id), ['confirmed' => true], ['Idempotency-Key' => $key])->assertForbidden();
    tfGrant(true, false); $this->postJson(tfConfirmUrl($id), ['confirmed' => true], ['Idempotency-Key' => $key])->assertForbidden();
    tfGrant(); $recipient->update(['account_activation_pending' => true]); $this->postJson(tfConfirmUrl($id), ['confirmed' => true], ['Idempotency-Key' => $key])->assertConflict();
    $recipient->update(['account_activation_pending' => false]); $from->update(['available_balance_cents' => 1000]);
    $this->postJson(tfConfirmUrl($id), ['confirmed' => true], ['Idempotency-Key' => $key])->assertConflict(); $from->update(['available_balance_cents' => 20000]);
    $policy = app(StudentTransferPolicyService::class); $policy->update((string) $sender->getKey(), $policy->current()->version, ['maximum_cents' => 5000], 'Reducir límite');
    $this->postJson(tfConfirmUrl($id), ['confirmed' => true], ['Idempotency-Key' => $key])->assertConflict();
    expect($to->fresh()->available_balance_cents)->toBe(0);
});
test('changed wallet ownership after preparation rolls back the whole attempted confirmation', function () {
    [$sender, $from] = tfUser($this, 20000);
[$recipient, $to] = tfUser($this);

$other = User::factory()->create(['email' => 'test-transfer-' . Str::uuid() . '@example.test']);
$other->forceFill([
    'email_verified_at' => now(),
    'account_activation_pending' => false,
])->save();

$this->tfUsers[] = (string) $other->getKey();
    $id = tfPrepare($this, $sender, $recipient); $to->update(['owner_id' => (string) $other->getKey()]);
    $this->postJson(tfConfirmUrl($id), ['confirmed' => true], ['Idempotency-Key' => tfKey()])->assertConflict();
    expect($from->fresh()->available_balance_cents)->toBe(20000)->and($to->fresh()->available_balance_cents)->toBe(0);
});
test('confirmation persistence failure rolls back transfer and permits only the original retry key', function () {
    [$sender, $from] = tfUser($this, 20000); [$recipient, $to] = tfUser($this); $id = tfPrepare($this, $sender, $recipient); $key = tfKey();
    StudentTransferConfirmation::updating(function ($c) { if ($c->status === 'COMPLETADA') throw new RuntimeException('test-tf-confirm-failure'); });
    try { $this->postJson(tfConfirmUrl($id), ['confirmed' => true], ['Idempotency-Key' => $key])->assertStatus(500); }
    finally { StudentTransferConfirmation::flushEventListeners(); }
    expect($from->fresh()->available_balance_cents)->toBe(20000)->and($to->fresh()->available_balance_cents)->toBe(0)
        ->and(StudentTransferConfirmation::where('public_id', $id)->firstOrFail()->status)->toBe('PENDIENTE');
    $this->postJson(tfConfirmUrl($id), ['confirmed' => true], ['Idempotency-Key' => tfKey()])->assertConflict();
    $this->postJson(tfConfirmUrl($id), ['confirmed' => true], ['Idempotency-Key' => $key])->assertCreated();
});
test('confirmation keys cannot be reused for another preparation and both steps require headers', function () {
    [$sender] = tfUser($this, 40000); [$recipient] = tfUser($this);
    $this->actingAs($sender)->postJson('/finanzas/transferencias/confirmations', tfPayload($recipient))->assertUnprocessable();
    $id = tfPrepare($this, $sender, $recipient); $this->postJson(tfConfirmUrl($id), ['confirmed' => true])->assertUnprocessable();
    $key = tfKey(); $this->postJson(tfConfirmUrl($id), ['confirmed' => true], ['Idempotency-Key' => $key])->assertCreated();
    $second = tfPrepare($this, $sender, $recipient); $this->postJson(tfConfirmUrl($second), ['confirmed' => true], ['Idempotency-Key' => $key])->assertConflict();
});
test('duration edits apply only to new preparations and policy api records actor version and history', function () {
    [$sender] = tfUser($this, 20000); [$recipient] = tfUser($this); $first = tfPrepare($this, $sender, $recipient);
    $version = app(StudentTransferPolicyService::class)->current()->version;
    $this->patchJson('/finanzas/transferencias/policy', ['expected_version' => $version, 'confirmation_seconds' => 60, 'reason' => 'Plazo de prueba'])->assertOk()->assertJsonPath('data.confirmation_seconds', 60);
    $second = tfPrepare($this, $sender, $recipient);
    expect(StudentTransferConfirmation::where('public_id', $first)->firstOrFail()->duration_seconds)->toBe(300)
        ->and(StudentTransferConfirmation::where('public_id', $second)->firstOrFail()->duration_seconds)->toBe(60);
    $this->patchJson('/finanzas/transferencias/policy', ['expected_version' => $version, 'confirmation_seconds' => 120, 'reason' => 'Obsoleto'])->assertConflict();
    $this->getJson('/finanzas/transferencias/policy/history')->assertOk()->assertJsonPath('data.0.actor_id', 'user:' . $sender->getKey());
    tfGrant(true, true, false); $this->patchJson('/finanzas/transferencias/policy', [])->assertForbidden();
});
test('2.10 blocked evidence survives a failed confirmed transfer', function () {
    [$sender, $from] = tfUser($this, 20000); [$recipient, $to] = tfUser($this); $id = tfPrepare($this, $sender, $recipient);
    $limit = app(FinancialLimitService::class)->create(['name' => 'test-tf-limit', 'subject_type' => 'OWNER', 'subject_id' => $from->owner_id,
        'operation' => 'TRANSFERENCIA_SALIDA', 'period' => 'OPERACION', 'metric' => 'MONTO', 'max_amount_cents' => 500, 'action' => 'BLOQUEAR', 'currency' => 'MXN'], 'test-tf-admin');
    $this->tfLimits[] = $limit->public_id;
    $this->postJson(tfConfirmUrl($id), ['confirmed' => true], ['Idempotency-Key' => tfKey()])->assertConflict()->assertJsonPath('code', 'FINANCIAL_LIMIT_EXCEEDED');
    expect($from->fresh()->available_balance_cents)->toBe(20000)->and($to->fresh()->available_balance_cents)->toBe(0)
        ->and(TransactionAlert::where('wallet_id', $from->public_id)->where('reference_type', 'STUDENT_TRANSFER_ATTEMPT')->count())->toBe(1);
});

test('an owner can recover a pending confirmation and its original retry key after a failed attempt', function () {
    [$sender, $from] = tfUser($this, 20000); [$recipient] = tfUser($this); [$other] = tfUser($this);
    $id = tfPrepare($this, $sender, $recipient); $key = tfKey(); $from->update(['available_balance_cents' => 1000]);
    $this->postJson(tfConfirmUrl($id), ['confirmed' => true], ['Idempotency-Key' => $key])->assertConflict();
    $this->getJson('/finanzas/transferencias/confirmations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.retry_key', $key);
    $this->actingAs($other)->getJson('/finanzas/transferencias/confirmations')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($sender)->getJson('/finanzas/transferencias/confirmations/' . $id)->assertOk()->assertJsonPath('data.retry_key', $key);
    $from->update(['available_balance_cents' => 20000]); $this->postJson(tfConfirmUrl($id), ['confirmed' => true], ['Idempotency-Key' => $key])->assertCreated();
});
