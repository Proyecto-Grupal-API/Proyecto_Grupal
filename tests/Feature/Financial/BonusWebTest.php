<?php

use App\Domains\Financial\Enums\BonusMovementType;
use App\Domains\Financial\Enums\BonusStatus;
use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Models\Bonus;
use App\Domains\Financial\Models\BonusLedgerEntry;
use App\Domains\Financial\Models\BonusRestriction;
use App\Domains\Financial\Models\Wallet;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    bonusUiCleanup();
    $this->withoutVite();
});
afterEach(function () { bonusUiCleanup(); });

function bonusUiCleanup(): void
{
    if (config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing') {
        throw new RuntimeException('Fixtures de bonos: solo se permite la base financiera de pruebas.');
    }
    $ids = Bonus::where('external_reference', 'like', 'test-bonus-ui-%')->pluck('public_id');
    BonusLedgerEntry::whereIn('bonus_id', $ids)->delete();
    BonusRestriction::whereIn('bonus_id', $ids)->delete();
    Bonus::whereIn('public_id', $ids)->delete();
}
function bonusUiUser(): User
{
    $user = User::factory()->create();
    $user->forceFill(['email_verified_at' => now()])->save();
    return $user;
}
function bonusUiFixture(User $user, array $overrides = []): Bonus
{
    return Bonus::create(array_merge([
        'public_id' => (string) Str::uuid(), 'beneficiary_type' => 'STUDENT',
        'beneficiary_id' => (string) $user->getKey(), 'issuer_type' => 'SYSTEM', 'issuer_id' => 'test-bonus-ui-issuer',
        'type' => BonusType::BENEFICIO, 'original_amount_cents' => 5000, 'remaining_amount_cents' => 5000,
        'currency' => 'MXN', 'status' => BonusStatus::ACTIVO,
        'valid_from' => now()->subDay(), 'expires_at' => now()->addDay(),
        'combinable' => true, 'allows_partial_use' => true,
        'external_reference' => 'test-bonus-ui-' . Str::uuid(),
    ], $overrides));
}

test('bonus web endpoints require authentication', function () {
    $id = (string) Str::uuid();
    foreach (['/finanzas/bonos', "/finanzas/bonos/{$id}", "/finanzas/bonos/{$id}/history"] as $url) {
        $this->getJson($url)->assertUnauthorized();
    }
});

test('bonus screen lists only the authenticated beneficiary even with forged query identifiers', function () {
    $user = bonusUiUser(); $other = bonusUiUser();
    $student = bonusUiFixture($user);
    $account = bonusUiFixture($user, ['beneficiary_type' => 'USER']);
    bonusUiFixture($other);
    bonusUiFixture($user, ['beneficiary_type' => 'BUSINESS']);
    $this->actingAs($user)->get('/finanzas/bonos?beneficiary_id=' . $other->getKey())
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Financial/Bonuses')
            ->where('bonuses.total', 2)->has('bonuses.data', 2)
            ->where('bonuses.data.0.id', strtolower($account->public_id))
            ->where('bonuses.data.1.id', strtolower($student->public_id))
            ->where('balances.0.amount_cents', 10000));
});

test('another beneficiary detail and history are hidden even for a self assigned admin', function () {
    $owner = bonusUiUser(); $outsider = bonusUiUser(); $bonus = bonusUiFixture($owner);
    $outsider->assignRole('admin');
    $this->actingAs($outsider)->getJson('/finanzas/bonos/' . $bonus->public_id)->assertNotFound();
    $this->getJson('/finanzas/bonos/' . $bonus->public_id . '/history')->assertNotFound();
    $this->getJson('/finanzas/bonos/' . Str::uuid())->assertNotFound();
});

test('owner detail exposes conditions and restrictions without private issuer or actor fields', function () {
    $user = bonusUiUser(); $bonus = bonusUiFixture($user);
    BonusRestriction::create(['public_id' => (string) Str::uuid(), 'bonus_id' => $bonus->public_id,
        'restriction_type' => 'NEGOCIO', 'target_id' => 'test-bonus-ui-business']);
    $this->actingAs($user)->getJson('/finanzas/bonos/' . $bonus->public_id)->assertOk()
        ->assertJsonPath('data.id', strtolower($bonus->public_id))->assertJsonPath('data.combinable', true)
        ->assertJsonPath('data.allows_partial_use', true)->assertJsonPath('data.restrictions.0.type', 'NEGOCIO')
        ->assertJsonPath('data.restrictions.0.target_id', 'test-bonus-ui-business')
        ->assertJsonMissingPath('data.issuer_id')->assertJsonMissingPath('data.beneficiary_id');
});

test('bonus balances exclude future expired exhausted and cancelled bonuses and keep currencies separate', function () {
    $user = bonusUiUser();
    bonusUiFixture($user, ['remaining_amount_cents' => 1200]);
    bonusUiFixture($user, ['currency' => 'USD', 'remaining_amount_cents' => 300]);
    bonusUiFixture($user, ['valid_from' => now()->addHour(), 'status' => BonusStatus::PENDIENTE]);
    bonusUiFixture($user, ['expires_at' => now()->subSecond()]);
    bonusUiFixture($user, ['remaining_amount_cents' => 0, 'status' => BonusStatus::AGOTADO]);
    bonusUiFixture($user, ['status' => BonusStatus::CANCELADO]);
    $this->actingAs($user)->get('/finanzas/bonos')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('balances', 2)->where('balances', function ($balances) {
            return collect($balances)->pluck('amount_cents', 'currency')->all() === ['MXN' => 1200, 'USD' => 300]
                || collect($balances)->pluck('amount_cents', 'currency')->all() === ['USD' => 300, 'MXN' => 1200];
        }));
});

test('viewing an expired bonus derives its visible state without posting movements or changing wallet balances', function () {
    $user = bonusUiUser(); $bonus = bonusUiFixture($user, ['expires_at' => now()->subMinute()]);
    $wallets = Wallet::orderBy('id')->get(['public_id', 'available_balance_cents', 'held_balance_cents'])->toArray();
    $before = BonusLedgerEntry::count();
    $this->actingAs($user)->getJson('/finanzas/bonos/' . $bonus->public_id)->assertOk()
        ->assertJsonPath('data.status', 'EXPIRADO')->assertJsonPath('data.recorded_status', 'ACTIVO');
    expect($bonus->fresh()->status)->toBe(BonusStatus::ACTIVO)
        ->and($bonus->fresh()->remaining_amount_cents)->toBe(5000)
        ->and(BonusLedgerEntry::count())->toBe($before)
        ->and(Wallet::orderBy('id')->get(['public_id', 'available_balance_cents', 'held_balance_cents'])->toArray())->toBe($wallets);
});

test('bonus type filtering and pagination remain scoped to the owner', function () {
    $user = bonusUiUser();
    for ($i = 0; $i < 13; $i++) { bonusUiFixture($user, ['type' => BonusType::BECA]); }
    bonusUiFixture($user); bonusUiFixture(bonusUiUser(), ['type' => BonusType::BECA]);
    $this->actingAs($user)->get('/finanzas/bonos?type=BECA')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('bonuses.total', 13)->has('bonuses.data', 12)->where('bonuses.last_page', 2)->where('filters.type', 'BECA'));
    $this->get('/finanzas/bonos?type=BECA&page=2')->assertOk()->assertInertia(fn (Assert $page) => $page->has('bonuses.data', 1));
    $this->getJson('/finanzas/bonos?type=INVALID')->assertUnprocessable();
});

test('bonus history is paginated and cannot be switched by a forged beneficiary', function () {
    $user = bonusUiUser(); $other = bonusUiUser(); $bonus = bonusUiFixture($user);
    for ($i = 0; $i < 21; $i++) {
        BonusLedgerEntry::create(['public_id' => (string) Str::uuid(), 'bonus_id' => $bonus->public_id,
            'movement_type' => BonusMovementType::CONSUMO, 'amount_cents' => -10,
            'remaining_after_cents' => 5000 - (($i + 1) * 10), 'reference_type' => 'TEST_BONUS_UI',
            'reference_id' => 'test-bonus-ui-order', 'actor_id' => 'test-bonus-ui-actor', 'reason' => 'Compra de prueba']);
    }
    $this->actingAs($user)->getJson('/finanzas/bonos/' . $bonus->public_id . '/history?beneficiary_id=' . $other->getKey())
        ->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 21)
        ->assertJsonPath('data.0.movement_type', 'CONSUMO')->assertJsonPath('data.0.amount_cents', -10)
        ->assertJsonMissingPath('data.0.actor_id');
    $this->getJson('/finanzas/bonos/' . $bonus->public_id . '/history?page=2')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/finanzas/bonos/' . $bonus->public_id . '/history?page=0')->assertUnprocessable();
});
