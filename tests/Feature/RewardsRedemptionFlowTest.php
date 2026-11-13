<?php

use App\Http\Middleware\EnsureRequiredTwoFactorAuthentication;
use App\Http\Middleware\EnsureSessionIsActive;
use App\Http\Middleware\TrackDeviceSession;
use App\Models\PlatformModule\PointsAccount;
use App\Models\PlatformModule\PointsLedger;
use App\Models\PlatformModule\Redemption;
use App\Models\PlatformModule\Reward;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;

// Diseño E7 7.1–7.5 y 7.9: negocio → regla → recompensa → acumulación → canje → validación → entrega / cancelación con reverso,
// con permisos por rol E1 y dashboard coherente con la base.
beforeEach(function () {
    (require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php'))->up();
    $this->seed(RoleSeeder::class);
    foreach (glob(database_path('migrations/platform/*.php')) as $file) {
        (require $file)->up();
    }
    config(['platform.ingest.enabled' => false, 'platform.delivery.enabled' => false]);
    $this->withoutMiddleware([TrackDeviceSession::class, EnsureSessionIsActive::class, EnsureRequiredTwoFactorAuthentication::class]);
    $this->admin = User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]);
    $this->admin->assignRole(Role::ADMIN);
    $this->student = User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]);
    $this->other = User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]);
});

function rewardsSetup($test): string
{
    $test->actingAs($test->admin);
    $test->postJson('/api/v1/recompensas/businesses', ['business_id' => 'BIZ-FLOW', 'business_name' => 'Cafetería de prueba', 'status' => 'active'])->assertCreated();
    $test->postJson('/api/v1/recompensas/rules', ['business_id' => 'BIZ-FLOW', 'name' => 'Un punto por cada 10', 'type' => 'amount_spent',
        'points_per_unit' => 1, 'unit_amount' => 10, 'status' => 'active', 'active_from' => now()->subDay()->toDateString()])->assertCreated();
    $reward = $test->postJson('/api/v1/recompensas/rewards', ['business_id' => 'BIZ-FLOW', 'name' => 'Café de prueba', 'points_required' => 5,
        'stock' => 3, 'status' => 'active', 'valid_to' => now()->addMonth()->toDateString()])->assertCreated()->json('reward');
    expect($reward['valid_to'])->not->toBeNull();
    $test->postJson('/api/v1/recompensas/points/earn', ['student_id' => (string) $test->student->getKey(), 'business_id' => 'BIZ-FLOW', 'sale_amount' => 120,
        'source_domain' => 'marketplace', 'source_reference' => 'venta-1', 'idempotency_key' => 'earn-venta-1'])->assertCreated();

    return (string) ($reward['_id'] ?? $reward['id']);
}

test('administrative writes are validated and denied to students', function () {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/recompensas/businesses', ['business_name' => ''])->assertUnprocessable()->assertJsonValidationErrors(['business_id', 'business_name']);
    $this->postJson('/api/v1/recompensas/rewards', ['name' => 'Sin puntos'])->assertUnprocessable()->assertJsonValidationErrors(['points_required']);
    $this->actingAs($this->student);
    $this->postJson('/api/v1/recompensas/rewards', ['name' => 'Intento', 'points_required' => 1])->assertForbidden();
    $this->postJson('/api/v1/recompensas/points/earn', ['student_id' => (string) $this->student->getKey(), 'business_id' => 'X', 'sale_amount' => 999,
        'source_domain' => 'x', 'source_reference' => 'y'])->assertForbidden();
    $this->getJson('/api/v1/recompensas/points/ledger')->assertForbidden();
    expect(Reward::count())->toBe(0)->and(PointsLedger::count())->toBe(0);
});

test('earning applies the business rule once per idempotency key', function () {
    rewardsSetup($this);
    $this->postJson('/api/v1/recompensas/points/earn', ['student_id' => (string) $this->student->getKey(), 'business_id' => 'BIZ-FLOW', 'sale_amount' => 120,
        'source_domain' => 'marketplace', 'source_reference' => 'venta-1', 'idempotency_key' => 'earn-venta-1'])->assertSuccessful();
    $this->actingAs($this->student)->getJson('/api/v1/recompensas/points/my-balance')->assertOk()->assertJsonPath('balance', 12);
    expect(PointsLedger::where('student_id', (string) $this->student->getKey())->count())->toBe(1);
});

test('a redemption is validated by code delivered once and kept private from other students', function () {
    $rewardId = rewardsSetup($this);
    $this->actingAs($this->student);
    $redemption = $this->postJson('/api/v1/recompensas/redemptions', ['reward_id' => $rewardId, 'idempotency_key' => 'canje-1'])->assertCreated()->json('redemption');
    $this->getJson('/api/v1/recompensas/points/my-balance')->assertJsonPath('balance', 7);
    $this->actingAs($this->other)->getJson('/api/v1/recompensas/redemptions/'.$redemption['id'])->assertForbidden();
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/recompensas/redemptions/validate-code', ['code' => 'NO-EXISTE'])->assertNotFound();
    $this->postJson('/api/v1/recompensas/redemptions/validate-code', ['code' => $redemption['redemption_code']])->assertOk();
    $this->postJson('/api/v1/recompensas/redemptions/'.$redemption['id'].'/deliver')->assertOk();
    $this->postJson('/api/v1/recompensas/redemptions/'.$redemption['id'].'/deliver')->assertUnprocessable();
    expect(Redemption::find($redemption['id'])->status)->toBe('delivered');
});

test('cancelling an own redemption reverses its points and the dashboard matches the database', function () {
    $rewardId = rewardsSetup($this);
    $this->actingAs($this->student);
    $id = $this->postJson('/api/v1/recompensas/redemptions', ['reward_id' => $rewardId, 'idempotency_key' => 'canje-2'])->assertCreated()->json('redemption.id');
    $this->postJson('/api/v1/recompensas/redemptions/'.$id.'/cancel', ['reason' => 'Ya no lo necesito'])->assertOk();
    $this->getJson('/api/v1/recompensas/points/my-balance')->assertJsonPath('balance', 12);
    $stats = $this->actingAs($this->admin)->getJson('/api/v1/recompensas/dashboard/stats')->assertOk()->json();
    expect($stats['total_points_in_circulation'])->toBe((int) PointsAccount::sum('balance'))
        ->and($stats['total_points_in_circulation'])->toBe((int) PointsLedger::sum('amount'))
        ->and($stats['active_businesses'])->toBe(1)
        ->and($stats['total_redemptions_delivered'])->toBe(0);
});

test('governance policies are validated and a manual adjustment moves points with an audit trail', function () {
    rewardsSetup($this);
    $this->postJson('/api/v1/recompensas/governance/policies', ['type' => 'expiration'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
    $this->postJson('/api/v1/recompensas/governance/policies', ['name' => 'Expiración anual', 'type' => 'expiration', 'expiration_days' => 365, 'status' => 'active'])->assertCreated();
    $this->postJson('/api/v1/recompensas/governance/manual-adjustment', ['student_id' => (string) $this->student->getKey(), 'amount' => 0, 'reason' => 'Nulo'])->assertUnprocessable();
    $this->postJson('/api/v1/recompensas/governance/manual-adjustment', ['student_id' => (string) $this->student->getKey(), 'amount' => 3,
        'reason' => 'Compensación por incidencia', 'idempotency_key' => 'ajuste-1'])->assertSuccessful();
    expect(\App\Models\PlatformModule\AuditLog::where('entity_type', '!=', null)->count())->toBeGreaterThan(0);
    $this->actingAs($this->student)->getJson('/api/v1/recompensas/points/my-balance')->assertJsonPath('balance', 15);
    $this->postJson('/api/v1/recompensas/governance/manual-adjustment', ['student_id' => (string) $this->student->getKey(), 'amount' => 100, 'reason' => 'x'])->assertForbidden();
});

test('configuration changes are versioned and an administrator cannot approve them alone', function () {
    $this->actingAs($this->admin);
    $id = $this->postJson('/api/v1/recompensas/settings/versions', ['config_key' => 'points.expiration_days', 'value' => 365])->assertSuccessful()->json('version._id')
        ?? \App\Models\PlatformModule\ConfigurationVersion::where('config_key', 'points.expiration_days')->value('_id');
    $this->postJson('/api/v1/recompensas/settings/versions/'.$id.'/transition', ['target_status' => 'active'])->assertStatus(403);
    expect(\App\Models\PlatformModule\ConfigurationVersion::find($id)->status)->toBe('draft');
});
