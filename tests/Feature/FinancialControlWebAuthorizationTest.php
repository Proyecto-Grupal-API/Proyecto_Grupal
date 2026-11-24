<?php

use App\Domains\Financial\Contracts\FinancialWebAuthorizer;
use App\Http\Middleware\EnsureRequiredTwoFactorAuthentication;
use App\Http\Middleware\EnsureSessionIsActive;
use App\Http\Middleware\TrackDeviceSession;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;

/*
 * Controles financieros en la interfaz web (límites, alertas y conciliaciones). La configuración de Finanzas describe
 * quién accede: `financial.access.view_roles` y `manage_roles`, nombres de rol de Identidad por instalación; vacío =
 * nadie; manage incluye view. El adaptador aplica exactamente eso y niega todo lo demás.
 */
beforeEach(function () {
    (require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php'))->up();
    $this->seed(RoleSeeder::class);
    $this->withoutMiddleware([TrackDeviceSession::class, EnsureSessionIsActive::class, EnsureRequiredTwoFactorAuthentication::class]);
    $make = fn () => User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]);
    $this->global = $make();
    $this->global->assignRole(Role::ADMIN);
    $this->manager = $make();
    $this->manager->assignRole(Role::ADMIN, 'service', 'finanzas');
    $this->viewer = $make();
    $this->viewer->assignRole(Role::ADMIN, 'service', 'finanzas-consulta');
    $this->other = $make();
    $this->other->assignRole(Role::ADMIN, 'service', 'tienda');
    $this->legacy = $make();
    $this->legacy->assignRole(Role::STUDENT_MANAGER);
    $this->student = $make();
    $this->student->assignRole(Role::ESTUDIANTE);
    $this->allows = fn (User $user, string $action, ?string $wallet = null) => app(FinancialWebAuthorizer::class)->allows((string) $user->getKey(), $action, $wallet);
});

const CONTROL_VIEW = ['limits.view', 'alerts.view', 'reconciliation.view'];
const CONTROL_MANAGE = ['limits.manage', 'limits.evaluate', 'alerts.review', 'reconciliation.run', 'reconciliation.resolve'];
const OUTSIDE_CONTROLS = ['transaction.reverse', 'refund.review', 'refund.execute', 'refund.recover', 'hold.release', 'hold.capture', 'policy.view', 'policy.manage',
    'adjustments.view', 'bonus.view', 'bonus.view.all', 'bonus.issue', 'bonus.cancel', 'bonus.cancel.all'];

test('with no roles declared nobody reaches the financial controls, not even the global administrator', function () {
    config(['financial.access.view_roles' => [], 'financial.access.manage_roles' => []]);
    foreach ([$this->global, $this->manager, $this->viewer, $this->student] as $user) {
        foreach ([...CONTROL_VIEW, ...CONTROL_MANAGE] as $action) {
            expect(($this->allows)($user, $action))->toBeFalse();
        }
    }
    $this->actingAs($this->global)->getJson('/finanzas/controles/limits')->assertForbidden();
});

test('declared roles open view or manage and manage includes view', function () {
    config(['financial.access.view_roles' => ['admin@service:finanzas-consulta'], 'financial.access.manage_roles' => ['admin@service:finanzas']]);
    foreach (CONTROL_VIEW as $action) {
        expect(($this->allows)($this->viewer, $action))->toBeTrue()->and(($this->allows)($this->manager, $action))->toBeTrue();
    }
    foreach (CONTROL_MANAGE as $action) {
        expect(($this->allows)($this->viewer, $action))->toBeFalse()->and(($this->allows)($this->manager, $action))->toBeTrue();
    }
    // Quien no tiene el rol declarado no entra: ni el administrador global, ni el cargo de otro servicio, ni un alumno.
    foreach ([$this->global, $this->other, $this->legacy, $this->student] as $user) {
        foreach ([...CONTROL_VIEW, ...CONTROL_MANAGE] as $action) {
            expect(($this->allows)($user, $action))->toBeFalse();
        }
    }
});

test('a global role of identity can be declared by its plain name', function () {
    config(['financial.access.view_roles' => ['student_manager'], 'financial.access.manage_roles' => ['admin']]);
    expect(($this->allows)($this->legacy, 'limits.view'))->toBeTrue()
        ->and(($this->allows)($this->legacy, 'limits.manage'))->toBeFalse()
        ->and(($this->allows)($this->global, 'reconciliation.run'))->toBeTrue()
        // Un rol contextual no vale por el global del mismo nombre.
        ->and(($this->allows)($this->manager, 'limits.view'))->toBeFalse();
});

test('actions outside limits, alerts and reconciliations stay denied whatever is declared', function () {
    config(['financial.access.view_roles' => ['admin@service:finanzas-consulta'], 'financial.access.manage_roles' => ['admin@service:finanzas', 'admin']]);
    foreach ([$this->manager, $this->global] as $user) {
        foreach (OUTSIDE_CONTROLS as $action) {
            expect(($this->allows)($user, $action))->toBeFalse();
        }
        // Tampoco concede nada acotado a un monedero: la configuración sólo describe controles institucionales.
        expect(($this->allows)($user, 'limits.view', '00000000-0000-4000-8000-000000000001'))->toBeFalse();
    }
});

test('malformed or unknown role declarations grant nothing', function () {
    config(['financial.access.view_roles' => ['', ' ', 'admin@', '@service:finanzas', 'admin@service', 'admin@service:', 'no_existe', 'admin@planeta:finanzas'], 'financial.access.manage_roles' => ['*']]);
    foreach ([$this->global, $this->manager, $this->viewer] as $user) {
        expect(($this->allows)($user, 'limits.view'))->toBeFalse()->and(($this->allows)($user, 'limits.manage'))->toBeFalse();
    }
    expect((app(FinancialWebAuthorizer::class))->allows('64b000000000000000000001', 'limits.view'))->toBeFalse();
});

test('the web routes of the controls follow the declared roles', function () {
    config(['financial.access.view_roles' => ['admin@service:finanzas-consulta'], 'financial.access.manage_roles' => ['admin@service:finanzas']]);
    $this->actingAs($this->viewer)->getJson('/finanzas/controles/limits')->assertOk();
    $this->getJson('/finanzas/controles/alerts')->assertOk();
    $this->getJson('/finanzas/controles/reconciliations')->assertOk();
    $this->postJson('/finanzas/controles/limits', [])->assertForbidden();
    $this->postJson('/finanzas/controles/reconciliations', [])->assertForbidden();
    // Quien administra pasa la autorización; la petición vacía la rechaza la validación, no el permiso.
    $this->actingAs($this->manager)->getJson('/finanzas/controles/limits')->assertOk();
    $this->postJson('/finanzas/controles/limits', [])->assertStatus(422);
    foreach ([$this->student, $this->other, $this->global] as $user) {
        $this->actingAs($user)->getJson('/finanzas/controles/limits')->assertForbidden();
        $this->postJson('/finanzas/controles/limits', [])->assertForbidden();
    }
});
