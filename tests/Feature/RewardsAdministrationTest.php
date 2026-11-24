<?php

use App\Enums\StudentStatus;
use App\Http\Middleware\EnsureRequiredTwoFactorAuthentication;
use App\Http\Middleware\EnsureSessionIsActive;
use App\Http\Middleware\TrackDeviceSession;
use App\Models\PlatformModule\AuditLog;
use App\Models\PlatformModule\Catalog;
use App\Models\PlatformModule\ConfigurationVersion;
use App\Models\PlatformModule\FraudFlag;
use App\Models\PlatformModule\PointsAccount;
use App\Models\PlatformModule\PointsLedger;
use App\Models\PlatformModule\PointsPolicy;
use App\Models\PlatformModule\Redemption;
use App\Models\PlatformModule\Reward;
use App\Models\PlatformModule\Setting;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;

/*
 * Administración de Recompensas (E7 7.6–7.11) y cargos por contexto de servicio.
 * Un cargo administrativo de Recompensas no exige ser administrador global: Identidad (módulo 1.3) ya permite asignar
 * el rol `admin` en un contexto `service`, y E7 traduce esos contextos a sus cargos propios.
 */
beforeEach(function () {
    (require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php'))->up();
    $this->seed(RoleSeeder::class);
    foreach (glob(database_path('migrations/platform/*.php')) as $file) {
        (require $file)->up();
    }
    config(['platform.ingest.enabled' => false, 'platform.delivery.enabled' => false]);
    $this->withoutMiddleware([TrackDeviceSession::class, EnsureSessionIsActive::class, EnsureRequiredTwoFactorAuthentication::class]);
    $make = fn () => User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]);
    $this->global = $make();
    $this->global->assignRole(Role::ADMIN);
    $this->program = $make();
    $this->program->assignRole(Role::ADMIN, 'service', 'recompensas');
    $this->platform = $make();
    $this->platform->assignRole(Role::ADMIN, 'service', 'plataforma');
    $this->auditor = $make();
    $this->auditor->assignRole(Role::ADMIN, 'service', 'auditoria');
    $this->foreign = $make();
    $this->foreign->assignRole(Role::ADMIN, 'service', 'biblioteca');
    $this->student = $make();
    $this->student->assignRole(Role::ESTUDIANTE);
});

function rewardsStudentProfile(User $user): void
{
    StudentProfile::create(['user_id' => (string) $user->getKey(), 'enrollment_number' => 'T'.substr((string) $user->getKey(), -7),
        'academic_status' => StudentStatus::Active, 'current_semester' => 1]);
}

function rewardsBusinessWithRule($test, array $rule = []): void
{
    $test->actingAs($test->program);
    $test->postJson('/api/v1/recompensas/businesses', ['business_id' => 'BIZ-ADM', 'business_name' => 'Souvenirs de prueba', 'status' => 'active'])->assertCreated();
    $test->postJson('/api/v1/recompensas/rules', [...['business_id' => 'BIZ-ADM', 'name' => 'Un punto por cada 2', 'type' => 'amount_spent',
        'points_per_unit' => 1, 'unit_amount' => 2, 'status' => 'active', 'active_from' => now()->subDay()->toDateString()], ...$rule])->assertCreated();
}

function rewardsEarn($test, float $amount, string $reference)
{
    return $test->postJson('/api/v1/recompensas/points/earn', ['student_id' => (string) $test->student->getKey(), 'business_id' => 'BIZ-ADM',
        'sale_amount' => $amount, 'source_domain' => 'marketplace', 'source_reference' => $reference, 'idempotency_key' => 'earn-'.$reference]);
}

// ---------- Cargos por contexto de servicio ----------

test('service scoped assignments become rewards roles and never a global administrator', function (string $who, string $role, string $area) {
    $user = $this->{$who};
    $this->actingAs($user)->get('/recompensas')->assertRedirect('/recompensas/'.$area);
    $page = $this->get('/recompensas/'.$area)->assertOk();
    expect($page->viewData('page')['props']['platformActor']['role'])->toBe($role)
        ->and($page->viewData('page')['props']['platformActor']['area'])->toBe($area === 'alumno' ? 'student' : 'admin');
    if (! in_array($who, ['global'], true)) {
        expect($user->hasRole(Role::ADMIN))->toBeFalse();
    }
})->with([
    'global administrator' => ['global', 'platform_admin', 'admin'],
    'rewards program administrator' => ['program', 'admin', 'admin'],
    'platform administration of the module' => ['platform', 'platform_admin', 'admin'],
    'auditor' => ['auditor', 'auditor', 'admin'],
    'administrator of another service' => ['foreign', 'student', 'alumno'],
    'student' => ['student', 'student', 'alumno'],
]);

test('each rewards role is limited to its own permissions', function () {
    $reward = ['name' => 'Taza', 'points_required' => 10, 'stock' => 2, 'status' => 'active', 'valid_to' => now()->addMonth()->toDateString()];
    $this->actingAs($this->program)->postJson('/api/v1/recompensas/rewards', $reward)->assertCreated();
    $this->getJson('/api/v1/recompensas/points/ledger')->assertOk();

    // Auditoría: lee todo, no escribe nada.
    $this->actingAs($this->auditor)->getJson('/api/v1/recompensas/points/ledger')->assertOk();
    $this->getJson('/api/v1/recompensas/audit/logs')->assertOk()->assertJsonPath('total', AuditLog::count());
    $this->get('/recompensas/admin/auditoria')->assertOk();
    $this->get('/recompensas/admin/catalogo')->assertForbidden();
    $this->postJson('/api/v1/recompensas/rewards', $reward)->assertForbidden();
    $this->postJson('/api/v1/recompensas/governance/manual-adjustment', ['student_id' => (string) $this->student->getKey(), 'amount' => 5, 'reason' => 'Intento'])->assertForbidden();

    // Otro servicio y alumno: nada de administración.
    foreach ([$this->foreign, $this->student] as $user) {
        $this->actingAs($user)->get('/recompensas/admin')->assertForbidden();
        $this->postJson('/api/v1/recompensas/rewards', $reward)->assertForbidden();
        $this->getJson('/api/v1/recompensas/points/ledger')->assertForbidden();
    }

    // El cargo de Recompensas no abre la administración de Identidad.
    $this->actingAs($this->program)->post('/roles/assign', ['user_id' => (string) $this->student->getKey(), 'role_name' => 'admin'])->assertForbidden();
    expect($this->student->fresh()->hasRole(Role::ADMIN))->toBeFalse();
});

// ---------- 7.8 Auditoría ----------

test('the audit search filters by action, entity and correlation', function () {
    rewardsBusinessWithRule($this);
    $this->postJson('/api/v1/recompensas/rewards', ['name' => 'Termo', 'points_required' => 10, 'stock' => 1, 'status' => 'active'])->assertCreated();
    $all = $this->getJson('/api/v1/recompensas/audit/logs')->assertOk()->json('total');
    $rules = $this->getJson('/api/v1/recompensas/audit/logs?search=earning_rule')->assertOk();
    expect($all)->toBeGreaterThanOrEqual(3)->and($rules->json('total'))->toBe(1)->and($rules->json('data.0.action'))->toBe('earning_rule.created');
    $this->getJson('/api/v1/recompensas/audit/logs?search=reward.created')->assertJsonPath('total', 1);
    $this->getJson('/api/v1/recompensas/audit/logs?search=nada-que-coincida')->assertJsonPath('total', 0);
    $this->getJson('/api/v1/recompensas/audit/logs?search=.%2A')->assertJsonPath('total', 0);
});

test('audit and ledger can be exported as csv only by who may read them', function () {
    rewardsStudentProfile($this->student);
    rewardsBusinessWithRule($this);
    rewardsEarn($this, 40, 'venta-csv')->assertCreated();
    $csv = $this->get('/api/v1/recompensas/points/ledger/export')->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv')
        ->and($csv->streamedContent())->toContain('venta-csv')->toContain('earn')->toContain((string) $this->student->getKey());
    $audit = $this->actingAs($this->auditor)->get('/api/v1/recompensas/audit/logs/export?search=points')->assertOk()->streamedContent();
    expect($audit)->toContain('points.earned')->not->toContain('earning_rule.created');
    $this->actingAs($this->student)->get('/api/v1/recompensas/points/ledger/export')->assertForbidden();
    $this->get('/api/v1/recompensas/audit/logs/export')->assertForbidden();
});

// ---------- 7.6 Gobierno ----------

test('the governance summary reports real figures', function () {
    rewardsStudentProfile($this->student);
    rewardsBusinessWithRule($this, ['daily_cap' => 100, 'max_points_per_operation' => 50]);
    rewardsEarn($this, 40, 'venta-gob')->assertCreated();
    $this->postJson('/api/v1/recompensas/governance/policies', ['name' => 'Tope general', 'type' => 'limits', 'daily_cap_points' => 500, 'status' => 'active'])->assertCreated();
    FraudFlag::create(['student_id' => (string) $this->student->getKey(), 'reason' => 'Faltante en reverso', 'severity' => 'high', 'status' => 'open']);
    PointsAccount::where('student_id', (string) $this->student->getKey())->update(['balance' => 999]);

    $this->getJson('/api/v1/recompensas/governance/summary')->assertOk()->assertJson([
        'policies_total' => 1, 'policies_active' => 1, 'rules_with_daily_cap' => 1, 'rules_with_operation_cap' => 1,
        'fraud_open' => 1, 'fraud_total' => 1, 'accounts_total' => 1, 'accounts_out_of_balance' => 1,
        'effective_daily_cap_points' => 500, 'effective_max_redemptions_per_day' => null,
    ]);
    $this->actingAs($this->student)->getJson('/api/v1/recompensas/governance/summary')->assertForbidden();
});

test('an active policy caps the points a student earns per day', function () {
    rewardsStudentProfile($this->student);
    rewardsBusinessWithRule($this);
    $this->postJson('/api/v1/recompensas/governance/policies', ['name' => 'Tope diario', 'type' => 'limits', 'daily_cap_points' => 30, 'status' => 'active'])->assertCreated();
    rewardsEarn($this, 40, 'venta-a')->assertCreated()->assertJsonPath('ledger.amount', 20);
    rewardsEarn($this, 40, 'venta-b')->assertCreated()->assertJsonPath('ledger.amount', 10);
    rewardsEarn($this, 40, 'venta-c')->assertStatus(422);
    expect((int) PointsLedger::where('student_id', (string) $this->student->getKey())->sum('amount'))->toBe(30);

    $policy = PointsPolicy::first();
    $this->putJson('/api/v1/recompensas/governance/policies/'.$policy->getKey(), ['status' => 'inactive'])->assertOk();
    rewardsEarn($this, 40, 'venta-d')->assertCreated()->assertJsonPath('ledger.amount', 20);
});

test('an active policy limits the redemptions a student makes per day', function () {
    rewardsStudentProfile($this->student);
    rewardsBusinessWithRule($this);
    rewardsEarn($this, 200, 'venta-canjes')->assertCreated();
    $reward = $this->postJson('/api/v1/recompensas/rewards', ['name' => 'Calcomanía', 'points_required' => 5, 'stock' => 10, 'status' => 'active'])->assertCreated()->json('reward');
    $this->postJson('/api/v1/recompensas/governance/policies', ['name' => 'Un canje al día', 'type' => 'limits', 'max_redemptions_per_day' => 1, 'status' => 'active'])->assertCreated();
    $id = (string) ($reward['_id'] ?? $reward['id']);
    $this->actingAs($this->student)->postJson('/api/v1/recompensas/redemptions', ['reward_id' => $id])->assertCreated();
    $this->postJson('/api/v1/recompensas/redemptions', ['reward_id' => $id])->assertStatus(422)->assertJsonPath('error', fn ($e) => str_contains($e, 'canjes por día'));
    expect(Redemption::count())->toBe(1);
});

test('a manual adjustment is refused for an account that is not a student', function () {
    rewardsStudentProfile($this->student);
    $this->actingAs($this->program)->postJson('/api/v1/recompensas/governance/manual-adjustment',
        ['student_id' => '64b000000000000000000001', 'amount' => 50, 'reason' => 'Cuenta que no existe'])->assertStatus(422)->assertJsonValidationErrors('student_id');
    $this->postJson('/api/v1/recompensas/governance/manual-adjustment',
        ['student_id' => (string) $this->foreign->getKey(), 'amount' => 50, 'reason' => 'Cuenta sin perfil de alumno'])->assertStatus(422);
    expect(PointsLedger::count())->toBe(0)->and(PointsAccount::count())->toBe(0);
    $this->postJson('/api/v1/recompensas/governance/manual-adjustment',
        ['student_id' => (string) $this->student->getKey(), 'amount' => 50, 'reason' => 'Participación'])->assertCreated();
    expect((int) PointsLedger::sum('amount'))->toBe(50);
});

// ---------- 7.11 Configuraciones sensibles con aprobación ----------

test('a configuration version needs a second person with approval authority', function () {
    $this->actingAs($this->program);
    $version = $this->postJson('/api/v1/recompensas/settings/versions', ['config_key' => 'points.daily_cap', 'value' => 400])->assertCreated()->json('version');
    $url = '/api/v1/recompensas/settings/versions/'.($version['_id'] ?? $version['id']).'/transition';

    // Quien la redacta la envía a aprobación, pero no puede aprobarla.
    $this->postJson($url, ['target_status' => 'approved'])->assertForbidden();
    $this->postJson($url, ['target_status' => 'pending_approval'])->assertOk()->assertJsonPath('version.status', 'pending_approval');
    $this->postJson($url, ['target_status' => 'approved'])->assertForbidden();
    $this->actingAs($this->auditor)->postJson($url, ['target_status' => 'approved'])->assertForbidden();
    $this->actingAs($this->student)->postJson($url, ['target_status' => 'approved'])->assertForbidden();
    expect(ConfigurationVersion::first()->status)->toBe('pending_approval');

    $this->actingAs($this->platform)->postJson($url, ['target_status' => 'approved'])->assertOk()->assertJsonPath('version.status', 'approved');
    $this->postJson($url, ['target_status' => 'active'])->assertOk()->assertJsonPath('version.status', 'active');
    expect(Setting::where('key', 'points.daily_cap')->first()->value)->toBe(400)
        ->and(AuditLog::where('action', 'config_version.approved')->first()->actor_id)->toBe((string) $this->platform->getKey());

    // Tampoco quien aprueba puede aprobar lo que redactó él mismo.
    $own = $this->postJson('/api/v1/recompensas/settings/versions', ['config_key' => 'points.daily_cap', 'value' => 900])->assertCreated()->json('version');
    $ownUrl = '/api/v1/recompensas/settings/versions/'.($own['_id'] ?? $own['id']).'/transition';
    $this->postJson($ownUrl, ['target_status' => 'pending_approval'])->assertOk();
    $this->postJson($ownUrl, ['target_status' => 'approved'])->assertForbidden();
    $this->actingAs($this->global)->postJson($ownUrl, ['target_status' => 'rejected', 'rejected_reason' => 'Fuera de política'])->assertOk();
    expect(Setting::where('key', 'points.daily_cap')->first()->value)->toBe(400);
});

// ---------- 7.7 Catálogos ----------

test('catalogs are created with an audit entry and only by who manages them', function () {
    $payload = ['name' => 'Tipos de negocio', 'code' => 'BUSINESS_TYPES', 'items' => ['Estudiante', 'Asociación'], 'description' => 'Catálogo maestro'];
    $this->actingAs($this->auditor)->postJson('/api/v1/recompensas/catalogs', $payload)->assertForbidden();
    $this->actingAs($this->program)->postJson('/api/v1/recompensas/catalogs', $payload)->assertCreated();
    $this->postJson('/api/v1/recompensas/catalogs', $payload)->assertStatus(422);
    expect(Catalog::count())->toBe(1)->and(AuditLog::where('action', 'catalog.created')->count())->toBe(1);
    $this->getJson('/api/v1/recompensas/catalogs')->assertOk()->assertJsonPath('0.code', 'BUSINESS_TYPES');
});

// ---------- 7.9 Tablero ----------

test('the administration dashboard carries recent audit entries and monthly series from the ledger', function () {
    rewardsStudentProfile($this->student);
    rewardsBusinessWithRule($this);
    rewardsEarn($this, 40, 'venta-tablero')->assertCreated();
    $stats = $this->getJson('/api/v1/recompensas/dashboard/stats')->assertOk();
    $month = now()->format('Y-m');
    expect($stats->json('recent_audit_logs'))->not->toBeEmpty()
        ->and($stats->json('recent_audit_logs.0'))->toHaveKeys(['action', 'actor_role', 'entity_type', 'created_at'])
        ->and(collect($stats->json('chart.labels'))->last())->toBe($month)
        ->and(collect($stats->json('chart.issued'))->last())->toBe(20)
        ->and($stats->json('governance'))->toHaveKeys(['rules_with_limits', 'fraud_open', 'draft_campaigns', 'pending_configuration_versions']);
    $this->actingAs($this->auditor)->getJson('/api/v1/recompensas/dashboard/stats')->assertOk()->assertJsonPath('role', 'admin');
});

// ---------- 7.1 / 7.6 Reverso con faltante y saldo pendiente ----------

test('a reversal without enough points leaves a debt, raises an alert and later credits settle it first', function () {
    rewardsStudentProfile($this->student);
    rewardsBusinessWithRule($this);
    $earned = rewardsEarn($this, 200, 'venta-reverso')->assertCreated()->json('ledger');   // 100 puntos
    $reward = $this->postJson('/api/v1/recompensas/rewards', ['name' => 'Pin', 'points_required' => 40, 'stock' => 5, 'status' => 'active'])->assertCreated()->json('reward');
    $this->actingAs($this->student)->postJson('/api/v1/recompensas/redemptions', ['reward_id' => (string) ($reward['_id'] ?? $reward['id'])])->assertCreated();
    $id = (string) $this->student->getKey();

    // Se revierte la compra completa (100) cuando al alumno sólo le quedan 60: faltan 40.
    $this->actingAs($this->program)->postJson('/api/v1/recompensas/points/reverse', ['related_ledger_id' => (string) ($earned['_id'] ?? $earned['id']), 'reason' => 'Devolución de la compra'])
        ->assertOk()->assertJsonPath('shortfall', 40);
    $account = PointsAccount::where('student_id', $id)->first();
    expect((int) PointsLedger::where('student_id', $id)->sum('amount'))->toBe(-40)
        ->and((int) $account->balance)->toBe(0)->and((int) $account->pending_balance)->toBe(40)
        ->and(FraudFlag::where('student_id', $id)->where('status', 'open')->count())->toBe(1);
    $this->getJson('/api/v1/recompensas/governance/summary')->assertJson(['accounts_out_of_balance' => 0, 'accounts_with_pending_balance' => 1, 'pending_balance_total' => 40, 'fraud_open' => 1]);

    // Un abono posterior salda primero la deuda: de 70 puntos, 40 cubren el pendiente y 30 quedan disponibles.
    $this->postJson('/api/v1/recompensas/governance/manual-adjustment', ['student_id' => $id, 'amount' => 70, 'reason' => 'Regularización tras dictamen'])->assertCreated();
    $account->refresh();
    expect((int) PointsLedger::where('student_id', $id)->sum('amount'))->toBe(30)
        ->and((int) $account->balance)->toBe(30)->and((int) $account->pending_balance)->toBe(0);
    $this->getJson('/api/v1/recompensas/governance/summary')->assertJson(['accounts_out_of_balance' => 0, 'accounts_with_pending_balance' => 0]);

    // «Conciliar» deja saldo y pendiente como los dicta el ledger.
    PointsAccount::where('student_id', $id)->update(['balance' => 999, 'pending_balance' => 7]);
    $this->postJson('/api/v1/recompensas/points/reconcile/'.$id)->assertOk()->assertJsonPath('account.balance', 30)->assertJsonPath('account.pending_balance', 0);
});

// 7.8-b, 7.8-d y 7.10-f. Lo que la pantalla de auditoría consulta para el detalle de un registro: sus valores de antes y después,
// el dispositivo desde el que se hizo y la traza de la operación por su correlación. Sólo para quien puede leer la auditoría.
test('an audit record exposes its before and after values, its device and its correlation trace only to who may read the audit', function () {
    rewardsStudentProfile($this->student);
    rewardsBusinessWithRule($this);
    $reward = ['name' => 'Termo', 'points_required' => 10, 'stock' => 2, 'status' => 'active', 'valid_to' => now()->addMonth()->toDateString()];
    $id = $this->actingAs($this->program)->withHeader('User-Agent', 'NavegadorDePrueba/1.0')->postJson('/api/v1/recompensas/rewards', $reward)->assertCreated()->json('data._id')
        ?? Reward::where('name', 'Termo')->firstOrFail()->getKey();
    $this->withHeader('User-Agent', 'NavegadorDePrueba/1.0')->putJson('/api/v1/recompensas/rewards/'.$id, ['stock' => 7])->assertOk();
    rewardsEarn($this, 40, 'venta-traza')->assertCreated();
    $update = AuditLog::where('action', 'reward.updated')->where('entity_id', (string) $id)->firstOrFail();
    $earned = AuditLog::where('action', 'points.earned')->whereNotNull('correlation_id')->latest('_id')->firstOrFail();

    // Quien audita ve el detalle: antes, después y dispositivo.
    $this->actingAs($this->auditor)->getJson('/api/v1/recompensas/audit/logs/'.$update->getKey())->assertOk()
        ->assertJsonPath('action', 'reward.updated')->assertJsonPath('before.stock', 2)->assertJsonPath('after.stock', 7)
        ->assertJsonPath('device', 'NavegadorDePrueba/1.0');
    // Y la traza de una operación por su correlación: el registro de la correlación y sus registros de auditoría, en orden.
    $trace = $this->getJson('/api/v1/recompensas/audit/correlations/'.$earned->correlation_id)->assertOk()
        ->assertJsonPath('correlation.correlation_id', $earned->correlation_id);
    expect(collect($trace->json('logs'))->pluck('action')->all())->toContain('points.earned')
        ->and($this->getJson('/api/v1/recompensas/audit/logs/'.$earned->getKey())->assertOk()->json('correlation.correlation_id'))->toBe($earned->correlation_id);
    $this->getJson('/api/v1/recompensas/audit/correlations/no-existe')->assertNotFound();

    // Quien no puede leer la auditoría no ve ni el detalle ajeno ni la traza.
    $this->actingAs($this->student)->getJson('/api/v1/recompensas/audit/logs/'.$update->getKey())->assertForbidden();
    $this->getJson('/api/v1/recompensas/audit/correlations/'.$earned->correlation_id)->assertForbidden();
    $this->actingAs($this->foreign)->getJson('/api/v1/recompensas/audit/logs/'.$update->getKey())->assertForbidden();
});

// 7.4-e. El límite por alumno que el administrador fija en una recompensa (`limit_per_user`) se guardaba y nadie lo aplicaba.
// Cuenta los canjes vigentes del alumno para esa recompensa: uno cancelado deja de contar. No toca otras recompensas ni a otro alumno.
test('a reward limit per student stops further redemptions of that reward until one is cancelled', function () {
    rewardsStudentProfile($this->student);
    rewardsBusinessWithRule($this);
    rewardsEarn($this, 200, 'venta-limite')->assertCreated();
    $limited = $this->postJson('/api/v1/recompensas/rewards', ['name' => 'Gorra', 'points_required' => 5, 'stock' => 10, 'status' => 'active', 'limit_per_user' => 2])->assertCreated()->json('reward');
    $free = $this->postJson('/api/v1/recompensas/rewards', ['name' => 'Pluma', 'points_required' => 5, 'stock' => 10, 'status' => 'active'])->assertCreated()->json('reward');
    $id = (string) ($limited['_id'] ?? $limited['id']);
    $freeId = (string) ($free['_id'] ?? $free['id']);

    $this->actingAs($this->student);
    $first = $this->postJson('/api/v1/recompensas/redemptions', ['reward_id' => $id])->assertCreated()->json('redemption');
    $this->postJson('/api/v1/recompensas/redemptions', ['reward_id' => $id])->assertCreated();
    $this->postJson('/api/v1/recompensas/redemptions', ['reward_id' => $id])->assertStatus(422)
        ->assertJsonPath('error', fn ($e) => str_contains($e, 'límite de 2 canjes por alumno'));
    expect(Redemption::where('reward_id', $id)->count())->toBe(2);
    // Otra recompensa sin límite sigue disponible.
    $this->postJson('/api/v1/recompensas/redemptions', ['reward_id' => $freeId])->assertCreated();
    // Un canje cancelado deja de contar.
    $this->postJson('/api/v1/recompensas/redemptions/'.($first['_id'] ?? $first['id']).'/cancel', ['reason' => 'Ya no lo necesito'])->assertOk();
    $this->postJson('/api/v1/recompensas/redemptions', ['reward_id' => $id])->assertCreated();
    expect(Redemption::where('reward_id', $id)->whereNotIn('status', ['cancelled', 'failed'])->count())->toBe(2);
});
