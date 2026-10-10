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
    $this->taPolicy = StudentTransferPolicy::where('policy_key', 'STUDENT_MXN')->firstOrFail()->getAttributes();
    $this->taUsers = []; $this->taWallets = []; $this->taLimits = [];
    $this->withoutVite(); $this->withoutMiddleware(PreventRequestForgery::class); taGrant(); taDelegate();
});
afterEach(function () {
    $this->travelBack();
    $ids = $this->taWallets;
    $alerts = TransactionAlert::whereIn('wallet_id', $ids)->pluck('public_id');
    TransactionAlertStatusChange::whereIn('alert_id', $alerts)->delete(); TransactionAlert::whereIn('public_id', $alerts)->delete();
    StudentTransferConfirmation::whereIn('sender_id', $this->taUsers)->delete();
    $transactions = LedgerEntry::whereIn('wallet_id', $ids)->pluck('transaction_id');
    StudentTransfer::whereIn('source_wallet_id', $ids)->orWhereIn('destination_wallet_id', $ids)->delete();
    LedgerEntry::whereIn('transaction_id', $transactions)->delete(); FinancialTransaction::whereIn('public_id', $transactions)->delete(); Wallet::whereIn('public_id', $ids)->delete();
    FinancialLimitChange::whereIn('limit_id', $this->taLimits)->delete(); FinancialLimit::whereIn('public_id', $this->taLimits)->delete();
    StudentTransferPolicyChange::whereIn('actor_id', array_map(fn ($id) => 'user:' . $id, $this->taUsers))->delete();
    StudentTransferPolicy::where('policy_key', 'STUDENT_MXN')->update(collect($this->taPolicy)->except('id')->all());
    StudentProfile::whereIn('user_id', $this->taUsers)->delete(); User::whereIn('_id', $this->taUsers)->delete();
});
function taGrant(bool $send = true, bool $receive = true, bool $manage = true): void
{
    if (!app()->bound('test.ta-grants')) {
        $state = new \stdClass(); app()->instance('test.ta-grants', $state);
        app()->instance(TransferAuthorizationProvider::class, new class($state) implements TransferAuthorizationProvider {
            public function __construct(private object $state) {}
            public function canSend(string $userId, Wallet $wallet): bool { return $this->state->send; }
            public function canReceive(string $userId, Wallet $wallet): bool { return $this->state->receive; }
            public function canManagePolicy(string $userId): bool { return $this->state->manage; }
        });
    }
    $state = app('test.ta-grants'); $state->send = $send; $state->receive = $receive; $state->manage = $manage;
}
function taUser($test, int $balance = 0): array
{
    $u = User::factory()->create(['email' => 'test-transfer-' . Str::uuid() . '@example.test']); $u->forceFill(['email_verified_at' => now(), 'account_activation_pending' => false])->save(); $test->taUsers[] = (string) $u->getKey();
    $w = app(WalletService::class)->create('USER', (string) $u->getKey(), WalletType::USUARIO); $test->taWallets[] = $w->public_id;
    if ($balance) app(LedgerService::class)->credit($w, $balance, MovementType::RECARGA, 'test-ta-seed-' . Str::uuid(), 'TEST_SEED', $w->public_id);
    return [$u, $w->fresh()];
}
function taKey(): string { return 'test-ta-' . Str::uuid(); }
function taPayload(User $u): array
{
    return ['identity_method' => 'USER_ID', 'recipient' => (string) $u->getKey(), 'amount_cents' => 10000, 'kind' => 'TRANSFERENCIA', 'concept' => 'Material escolar'];
}
function taDelegate(): object
{
    $state = (object) ['users' => [], 'actions' => [], 'digests' => [], 'calls' => 0, 'client' => 'test-transfer-client-' . Str::uuid()];
    app()->instance('test.ta-delegation', $state);
    app()->instance(\App\Domains\Financial\Contracts\TransferDelegationProvider::class,
        new class($state) implements \App\Domains\Financial\Contracts\TransferDelegationProvider {
            public function __construct(private object $state) {}
            public function resolve(string $clientId, string $proof, string $action, string $requestDigest): string
            {
                $this->state->calls++;
                if ($clientId !== $this->state->client || !isset($this->state->users[$proof])
                    || (isset($this->state->actions[$proof]) && $this->state->actions[$proof] !== $action)
                    || (isset($this->state->digests[$proof]) && $this->state->digests[$proof] !== $requestDigest)) {
                    throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('Bearer', 'Delegación inválida.');
                }
                return $this->state->users[$proof];
            }
        });
    return $state;
}
function taHeaders(User $user, array $scopes = ['financial:transfer:read', 'financial:transfer:prepare', 'financial:transfer:confirm', 'financial:transfer:cancel', 'financial:transfer:policy:manage']): array
{
    $key = (string) config('oauth.signing_key');
    if (str_starts_with($key, 'base64:')) { $key = base64_decode(substr($key, 7)); }
    $token = \Firebase\JWT\JWT::encode(['iss' => config('oauth.issuer'), 'aud' => config('oauth.audience'),
        'sub' => app('test.ta-delegation')->client, 'scope' => implode(' ', $scopes), 'iat' => now()->timestamp, 'exp' => now()->addMinutes(5)->timestamp], $key, 'HS256');
    $proof = 'test-proof-' . Str::uuid(); app('test.ta-delegation')->users[$proof] = (string) $user->getKey();
    return ['Authorization' => 'Bearer ' . $token, 'X-User-Authorization' => $proof, 'Idempotency-Key' => taKey()];
}
function taApi(string $path = ''): string { return '/api/v1/financial/student-transfers' . $path; }

test('transfer api requires a service token user delegation and an integrated adapter independently', function () {
    [$u] = taUser($this); $headers = taHeaders($u);
    app()->instance(\App\Domains\Financial\Contracts\TransferDelegationProvider::class, new \App\Domains\Financial\Adapters\PendingTransferDelegationProvider());
    $this->getJson(taApi('/records'))->assertUnauthorized();
    $this->getJson(taApi('/records'), array_diff_key($headers, ['X-User-Authorization' => true]))->assertUnauthorized();
    $this->getJson(taApi('/records'), $headers)->assertStatus(503);
});
test('transfer api checks every dedicated scope before consulting delegated identity', function () {
    [$u] = taUser($this); $id = (string) Str::uuid();
    foreach ([
        ['GET', '/records', 'read', 200], ['GET', '/confirmations', 'read', 200],
        ['GET', '/confirmations/' . $id, 'read', 404], ['GET', '/' . $id, 'read', 404],
        ['POST', '/confirmations', 'prepare', 422], ['POST', '/confirmations/' . $id . '/confirm', 'confirm', 404],
        ['POST', '/confirmations/' . $id . '/cancel', 'cancel', 404],
        ['GET', '/policy', 'read', 200],
        ['GET', '/policy/history', 'policy:manage', 200], ['PATCH', '/policy', 'policy:manage', 422],
    ] as [$method, $path, $scope, $status]) {
        $state = app('test.ta-delegation'); $before = $state->calls;
        $this->json($method, taApi($path), ['confirmed' => true], taHeaders($u, ['financial:read', 'financial:write']))->assertForbidden();
        expect($state->calls)->toBe($before);
        $this->json($method, taApi($path), ['confirmed' => true], taHeaders($u, ['financial:transfer:' . $scope]))->assertStatus($status);
    }
});
test('transfer api derives the sender from delegation and posts an immutable gift once', function () {
    [$sender, $from] = taUser($this, 20000); [$recipient, $to] = taUser($this); $h = taHeaders($sender);
    $body = taPayload($recipient); $body['kind'] = 'REGALO';
    $this->postJson(taApi('/confirmations'), $body + ['sender_id' => (string) $recipient->getKey()], $h)->assertUnprocessable();
    $id = $this->postJson(taApi('/confirmations'), $body, $h)->assertCreated()->json('data.id');
    $this->postJson(taApi('/confirmations'), $body, $h)->assertOk()->assertJsonPath('data.id', $id);
    expect($from->fresh()->available_balance_cents)->toBe(20000)->and($to->fresh()->available_balance_cents)->toBe(0);
    $h['Idempotency-Key'] = taKey();
    $this->postJson(taApi('/confirmations/' . $id . '/confirm'), ['confirmed' => true, 'amount_cents' => 1], $h)->assertUnprocessable();
    $first = $this->postJson(taApi('/confirmations/' . $id . '/confirm'), ['confirmed' => true], $h)->assertCreated()->assertJsonPath('data.kind', 'REGALO');
    $transferId = $first->json('data.id');
    $this->postJson(taApi('/confirmations/' . $id . '/confirm'), ['confirmed' => true], $h)->assertOk()->assertJsonPath('data.id', $transferId);
    expect($from->fresh()->available_balance_cents)->toBe(10000)->and($to->fresh()->available_balance_cents)->toBe(10000);
    expect(StudentTransfer::where('sender_id', (string) $sender->getKey())->count())->toBe(1);
});
test('transfer api isolates confirmations and history even with valid service scopes', function () {
    [$sender] = taUser($this, 20000); [$recipient] = taUser($this); [$other] = taUser($this);
    $h = taHeaders($sender); $id = $this->postJson(taApi('/confirmations'), taPayload($recipient), $h)->assertCreated()->json('data.id');
    $foreign = taHeaders($other);
    $this->getJson(taApi('/confirmations/' . $id), $foreign)->assertNotFound();
    $this->postJson(taApi('/confirmations/' . $id . '/confirm'), ['confirmed' => true], $foreign)->assertNotFound();
    $this->postJson(taApi('/confirmations/' . $id . '/cancel'), [], $foreign)->assertNotFound();
    $h['Idempotency-Key'] = taKey();
    $tid = $this->postJson(taApi('/confirmations/' . $id . '/confirm'), ['confirmed' => true], $h)->assertCreated()->json('data.id');
    $this->getJson(taApi('/' . $tid), $foreign)->assertNotFound();
    $this->getJson(taApi('/records'), $foreign)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(taApi('/' . $tid), taHeaders($recipient))->assertOk()->assertJsonPath('data.direction', 'ENTRADA');
});
test('transfer api contract binds proof to client action path payload and idempotency key', function () {
    [$u] = taUser($this, 20000); [$recipient] = taUser($this); $h = taHeaders($u); $state = app('test.ta-delegation');
    $proof = $h['X-User-Authorization']; $state->actions[$proof] = 'prepare';
    $body = taPayload($recipient);
    $request = \Illuminate\Http\Request::create(taApi('/confirmations'), 'POST', $body);
    $request->headers->set('Idempotency-Key', $h['Idempotency-Key']);
    $state->digests[$proof] = \App\Http\Middleware\ResolveTransferDelegation::digest($request);
    $this->getJson(taApi('/records'), $h)->assertUnauthorized();
    $changed = $body; $changed['amount_cents'] = 20000;
    $this->postJson(taApi('/confirmations'), $changed, $h)->assertUnauthorized();
    $different = $h; $different['Idempotency-Key'] = taKey();
    $this->postJson(taApi('/confirmations'), $body, $different)->assertUnauthorized();
    $this->postJson(taApi('/confirmations'), $body, $h)->assertCreated();
});
test('transfer api refuses malformed identity and forged origin fields before money moves', function () {
    [$u, $w] = taUser($this, 20000); [$recipient] = taUser($this); $h = taHeaders($u);
    foreach (['actor_id', 'user_id', 'source_wallet_id'] as $field) {
        $this->postJson(taApi('/confirmations'), taPayload($recipient) + [$field => 'forged'], $h)->assertUnprocessable();
    }
    app('test.ta-delegation')->users[$h['X-User-Authorization']] = 'bad-account';
    $this->postJson(taApi('/confirmations'), taPayload($recipient), $h)->assertStatus(503);
    expect($w->fresh()->available_balance_cents)->toBe(20000);
});
test('transfer api policy scope never substitutes for trusted administrative permission', function () {
    [$u] = taUser($this); $h = taHeaders($u); taGrant(true, true, false);
    $this->getJson(taApi('/policy/history'), $h)->assertForbidden();
    $this->patchJson(taApi('/policy'), [], $h)->assertForbidden();
    taGrant(true, true, true);
    $policy = StudentTransferPolicy::where('policy_key', 'STUDENT_MXN')->firstOrFail();
    $this->patchJson(taApi('/policy'), ['expected_version' => $policy->version, 'reason' => 'Plazo de prueba', 'confirmation_seconds' => 60], $h)
        ->assertOk()->assertJsonPath('data.confirmation_seconds', 60);
    $this->getJson(taApi('/policy/history'), $h)->assertOk()->assertJsonPath('data.0.actor_id', 'user:' . $u->getKey());
});
test('transfer api pending cancellation and idempotency validation remain separate actions', function () {
    [$u, $w] = taUser($this, 20000); [$recipient] = taUser($this); $h = taHeaders($u);
    $this->postJson(taApi('/confirmations'), taPayload($recipient), array_diff_key($h, ['Idempotency-Key' => true]))->assertUnprocessable();
    $id = $this->postJson(taApi('/confirmations'), taPayload($recipient), $h)->assertCreated()->json('data.id');
    foreach ([1, 2] as $attempt) {
        $this->postJson(taApi('/confirmations/' . $id . '/cancel'), [], $h)->assertOk()->assertJsonPath('data.status', 'CANCELADA');
    }
    $h['Idempotency-Key'] = taKey();
    $this->postJson(taApi('/confirmations/' . $id . '/confirm'), ['confirmed' => true], $h)->assertConflict();
    expect($w->fresh()->available_balance_cents)->toBe(20000);
});

test('transfer api rate limit separates controller actions for the same service', function () {
    [$u] = taUser($this); $h = taHeaders($u);
    for ($i = 0; $i < 30; $i++) { $this->getJson(taApi('/records'), $h)->assertOk(); }
    $before = app('test.ta-delegation')->calls;
    $this->getJson(taApi('/records'), $h)->assertStatus(429);
    expect(app('test.ta-delegation')->calls)->toBe($before);
    $this->getJson(taApi('/confirmations'), $h)->assertOk();
});
