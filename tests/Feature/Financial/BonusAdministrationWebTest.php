<?php

use App\Domains\Financial\Adapters\PendingBonusAuthorizationProvider;
use App\Domains\Financial\Contracts\BonusAuthorizationProvider;
use App\Domains\Financial\Contracts\FinancialWebAuthorizer;
use App\Domains\Financial\Enums\BonusStatus;
use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Models\Bonus;
use App\Domains\Financial\Models\BonusAdministrativeOperation;
use App\Domains\Financial\Models\BonusLedgerEntry;
use App\Domains\Financial\Models\BonusRestriction;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Support\FinancialJobLock;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    bonusAdminCleanup(); $this->withoutVite();
    $this->withoutMiddleware(PreventRequestForgery::class);
});
afterEach(function () { bonusAdminCleanup(); });
function bonusAdminCleanup(): void
{
    if (config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing') {
        throw new RuntimeException('La limpieza de bonos administrativos solo se permite en la base financiera de pruebas.');
    }
    $operations = BonusAdministrativeOperation::where('idempotency_key', 'like', 'test-bonus-admin-%');
    $ids = $operations->pluck('bonus_id')->merge(Bonus::where('external_reference', 'like', 'test-bonus-admin-%')->pluck('public_id'))->unique();
    BonusAdministrativeOperation::whereIn('bonus_id', $ids)->delete();
    BonusLedgerEntry::whereIn('bonus_id', $ids)->delete();
    BonusRestriction::whereIn('bonus_id', $ids)->delete();
    Bonus::whereIn('public_id', $ids)->delete();
}
function bonusAdminUser(): User
{
    $user = User::factory()->create(); $user->forceFill(['email_verified_at' => now()])->save(); return $user;
}
function bonusAdminGrant(array $actions, bool $issuer = true): void
{
    app()->instance(FinancialWebAuthorizer::class, new class($actions) implements FinancialWebAuthorizer {
        public function __construct(private array $actions) {}
        public function allows(string $userId, string $action, ?string $walletId = null): bool { return in_array($action, $this->actions, true); }
    });
    app()->instance(BonusAuthorizationProvider::class, new class($issuer) implements BonusAuthorizationProvider {
        public function __construct(private bool $allowed) {}
        public function canIssueBonus(string $issuerType, string $issuerId, BonusType $bonusType): bool { return $this->allowed; }
    });
}
function bonusAdminPayload(User $user): array
{
    return ['beneficiary_id' => (string) $user->getKey(), 'type' => 'BENEFICIO', 'amount_cents' => 10000,
        'valid_from' => now()->subMinute()->toISOString(), 'expires_at' => now()->addDay()->toISOString(),
        'combinable' => true, 'allows_partial_use' => true, 'reason' => 'Apoyo autorizado de prueba',
        'external_reference' => 'test-bonus-admin-' . Str::uuid(),
        'restrictions' => [['type' => 'NEGOCIO', 'target_id' => 'business-test']]];
}
function bonusAdminKey(): string { return 'test-bonus-admin-' . Str::uuid(); }
function bonusAdminEmit($test, User $recipient, ?array $payload = null): Bonus
{
    $r = $test->postJson('/finanzas/bonos/administracion/issue', $payload ?? bonusAdminPayload($recipient), ['Idempotency-Key' => bonusAdminKey()])->assertCreated();
    return Bonus::where('public_id', $r->json('data.bonus_id'))->firstOrFail();
}

test('bonus administration endpoints require authentication and dedicated permissions', function () {
    $this->postJson('/finanzas/bonos/administracion/issue', [])->assertUnauthorized();
    $user = bonusAdminUser(); $user->assignRole('admin');
    $this->actingAs($user)->get('/finanzas/bonos/administracion')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Financial/BonusAdministration')->where('permissions.issue', false)->where('permissions.view', false));
    $this->getJson('/finanzas/bonos/administracion/records')->assertForbidden();
    $this->postJson('/finanzas/bonos/administracion/issue', [])->assertForbidden();
    $this->postJson('/finanzas/bonos/administracion/' . Str::uuid() . '/cancel', [])->assertForbidden();
});

test('emission requires both web permission and authorization of the issuer', function () {
    $operator = bonusAdminUser(); $recipient = bonusAdminUser(); $payload = bonusAdminPayload($recipient);
    bonusAdminGrant(['bonus.issue'], false);
    $this->actingAs($operator)->postJson('/finanzas/bonos/administracion/issue', $payload, ['Idempotency-Key' => bonusAdminKey()])->assertForbidden();
    expect(Bonus::where('external_reference', $payload['external_reference'])->exists())->toBeFalse();
    expect(app(PendingBonusAuthorizationProvider::class)->canIssueBonus('USER', (string) $operator->getKey(), BonusType::BECA))->toBeFalse();
});

test('emission retries once and records trusted issuer actor reason restrictions and snapshot without wallet credit', function () {
    $operator = bonusAdminUser(); $recipient = bonusAdminUser(); bonusAdminGrant(['bonus.issue']);
    $payload = bonusAdminPayload($recipient); $payload['issuer_id'] = 'forged'; $payload['actor_id'] = 'forged'; $key = bonusAdminKey();
    $wallets = Wallet::orderBy('id')->get(['public_id', 'available_balance_cents', 'held_balance_cents'])->toArray();
    $first = $this->actingAs($operator)->postJson('/finanzas/bonos/administracion/issue', $payload, ['Idempotency-Key' => $key])->assertCreated();
    $id = $first->json('data.bonus_id');
    $this->postJson('/finanzas/bonos/administracion/issue', $payload, ['Idempotency-Key' => $key])->assertOk()
        ->assertJsonPath('data.bonus_id', $id)->assertJsonPath('replayed', true);
    $bonus = Bonus::where('public_id', $id)->firstOrFail();
    $operation = BonusAdministrativeOperation::where('idempotency_key', $key)->firstOrFail();
    expect($bonus->issuer_id)->toBe((string) $operator->getKey())->and($bonus->issuer_type)->toBe('USER')
        ->and($bonus->restrictions()->count())->toBe(1)->and($bonus->ledgerEntries()->count())->toBe(1)
        ->and($bonus->ledgerEntries()->first()->actor_id)->toBe('user:' . $operator->getKey())
        ->and($bonus->ledgerEntries()->first()->reason)->toBe($payload['reason'])
        ->and($operation->actor_id)->toBe('user:' . $operator->getKey())
        ->and($operation->before_data)->toBeNull()->and($operation->after_data['restrictions'])->toHaveCount(1)
        ->and(Wallet::orderBy('id')->get(['public_id', 'available_balance_cents', 'held_balance_cents'])->toArray())->toBe($wallets);
});

test('same emission key rejects a changed request and a different administrator', function () {
    $operator = bonusAdminUser(); $recipient = bonusAdminUser(); bonusAdminGrant(['bonus.issue']); $key = bonusAdminKey();
    $payload = bonusAdminPayload($recipient);
    $this->actingAs($operator)->postJson('/finanzas/bonos/administracion/issue', $payload, ['Idempotency-Key' => $key])->assertCreated();
    $changed = $payload; $changed['amount_cents'] = 20000;
    $this->postJson('/finanzas/bonos/administracion/issue', $changed, ['Idempotency-Key' => $key])->assertConflict();
    $this->actingAs(bonusAdminUser())->postJson('/finanzas/bonos/administracion/issue', $payload, ['Idempotency-Key' => $key])->assertConflict();
    expect(Bonus::where('external_reference', $payload['external_reference'])->count())->toBe(1);
});

test('emission validates recipients required restrictions and idempotency header', function () {
    $operator = bonusAdminUser(); $recipient = bonusAdminUser(); bonusAdminGrant(['bonus.issue']); $payload = bonusAdminPayload($recipient);
    $this->actingAs($operator)->postJson('/finanzas/bonos/administracion/issue', $payload)->assertUnprocessable();
    $bad = $payload; $bad['beneficiary_id'] = '000000000000000000000000';
    $this->postJson('/finanzas/bonos/administracion/issue', $bad, ['Idempotency-Key' => bonusAdminKey()])->assertConflict();
    $bad = $payload; $bad['type'] = 'CATEGORIA'; $bad['restrictions'] = [];
    $this->postJson('/finanzas/bonos/administracion/issue', $bad, ['Idempotency-Key' => bonusAdminKey()])->assertConflict();
    $bad = $payload; $bad['restrictions'][] = $bad['restrictions'][0];
    $this->postJson('/finanzas/bonos/administracion/issue', $bad, ['Idempotency-Key' => bonusAdminKey()])->assertConflict();
    $bad = $payload; $bad['reason'] = '   ';
    $this->postJson('/finanzas/bonos/administracion/issue', $bad, ['Idempotency-Key' => bonusAdminKey()])->assertUnprocessable();
    expect(Bonus::where('external_reference', $payload['external_reference'])->exists())->toBeFalse();
});

test('audit failure rolls back the whole emission and allows retry with the original key', function () {
    $operator = bonusAdminUser(); $recipient = bonusAdminUser(); bonusAdminGrant(['bonus.issue']);
    $payload = bonusAdminPayload($recipient); $key = bonusAdminKey();
    BonusAdministrativeOperation::creating(function () { throw new RuntimeException('test-bonus-admin-audit-failure'); });
    try { $this->actingAs($operator)->postJson('/finanzas/bonos/administracion/issue', $payload, ['Idempotency-Key' => $key])->assertStatus(500); }
    finally { BonusAdministrativeOperation::flushEventListeners(); }
    expect(Bonus::where('external_reference', $payload['external_reference'])->exists())->toBeFalse()
        ->and(BonusAdministrativeOperation::where('idempotency_key', $key)->exists())->toBeFalse();
    $this->postJson('/finanzas/bonos/administracion/issue', $payload, ['Idempotency-Key' => $key])->assertCreated();
});

test('an in progress emission does not create a second bonus', function () {
    $operator = bonusAdminUser(); $recipient = bonusAdminUser(); bonusAdminGrant(['bonus.issue']); $key = bonusAdminKey();
    $lockKey = 'bonus-admin:' . hash('sha256', $key); $lock = app(FinancialJobLock::class); $token = $lock->acquire($lockKey, 180);
    try { $this->actingAs($operator)->postJson('/finanzas/bonos/administracion/issue', bonusAdminPayload($recipient), ['Idempotency-Key' => $key])->assertConflict(); }
    finally { $lock->release($lockKey, $token); }
    expect(BonusAdministrativeOperation::where('idempotency_key', $key)->exists())->toBeFalse();
});

test('cancellation retries once preserves consumed amounts and records before and after without crediting wallet', function () {
    $operator = bonusAdminUser(); $recipient = bonusAdminUser(); bonusAdminGrant(['bonus.issue', 'bonus.cancel', 'bonus.view']);
    $this->actingAs($operator); $bonus = bonusAdminEmit($this, $recipient); $bonus->update(['remaining_amount_cents' => 3000]);
    $wallets = Wallet::orderBy('id')->get(['public_id', 'available_balance_cents', 'held_balance_cents'])->toArray();
    $key = bonusAdminKey(); $url = '/finanzas/bonos/administracion/' . $bonus->public_id . '/cancel';
    $this->postJson($url, ['reason' => 'Fin del beneficio'], ['Idempotency-Key' => $key])->assertCreated();
    $this->postJson($url, ['reason' => 'Fin del beneficio'], ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('replayed', true);
    $this->postJson($url, ['reason' => 'Otro motivo'], ['Idempotency-Key' => $key])->assertConflict();
    $entry = $bonus->ledgerEntries()->where('movement_type', 'CANCELACION')->firstOrFail();
    $operation = BonusAdministrativeOperation::where('idempotency_key', $key)->firstOrFail();
    expect($entry->amount_cents)->toBe(-3000)->and($entry->actor_id)->toBe('user:' . $operator->getKey())
        ->and($bonus->ledgerEntries()->where('movement_type', 'CANCELACION')->count())->toBe(1)
        ->and($operation->before_data['remaining_amount_cents'])->toBe(3000)
        ->and($operation->after_data['remaining_amount_cents'])->toBe(0)
        ->and($bonus->fresh()->status)->toBe(BonusStatus::CANCELADO)
        ->and(Wallet::orderBy('id')->get(['public_id', 'available_balance_cents', 'held_balance_cents'])->toArray())->toBe($wallets);
});

test('administrative view and cancellation remain scoped to the issuer unless explicit global grants exist', function () {
    $issuer = bonusAdminUser(); $recipient = bonusAdminUser(); bonusAdminGrant(['bonus.issue', 'bonus.view', 'bonus.cancel']);
    $this->actingAs($issuer); $bonus = bonusAdminEmit($this, $recipient);
    $outsider = bonusAdminUser(); $this->actingAs($outsider);
    $this->getJson('/finanzas/bonos/administracion/records')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/finanzas/bonos/administracion/' . $bonus->public_id . '/history')->assertNotFound();
    $this->postJson('/finanzas/bonos/administracion/' . $bonus->public_id . '/cancel', ['reason' => 'Sin ámbito'], ['Idempotency-Key' => bonusAdminKey()])->assertNotFound();
    bonusAdminGrant(['bonus.view', 'bonus.view.all', 'bonus.cancel']);
    $this->getJson('/finanzas/bonos/administracion/records')->assertOk()->assertJsonPath('data.0.can_cancel', false);
    $this->getJson('/finanzas/bonos/administracion/' . $bonus->public_id . '/history')->assertOk()->assertJsonCount(1, 'data');
    bonusAdminGrant(['bonus.cancel', 'bonus.cancel.all']);
    $this->postJson('/finanzas/bonos/administracion/' . $bonus->public_id . '/cancel', ['reason' => 'Autorización global'], ['Idempotency-Key' => bonusAdminKey()])->assertCreated();
});

test('cancel failure rolls back and a cancelled or exhausted bonus cannot be cancelled with a new key', function () {
    $operator = bonusAdminUser(); $recipient = bonusAdminUser(); bonusAdminGrant(['bonus.issue', 'bonus.cancel']);
    $this->actingAs($operator); $bonus = bonusAdminEmit($this, $recipient); $key = bonusAdminKey(); $url = '/finanzas/bonos/administracion/' . $bonus->public_id . '/cancel';
    BonusAdministrativeOperation::creating(function () { throw new RuntimeException('test-bonus-admin-cancel-failure'); });
    try { $this->postJson($url, ['reason' => 'Cancelar'], ['Idempotency-Key' => $key])->assertStatus(500); }
    finally { BonusAdministrativeOperation::flushEventListeners(); }
    expect($bonus->fresh()->status)->toBe(BonusStatus::ACTIVO)
        ->and($bonus->fresh()->remaining_amount_cents)->toBe(10000)
        ->and($bonus->ledgerEntries()->where('movement_type', 'CANCELACION')->count())->toBe(0);
    $this->postJson($url, ['reason' => 'Cancelar'], ['Idempotency-Key' => $key])->assertCreated();
    $this->postJson($url, ['reason' => 'Cancelar otra vez'], ['Idempotency-Key' => bonusAdminKey()])->assertConflict();
    $spent = bonusAdminEmit($this, $recipient); $spent->update(['status' => BonusStatus::AGOTADO, 'remaining_amount_cents' => 0]);
    $this->postJson('/finanzas/bonos/administracion/' . $spent->public_id . '/cancel', ['reason' => 'Agotado'], ['Idempotency-Key' => bonusAdminKey()])->assertConflict();
});

test('expired cancellation is rejected without an expiry mutation inside a failed administrative request', function () {
    $operator = bonusAdminUser(); $recipient = bonusAdminUser(); bonusAdminGrant(['bonus.issue', 'bonus.cancel']);
    $this->actingAs($operator); $bonus = bonusAdminEmit($this, $recipient); $bonus->update(['expires_at' => now()->subMinute()]);
    $before = $bonus->ledgerEntries()->count();
    $this->postJson('/finanzas/bonos/administracion/' . $bonus->public_id . '/cancel', ['reason' => 'Ya venció'], ['Idempotency-Key' => bonusAdminKey()])->assertConflict();
    expect($bonus->fresh()->status)->toBe(BonusStatus::ACTIVO)->and($bonus->ledgerEntries()->count())->toBe($before);
});

test('permissions are rechecked before replaying a previously successful issuance', function () {
    $operator = bonusAdminUser(); $recipient = bonusAdminUser(); bonusAdminGrant(['bonus.issue']);
    $payload = bonusAdminPayload($recipient); $key = bonusAdminKey();
    $this->actingAs($operator)->postJson('/finanzas/bonos/administracion/issue', $payload, ['Idempotency-Key' => $key])->assertCreated();
    bonusAdminGrant([]);
    $this->postJson('/finanzas/bonos/administracion/issue', $payload, ['Idempotency-Key' => $key])->assertForbidden();
});
