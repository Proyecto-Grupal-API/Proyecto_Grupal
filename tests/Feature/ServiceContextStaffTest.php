<?php

use App\Http\Middleware\EnsureRequiredTwoFactorAuthentication;
use App\Http\Middleware\EnsureSessionIsActive;
use App\Http\Middleware\TrackDeviceSession;
use App\Models\Marketplace\BusinessApplication;
use App\Models\Role;
use App\Models\StudentServices\Lockers\Locker;
use App\Models\User;
use App\Support\Authorization\ServiceStaff;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Cargos de módulo sin administrador global. Identidad (diseño 1.3) ya permite asignar el rol `admin` en un contexto
 * `service`; Servicios al estudiante y la revisión institucional de Tienda reconocen ese cargo además del
 * administrador global, igual que Recompensas. El cargo de un servicio no abre otro servicio ni la administración
 * de Identidad.
 */
beforeEach(function () {
    (require database_path('migrations/2026_10_05_000100_create_authorization_foundation_indexes.php'))->up();
    $this->seed(RoleSeeder::class);
    $this->withoutVite();
    $this->withoutMiddleware([TrackDeviceSession::class, EnsureSessionIsActive::class, EnsureRequiredTwoFactorAuthentication::class]);
    $make = fn () => User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]);
    $this->global = $make();
    $this->global->assignRole(Role::ADMIN);
    $this->services = $make();
    $this->services->assignRole(Role::ADMIN, 'service', 'servicios-estudiante');
    $this->store = $make();
    $this->store->assignRole(Role::ADMIN, 'service', 'tienda');
    $this->rewards = $make();
    $this->rewards->assignRole(Role::ADMIN, 'service', 'recompensas');
    $this->student = $make();
    $this->student->assignRole(Role::ESTUDIANTE);
});

test('a service charge is recognised only for its own service and never as a global administrator', function () {
    expect(ServiceStaff::manages($this->global, 'servicios-estudiante'))->toBeTrue()
        ->and(ServiceStaff::manages($this->services, 'servicios-estudiante'))->toBeTrue()
        ->and(ServiceStaff::manages($this->services, 'tienda'))->toBeFalse()
        ->and(ServiceStaff::manages($this->store, 'tienda'))->toBeTrue()
        ->and(ServiceStaff::manages($this->store, 'servicios-estudiante'))->toBeFalse()
        ->and(ServiceStaff::manages($this->student, 'tienda'))->toBeFalse()
        ->and(ServiceStaff::manages(null, 'tienda'))->toBeFalse();
    foreach ([$this->services, $this->store] as $user) {
        expect($user->hasRole(Role::ADMIN))->toBeFalse();
    }
});

test('a revoked service charge stops being recognised', function () {
    $this->services->revokeRole(Role::ADMIN, 'service', 'servicios-estudiante');
    expect(ServiceStaff::manages($this->services->fresh(), 'servicios-estudiante'))->toBeFalse();
    $this->actingAs($this->services->fresh())->get('/servicios-estudiante/reportes')->assertForbidden();
});

// ---------- E5 Servicios al estudiante ----------

test('staff of student services by service context operates the staff pages', function () {
    $this->actingAs($this->services);
    $this->get('/servicios-estudiante/reportes')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('student-services/reports/Index')->where('auth.servicios_staff', true));
    $this->get('/servicios-estudiante/lockers/periodos')->assertOk();
    $this->get('/servicios-estudiante/validacion-servicios')->assertOk();
    $this->get('/servicios-estudiante/reportes/servicios.csv')->assertOk();
    // Una operación de personal: alta de locker.
    $this->post('/servicios-estudiante/lockers', ['code' => 'CTX-001', 'size' => 'small', 'building' => 'A', 'zone' => 'Planta baja'])->assertSessionHasNoErrors();
    expect(Locker::where('code', 'CTX-001')->exists())->toBeTrue();
});

test('the charge of another service and a student do not operate student services', function () {
    foreach ([$this->store, $this->rewards, $this->student] as $user) {
        $this->actingAs($user);
        $this->get('/servicios-estudiante/reportes')->assertForbidden();
        $this->get('/servicios-estudiante/lockers/periodos')->assertForbidden();
        $this->postJson('/servicios-estudiante/lockers', ['code' => 'CTX-002', 'size' => 'small', 'building' => 'A', 'zone' => 'Planta baja'])->assertForbidden();
        $this->get('/servicios-estudiante')->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.servicios_staff', false));
    }
    expect(Locker::where('code', 'CTX-002')->exists())->toBeFalse();
});

test('a service charge does not open the identity administration', function () {
    foreach ([$this->services, $this->store] as $user) {
        $this->actingAs($user);
        // «Roles» muestra a cada cuenta sus propios roles; asignar y ver a los demás es sólo del administrador global.
        $this->get('/roles')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canAssignRoles', false)->where('assignableUsers', []));
        $this->post('/roles/assign', ['user_id' => (string) $this->student->getKey(), 'role_name' => 'admin'])->assertForbidden();
        $this->get('/students')->assertForbidden();
    }
    expect($this->student->fresh()->hasRole(Role::ADMIN))->toBeFalse();
});

// ---------- E3 Tienda: revisión institucional ----------

test('the store reviewer by service context is recognised as the institutional review', function () {
    (require database_path('migrations/2026_10_14_000200_create_marketplace_indexes.php'))->up();
    $application = BusinessApplication::create(['applicant_id' => (string) $this->student->getKey(), 'business_name' => 'Papelería', 'type' => 'student',
        'description' => 'Solicitud de prueba', 'status' => 'pending']);
    $url = '/tienda/solicitudes/'.$application->getKey().'/resolver';

    foreach ([$this->services, $this->rewards, $this->student] as $user) {
        $this->actingAs($user)->postJson($url, ['decision' => 'observed', 'observations' => 'Falta descripción'])->assertForbidden();
    }
    expect($application->fresh()->status)->toBe('pending');

    $this->actingAs($this->store)->get('/tienda')->assertOk()->assertInertia(fn (Assert $page) => $page->where('isAdmin', true));
    $this->post($url, ['decision' => 'observed', 'observations' => 'Falta descripción'])->assertSessionHasNoErrors();
    expect($application->fresh()->status)->toBe('observed');
    $this->actingAs($this->student)->get('/tienda')->assertOk()->assertInertia(fn (Assert $page) => $page->where('isAdmin', false));

    // El administrador global conserva la revisión.
    $this->actingAs($this->global)->get('/tienda')->assertOk()->assertInertia(fn (Assert $page) => $page->where('isAdmin', true));
});

test('the store reviewer charge does not open student services nor rewards administration', function () {
    $this->actingAs($this->store);
    $this->get('/servicios-estudiante/reportes')->assertForbidden();
    $this->get('/recompensas/admin')->assertForbidden();
});

// ---------- E5: operaciones de atención que ahora tienen control en pantalla ----------

test('staff by service context attends a support ticket from open to closed and the student cannot', function () {
    $ticket = App\Models\StudentServices\Support\SupportTicket::create(['folio' => 'TIC-CTX-0001', 'student_id' => (string) $this->student->getKey(), 'category' => 'network',
        'subject' => 'Sin red', 'description' => 'Sin red en el laboratorio', 'priority' => 'medium', 'status' => 'open']);
    $base = '/servicios-estudiante/soporte/'.$ticket->getKey();

    $this->actingAs($this->student)->patchJson($base.'/asignar', ['assigned_to' => (string) $this->student->getKey()])->assertForbidden();
    $this->patchJson($base.'/estado', ['status' => 'resolved'])->assertForbidden();
    expect($ticket->fresh()->status)->toBe('open');

    $this->actingAs($this->services);
    $this->patch($base.'/asignar', ['assigned_to' => (string) $this->services->getKey()])->assertSessionHasNoErrors();
    expect($ticket->fresh()->status)->toBe('assigned')->and($ticket->fresh()->assigned_to)->toBe((string) $this->services->getKey());
    foreach (['in_progress', 'resolved', 'closed'] as $status) {
        $this->patch($base.'/estado', ['status' => $status, 'message' => 'Nota de '.$status])->assertSessionHasNoErrors();
        expect($ticket->fresh()->status)->toBe($status);
    }
    // Un ticket cerrado ya no se modifica.
    $this->from('/servicios-estudiante/soporte')->patch($base.'/estado', ['status' => 'resolved'])->assertSessionHasErrors();
    expect($ticket->fresh()->status)->toBe('closed');
});

test('staff by service context marks a paid order ready and delivers it and the student cannot', function () {
    $order = App\Models\StudentServices\Services\ServiceOrder::create(['folio' => 'SER-CTX-0001', 'student_id' => (string) $this->student->getKey(), 'service_type' => 'printing',
        'quantity' => 4, 'quoted_amount_cents' => 800, 'status' => 'processing', 'payment_status' => 'paid']);
    $base = '/servicios-estudiante/servicios-impresiones/'.$order->getKey();

    $this->actingAs($this->student)->patchJson($base.'/listo')->assertForbidden();
    $this->patchJson($base.'/entregar')->assertForbidden();
    expect($order->fresh()->status)->toBe('processing');

    $this->actingAs($this->services)->patch($base.'/listo')->assertSessionHasNoErrors();
    expect($order->fresh()->status)->toBe('ready')->and($order->fresh()->ready_at)->not->toBeNull();
    $this->patch($base.'/entregar')->assertSessionHasNoErrors();
    expect($order->fresh()->status)->toBe('delivered');
});
