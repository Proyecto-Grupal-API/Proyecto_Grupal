<?php

use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\WalletService;
use App\Http\Middleware\EnsureRequiredTwoFactorAuthentication;
use App\Http\Middleware\EnsureSessionIsActive;
use App\Http\Middleware\TrackDeviceSession;
use App\Models\PlatformModule\AuditLog;
use App\Models\PlatformModule\EarningRule;
use App\Models\PlatformModule\PointsAccount;
use App\Models\PlatformModule\PointsLedger;
use App\Models\PlatformModule\Redemption;
use App\Models\PlatformModule\RedemptionEvent;
use App\Models\PlatformModule\Reward;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    (require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php'))->up();
    $this->seed(RoleSeeder::class);
    foreach (glob(database_path('migrations/platform/*.php')) as $file) {
        (require $file)->up();
    }
    config(['database.connections.sqlsrv.database' => 'campus_digital_financial_testing']);
    DB::purge('sqlsrv');
    if (! app()->environment('testing') || DB::connection('sqlsrv')->getDatabaseName() !== 'campus_digital_financial_testing') {
        throw new RuntimeException('Unsafe financial test target.');
    }
    DB::connection('sqlsrv')->beginTransaction();
    $this->withoutMiddleware([TrackDeviceSession::class, EnsureSessionIsActive::class, EnsureRequiredTwoFactorAuthentication::class]);
    $this->actor = User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]);
});
afterEach(function () {
    while (DB::connection('sqlsrv')->transactionLevel() > 0) {
        DB::connection('sqlsrv')->rollBack();
    }
});
it('registers the seven modules and preserves the E1 login', function () {
    foreach (['financial.dashboard', 'platform.home', 'equipo4.dashboard', 'modulo6.eventos'] as $name) {
        expect(Route::has($name))->toBeTrue();
    }
    $this->get('/finanzas')->assertRedirect('/login');
    $this->get('/recompensas')->assertRedirect('/login');
    $this->postJson('/api/v1/plataforma/eventos', [])->assertStatus(503);
    $this->postJson('/api/v1/recompensas/auth/switch-demo-user', ['role' => 'admin'])->assertNotFound();
});
it('renders the source finance screens against SQL and restricts wallets to their owner', function () {
    $this->actingAs($this->actor);
    $this->get('/finanzas')->assertOk()->assertInertia(fn (Assert $a) => $a->component('Financial/Dashboard')->where('wallet', null));
    $wallet = app(WalletService::class)->create('USER', (string) $this->actor->getKey(), WalletType::USUARIO);
    $this->get('/finanzas')->assertOk()->assertInertia(fn (Assert $a) => $a->where('wallet.id', $wallet->public_id)->where('wallet.available_balance_cents', 0));
    foreach (['/finanzas/bonos', '/finanzas/comprobantes', '/finanzas/compras', '/finanzas/transferencias', '/finanzas/caja', '/finanzas/controles', '/finanzas/bonos/administracion', '/finanzas/ajustes'] as $path) {
        $this->get($path)->assertOk();
    }
    $other = User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]);
    $this->actingAs($other)->get('/finanzas')->assertOk()->assertInertia(fn (Assert $a) => $a->where('wallet', null));
    expect(Wallet::where('owner_id', (string) $this->actor->getKey())->count())->toBe(1);
});
it('does not grant financial administrative powers to the platform admin', function () {
    $this->actor->assignRole(Role::ADMIN);
    $this->actingAs($this->actor)->postJson('/finanzas/bonos/administracion/issue', [])->assertForbidden();
    expect(config('financial_marketplace.enabled'))->toBeFalse();
    expect(config('financial_identity_authorization.enabled'))->toBeFalse();
});
it('uses E1 identity for own points and rejects demo or cross-student access', function () {
    $this->actingAs($this->actor);
    $this->get('/recompensas')->assertRedirect('/recompensas/alumno');
    $this->get('/recompensas/alumno')->assertOk()->assertInertia(fn (Assert $a) => $a->component('Platform/student/StudentDashboard')->where('platformActor.id', (string) $this->actor->getKey()));
    $this->getJson('/api/v1/recompensas/points/my-balance')->assertOk()->assertJsonPath('balance', 0)->assertJsonPath('student_id', (string) $this->actor->getKey());
    $this->getJson('/api/v1/recompensas/points/balance/someone-else')->assertForbidden();
    $this->get('/recompensas/admin')->assertForbidden();
    $this->postJson('/api/v1/recompensas/rewards', ['name' => 'Unauthorized'])->assertForbidden();
    expect(Reward::count())->toBe(0);
});
it('imports E7 administrative APIs under a global E1 admin and writes only E7 data', function () {
    $this->actor->assignRole(Role::ADMIN);
    $this->actingAs($this->actor);
    $this->get('/recompensas')->assertRedirect('/recompensas/admin');
    foreach (['/dashboard/stats', '/rewards', '/campaigns', '/businesses', '/rules', '/points/ledger', '/redemptions', '/governance/policies', '/governance/fraud-flags', '/audit/logs', '/analytics/overview', '/integrations/logs', '/integrations/webhooks', '/settings', '/catalogs'] as $path) {
        $this->getJson('/api/v1/recompensas'.$path)->assertOk();
    }
    $this->postJson('/api/v1/recompensas/rewards', ['name' => 'Recompensa de prueba', 'points_required' => 100, 'is_physical' => false, 'status' => 'active'])->assertCreated();
    expect(Reward::count())->toBe(1)->and(AuditLog::where('action', 'reward.created')->count())->toBe(1);
    expect((new Reward)->getConnectionName())->toBe('platform'); // Test aliases share only the guarded test database.
});
it('never converts a scoped admin or revoked assignment into global E7 authority', function () {
    $this->actor->assignRole(Role::ADMIN, 'business', 'only-business');
    $this->actingAs($this->actor);
    $this->get('/recompensas/admin')->assertForbidden();
    $this->postJson('/api/v1/recompensas/rewards', [])->assertForbidden();
});
it('keeps another students redemption timeline and audit details private', function () {
    $this->actingAs($this->actor);
    $rd = Redemption::create(['student_id' => 'other-student', 'reward_id' => 'reward-1', 'status' => 'reserved', 'redemption_code' => 'PRIVATE-RD', 'points_spent' => 1]);
    $log = AuditLog::create(['actor_id' => 'other-user', 'action' => 'private.action', 'entity_type' => 'record', 'entity_id' => 'record-1']);
    $this->getJson('/api/v1/recompensas/redemptions/'.$rd->getKey().'/events')->assertForbidden();
    $this->getJson('/api/v1/recompensas/audit/logs/'.$log->getKey())->assertForbidden();
});
it('keeps a redemption retry to one debit one reservation and one receipt', function () {
    $admin = User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]);
    $admin->assignRole(Role::ADMIN);
    $this->actingAs($admin);
    EarningRule::create(['business_id' => 'business-test', 'name' => 'Regla de pruebas', 'status' => 'active', 'active_from' => now()->subDay(), 'points_per_unit' => 1, 'unit_amount' => 1, 'multiplier' => 1]);
    $this->postJson('/api/v1/recompensas/points/earn', ['student_id' => (string) $this->actor->getKey(), 'business_id' => 'business-test', 'sale_amount' => 300, 'source_domain' => 'community', 'source_reference' => 'fixture-1', 'idempotency_key' => 'earn-fixture-1'])->assertCreated();
    $reward = Reward::create(['name' => 'Servicio de prueba', 'points_required' => 50, 'stock' => 5, 'reserved_stock' => 0, 'is_physical' => false, 'status' => 'active']);
    $this->actingAs($this->actor);
    $body = ['reward_id' => (string) $reward->getKey(), 'idempotency_key' => 'redeem-once'];
    $first = $this->postJson('/api/v1/recompensas/redemptions', $body)->assertCreated()->json('redemption.id');
    $again = $this->postJson('/api/v1/recompensas/redemptions', $body)->assertSuccessful()->json('redemption.id');
    expect($first)->toBeString()->and($again)->toBe($first)->and(Redemption::count())->toBe(1)->and($reward->fresh()->reserved_stock)->toBe(1);
    $this->getJson('/api/v1/recompensas/points/my-balance')->assertJsonPath('balance', 250);
});

it('rolls back the points and stock if writing the redemption timeline fails', function () {
    $id = (string) $this->actor->getKey();
    PointsLedger::create(['student_id' => $id, 'idempotency_key' => 'fixture-credit', 'type' => 'earn', 'amount' => 100, 'source_domain' => 'test', 'source_reference' => 'test']);
    PointsAccount::create(['student_id' => $id, 'balance' => 100, 'pending_balance' => 0, 'lifetime_earned' => 100, 'lifetime_redeemed' => 0]);
    $reward = Reward::create(['name' => 'Rollback fixture', 'points_required' => 50, 'stock' => 5, 'reserved_stock' => 0, 'is_physical' => false, 'status' => 'active']);
    $this->actingAs($this->actor);
    RedemptionEvent::creating(fn () => throw new RuntimeException('fixture timeline failure'));
    try {
        $this->postJson('/api/v1/recompensas/redemptions', ['reward_id' => (string) $reward->getKey(), 'idempotency_key' => 'rollback-once'])->assertStatus(500);
    } finally {
        RedemptionEvent::flushEventListeners();
    }
    expect(Redemption::count())->toBe(0)->and($reward->fresh()->reserved_stock)->toBe(0)->and(PointsAccount::first()->balance)->toBe(100);
    $this->getJson('/api/v1/recompensas/points/my-balance')->assertJsonPath('balance',100);
});
