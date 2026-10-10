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
    $this->auPolicy = StudentTransferPolicy::where('policy_key', 'STUDENT_MXN')->firstOrFail()->getAttributes();
    $this->auUsers = []; $this->auWallets = []; $this->auLimits = [];
    $this->withoutVite(); $this->withoutMiddleware(PreventRequestForgery::class); auGrant(); auDelegate();
    $state = (object) ['down' => false]; $this->auAccountState = $state;
    app()->instance(\App\Domains\Financial\Contracts\TransferAccountProvider::class,
        new class($state) implements \App\Domains\Financial\Contracts\TransferAccountProvider {
            public function __construct(private object $state) {}
            public function assertActive(string $userId): void {
                if ($this->state->down) { throw new \App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException('Identidad temporalmente caída.'); }
                (new \App\Domains\Financial\Adapters\Module1TransferAccountAdapter())->assertActive($userId);
            }
        });
});
afterEach(function () {
    $this->travelBack();
    $ids = $this->auWallets;
    $alerts = TransactionAlert::whereIn('wallet_id', $ids)->pluck('public_id');
    TransactionAlertStatusChange::whereIn('alert_id', $alerts)->delete(); TransactionAlert::whereIn('public_id', $alerts)->delete();
    StudentTransferConfirmation::whereIn('sender_id', $this->auUsers)->delete();
    $transactions = LedgerEntry::whereIn('wallet_id', $ids)->pluck('transaction_id');
    StudentTransfer::whereIn('source_wallet_id', $ids)->orWhereIn('destination_wallet_id', $ids)->delete();
    LedgerEntry::whereIn('transaction_id', $transactions)->delete(); FinancialTransaction::whereIn('original_transaction_id', $transactions)->delete(); FinancialTransaction::whereIn('public_id', $transactions)->delete(); Wallet::whereIn('public_id', $ids)->delete();
    FinancialLimitChange::whereIn('limit_id', $this->auLimits)->delete(); FinancialLimit::whereIn('public_id', $this->auLimits)->delete();
    StudentTransferPolicyChange::whereIn('actor_id', array_map(fn ($id) => 'user:' . $id, $this->auUsers))->delete();
    StudentTransferPolicy::where('policy_key', 'STUDENT_MXN')->update(collect($this->auPolicy)->except('id')->all());
    StudentProfile::whereIn('user_id', $this->auUsers)->delete(); \App\Models\UserSession::whereIn('user_id', $this->auUsers)->delete(); \App\Models\Device::whereIn('user_id', $this->auUsers)->delete(); \App\Models\SecurityEvent::whereIn('user_id', $this->auUsers)->delete(); User::whereIn('_id', $this->auUsers)->delete();
});
function auGrant(bool $send = true, bool $receive = true, bool $manage = true): void
{
    if (!app()->bound('test.au-grants')) {
        $state = new \stdClass(); app()->instance('test.au-grants', $state);
        app()->instance(TransferAuthorizationProvider::class, new class($state) implements TransferAuthorizationProvider {
            public function __construct(private object $state) {}
            public function canSend(string $userId, Wallet $wallet): bool { return $this->state->send; }
            public function canReceive(string $userId, Wallet $wallet): bool { return $this->state->receive; }
            public function canManagePolicy(string $userId): bool { return $this->state->manage; }
        });
    }
    $state = app('test.au-grants'); $state->send = $send; $state->receive = $receive; $state->manage = $manage;
}
function auUser($test, int $balance = 0): array
{
    $u = User::factory()->create(['email' => 'test-transfer-' . Str::uuid() . '@example.test']); $u->forceFill(['email_verified_at' => now(), 'account_activation_pending' => false])->save(); $test->auUsers[] = (string) $u->getKey();
    $w = app(WalletService::class)->create('USER', (string) $u->getKey(), WalletType::USUARIO); $test->auWallets[] = $w->public_id;
    if ($balance) app(LedgerService::class)->credit($w, $balance, MovementType::RECARGA, 'test-au-seed-' . Str::uuid(), 'TEST_SEED', $w->public_id);
    return [$u, $w->fresh()];
}
function auKey(): string { return 'test-au-' . Str::uuid(); }
function auPayload(User $u): array
{
    return ['identity_method' => 'USER_ID', 'recipient' => (string) $u->getKey(), 'amount_cents' => 10000, 'kind' => 'TRANSFERENCIA', 'concept' => 'Material escolar'];
}
function auDelegate(): object
{
    $state = (object) ['users' => [], 'actions' => [], 'digests' => [], 'calls' => 0, 'client' => 'test-transfer-client-' . Str::uuid()];
    app()->instance('test.au-delegation', $state);
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
function auHeaders(User $user, array $scopes = ['financial:transfer:read', 'financial:transfer:prepare', 'financial:transfer:confirm', 'financial:transfer:cancel', 'financial:transfer:policy:manage']): array
{
    $key = (string) config('oauth.signing_key');
    if (str_starts_with($key, 'base64:')) { $key = base64_decode(substr($key, 7)); }
    $token = \Firebase\JWT\JWT::encode(['iss' => config('oauth.issuer'), 'aud' => config('oauth.audience'),
               'sub' => app('test.au-delegation')->client, 'scope' => implode(' ', $scopes), 'iat' => now()->timestamp, 'exp' => now()->addMinutes(5)->timestamp], $key, 'HS256');
    $proof = 'test-proof-' . Str::uuid(); app('test.au-delegation')->users[$proof] = (string) $user->getKey();
    return ['Authorization' => 'Bearer ' . $token, 'X-User-Authorization' => $proof, 'Idempotency-Key' => auKey()];
}
function auApi(string $path = ''): string { return '/api/v1/financial/student-transfers' . $path; }


function auWebPrepare($test, User $sender, User $recipient, string $concept = 'Material'): string
{
    $body = auPayload($recipient); $body['concept'] = $concept;
    return $test->actingAs($sender)->postJson('/finanzas/transferencias/confirmations', $body,
        ['Idempotency-Key' => auKey(), 'X-Correlation-Id' => 'test-audit-preparation'])->assertCreated()->json('data.id');
}
function auWebConfirm($test, User $sender, string $id, ?string $key = null)
{
    return $test->actingAs($sender)->postJson('/finanzas/transferencias/confirmations/' . $id . '/confirm', ['confirmed' => true],
        ['Idempotency-Key' => $key ?? auKey(), 'X-Correlation-Id' => 'test-audit-confirmation']);
}
test('transfer audit records separate web preparation and execution and preserves zero concept', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.25']);
    [$sender, $from] = auUser($this, 20000); [$recipient, $to] = auUser($this);
    $id = auWebPrepare($this, $sender, $recipient, '0');
    $c = StudentTransferConfirmation::where('public_id', $id)->firstOrFail();
        expect($c->concept)->toBe('0')
        ->and($c->preparation_audit['channel'])->toBe('WEB')
        ->and($c->preparation_audit['correlation_id'])->toBe('test-audit-preparation');
    $key = auKey(); $response = auWebConfirm($this, $sender, $id, $key)->assertCreated()->assertJsonPath('data.concept', '0');
    $transfer = StudentTransfer::where('public_id', $response->json('data.id'))->firstOrFail();
    $context = $transfer->audit_context;
      expect($context['channel'])->toBe('WEB')
        ->and($context['client_id'])->toBeNull()
        ->and($context['correlation_id'])->toBe('test-audit-confirmation')
        ->and($context['ip'])->toBe('192.0.2.25');
    expect($c->fresh()->confirmation_audit)->toBe($context);
    $ft = FinancialTransaction::where('public_id', $transfer->financial_transaction_id)->firstOrFail();
    expect($ft->metadata['concept'])->toBe('0')->and($ft->metadata['audit_context'])->toBe($context);
    auWebConfirm($this, $sender, $id, $key)->assertOk();
    expect($transfer->fresh()->audit_context)->toBe($context)->and($from->fresh()->available_balance_cents)->toBe(10000);
});
test('transfer audit records verified external client and rejects forged device attribution', function () {
    [$sender] = auUser($this, 20000); [$recipient] = auUser($this); $h = auHeaders($sender);
    $h['X-Correlation-Id'] = 'test-audit-api'; $h['X-Device-Id'] = 'forged-header';
    $body = auPayload($recipient); $body['device_id'] = 'forged-body'; $body['session_id'] = 'forged-session';
    $id = $this->postJson(auApi('/confirmations'), $body, $h)->assertCreated()->json('data.id');
    $h['Idempotency-Key'] = auKey();
    $response = $this->postJson(auApi('/confirmations/' . $id . '/confirm'), ['confirmed' => true], $h)->assertCreated();
    $transfer = StudentTransfer::where('public_id', $response->json('data.id'))->firstOrFail();
    expect($transfer->audit_context['channel'])->toBe('API')->and($transfer->audit_context['client_id'])->toBe(app('test.au-delegation')->client)
        ->and($transfer->audit_context['device_id'])->toBeNull()->and($transfer->audit_context['session_id'])->toBeNull()
        ->and($transfer->audit_context['identity_context_status'])->toBe('NOT_PROVIDED');
});
test('account contract outage rejects preparation and confirmation without partial money and permits retry', function () {
    [$sender, $from] = auUser($this, 20000); [$recipient, $to] = auUser($this);
    $this->auAccountState->down = true;
    $this->actingAs($sender)->postJson('/finanzas/transferencias/confirmations', auPayload($recipient), ['Idempotency-Key' => auKey()])->assertStatus(503);
    expect(StudentTransferConfirmation::where('sender_id', (string) $sender->getKey())->count())->toBe(0);
    $this->auAccountState->down = false; $id = auWebPrepare($this, $sender, $recipient); $key = auKey();
    $this->auAccountState->down = true; auWebConfirm($this, $sender, $id, $key)->assertStatus(503);
    expect($from->fresh()->available_balance_cents)->toBe(20000)->and($to->fresh()->available_balance_cents)->toBe(0)
        ->and(StudentTransfer::where('sender_id', (string) $sender->getKey())->count())->toBe(0);
    $this->auAccountState->down = false; auWebConfirm($this, $sender, $id, $key)->assertCreated();
});
test('trusted session adapter ignores foreign and revoked session references', function () {
    [$sender] = auUser($this); [$other] = auUser($this);
    $session = \App\Models\UserSession::create(['user_id' => (string) $sender->getKey(), 'device_id' => 'test-device-reference', 'started_at' => now(), 'last_activity_at' => now()]);
    $adapter = new \App\Domains\Financial\Adapters\Module1TransferSessionContextAdapter();
    $context = $adapter->resolve((string) $sender->getKey(), (string) $session->getKey());
    expect($context['status'])->toBe('VERIFIED')->and($context['device_id'])->toBe('test-device-reference');
    expect($adapter->resolve((string) $other->getKey(), (string) $session->getKey())['status'])->toBe('NOT_VERIFIED');
    $session->update(['revoked_at' => now()]);
    expect($adapter->resolve((string) $sender->getKey(), (string) $session->getKey())['device_id'])->toBeNull();
});
test('participant detail returns own entry and references without exposing device ip or retry secrets', function () {
    [$sender] = auUser($this, 20000); [$recipient] = auUser($this); [$other] = auUser($this);
    $id = auWebPrepare($this, $sender, $recipient); $tid = auWebConfirm($this, $sender, $id)->assertCreated()->json('data.id');
    $this->actingAs($recipient)->getJson('/finanzas/transferencias/' . $tid)->assertOk()
        ->assertJsonPath('data.direction', 'ENTRADA')->assertJsonCount(1, 'data.own_ledger')
        ->assertJsonPath('data.own_ledger.0.amount_cents', 10000)->assertJsonMissingPath('data.audit_context')
        ->assertJsonMissingPath('data.confirmation.retry_key')->assertJsonMissingPath('data.trace.ip')
        ->assertJsonMissingPath('data.trace.device_id')->assertJsonMissingPath('data.own_ledger.0.balance_after_cents');
    $this->actingAs($other)->getJson('/finanzas/transferencias/' . $tid)->assertNotFound();
});
test('history filters kind and direction retain participant isolation for web and api', function () {
    [$sender] = auUser($this, 30000); [$recipient] = auUser($this); [$other] = auUser($this);
    $id = auWebPrepare($this, $sender, $recipient); auWebConfirm($this, $sender, $id)->assertCreated();
    $body = auPayload($recipient); $body['kind'] = 'REGALO';
    $id = $this->actingAs($sender)->postJson('/finanzas/transferencias/confirmations', $body, ['Idempotency-Key' => auKey()])->assertCreated()->json('data.id');
    auWebConfirm($this, $sender, $id)->assertCreated();
    $url = '/finanzas/transferencias/records?kind=REGALO&direction=SALIDA';
    $this->actingAs($sender)->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.kind', 'REGALO');
    $this->actingAs($other)->getJson($url)->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($sender)->getJson('/finanzas/transferencias/records?kind=invalid')->assertUnprocessable();
    $this->getJson(auApi('/records?kind=REGALO&direction=ENTRADA'), auHeaders($recipient))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.direction', 'ENTRADA');
});
test('reversal of a confirmed student transfer or gift preserves history and updates participant detail', function (string $kind) {
    [$sender, $from] = auUser($this, 20000); [$recipient, $to] = auUser($this);
    $body = auPayload($recipient); $body['kind'] = $kind;
    $id = $this->actingAs($sender)->postJson('/finanzas/transferencias/confirmations', $body, ['Idempotency-Key' => auKey()])->assertCreated()->json('data.id');
    $response = auWebConfirm($this, $sender, $id)->assertCreated(); $tid = $response->json('data.id');
    $ft = FinancialTransaction::where('public_id', $response->json('data.financial_transaction_id'))->firstOrFail();
    $service = app(\App\Domains\Financial\Services\FinancialAdjustmentService::class); $key = auKey();
    $reversal = $service->reverse($ft, $key, 'Corrección autorizada de prueba', 'test-supervisor');
    $again = $service->reverse($ft, $key, 'Corrección autorizada de prueba', 'test-supervisor');
    expect(strtolower($again->public_id))->toBe(strtolower($reversal->public_id));
    expect($from->fresh()->available_balance_cents)->toBe(20000)->and($to->fresh()->available_balance_cents)->toBe(0);
    $this->actingAs($sender)->getJson('/finanzas/transferencias/' . $tid)->assertOk()->assertJsonPath('data.status', 'REVERTIDA')
        ->assertJsonPath('data.executed_status', 'COMPLETADA')->assertJsonPath('data.reversal.id', strtolower($reversal->public_id));
    $this->postJson('/finanzas/transferencias/confirmations/' . $id . '/cancel')->assertConflict();
})->with(['TRANSFERENCIA', 'REGALO']);
test('policy update and cancelled preview keep separate immutable audit context', function () {
    [$sender] = auUser($this, 20000); [$recipient] = auUser($this); $id = auWebPrepare($this, $sender, $recipient);
    $url = '/finanzas/transferencias/confirmations/' . $id . '/cancel';
    $this->postJson($url, [], ['X-Correlation-Id' => 'test-audit-cancel'])->assertOk();
    $c = StudentTransferConfirmation::where('public_id', $id)->firstOrFail();
    expect($c->cancellation_audit['correlation_id'])->toBe('test-audit-cancel');
    $this->postJson($url, [], ['X-Correlation-Id' => 'another-cancel'])->assertOk();
    expect($c->fresh()->cancellation_audit)->toBe($c->cancellation_audit);
    $p = StudentTransferPolicy::where('policy_key', 'STUDENT_MXN')->firstOrFail();
    $this->patchJson('/finanzas/transferencias/policy', ['expected_version' => $p->version, 'confirmation_seconds' => 120, 'reason' => 'Auditoría'], ['X-Correlation-Id' => 'test-audit-policy'])->assertOk();
    $change = StudentTransferPolicyChange::where('actor_id', 'user:' . $sender->getKey())->latest('id')->firstOrFail();
    expect($change->audit_context['channel'])->toBe('WEB')->and($change->audit_context['correlation_id'])->toBe('test-audit-policy');
});
test('legacy transfer detail reports absent audit explicitly and internal calls remain compatible', function () {
    [$sender, $from] = auUser($this, 20000); [$recipient, $to] = auUser($this);
    $transfer = app(\App\Domains\Financial\Services\StudentTransferService::class)->execute((string) $sender->getKey(), $from, $to, 10000,
        \App\Domains\Financial\Enums\StudentTransferKind::TRANSFERENCIA, '0', auKey());
    expect($transfer->audit_context['channel'])->toBe('INTERNAL')->and($transfer->concept)->toBe('0');
    $transfer->update(['audit_context' => null]);
    $detail = app(\App\Domains\Financial\Services\StudentTransferQueryService::class)->detail((string) $sender->getKey(), $transfer->public_id);
    expect($detail['trace'])->toBeNull()->and($detail['confirmation'])->toBeNull()->and($detail['reversal'])->toBeNull();
});

test('failed confirmation audit persistence rolls back money and preserves original preparation evidence', function () {
    [$sender, $from] = auUser($this, 20000); [$recipient, $to] = auUser($this);
    $id = auWebPrepare($this, $sender, $recipient); $key = auKey();
    $c = StudentTransferConfirmation::where('public_id', $id)->firstOrFail(); $original = $c->preparation_audit;
    StudentTransferConfirmation::updating(function ($row) { if ($row->status === 'COMPLETADA') { throw new RuntimeException('test-audit-failure'); } });
    try { auWebConfirm($this, $sender, $id, $key)->assertStatus(500); }
    finally { StudentTransferConfirmation::flushEventListeners(); }
    expect($from->fresh()->available_balance_cents)->toBe(20000)->and($to->fresh()->available_balance_cents)->toBe(0)
        ->and($c->fresh()->confirmation_audit)->toBeNull()->and($c->fresh()->preparation_audit)->toBe($original)
        ->and(StudentTransfer::where('sender_id', (string) $sender->getKey())->count())->toBe(0);
    auWebConfirm($this, $sender, $id, $key)->assertCreated();
});
