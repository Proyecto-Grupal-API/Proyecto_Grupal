<?php

use App\Models\Role;
use App\Models\StudentServices\Library\LibraryFine;
use App\Models\StudentServices\Services\ServiceOrder;
use App\Models\StudentServices\Support\SupportTicket;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

// Rúbrica 10.1–10.5 para E5: panel con indicadores reales, reporte exportable, filtros validados y cifras iguales a la base.
beforeEach(function () {
    expect(\Illuminate\Support\Facades\DB::connection('mongodb')->getDatabaseName())->toBe('campus_virtual_testing');
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    $this->seed(RoleSeeder::class);
    $this->withoutVite();
    $this->withoutMiddleware([\App\Http\Middleware\TrackDeviceSession::class, \App\Http\Middleware\EnsureSessionIsActive::class, \App\Http\Middleware\EnsureRequiredTwoFactorAuthentication::class]);
    $this->travelTo(Carbon::parse('2026-10-20 12:00', config('app.timezone')));
    $this->staff = User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]);
    $this->staff->assignRole(Role::ADMIN);
    $this->student = User::factory()->create(['must_change_password' => false, 'account_activation_pending' => false]);
    $sid = (string) $this->student->id;
    foreach ([['pending', 2500], ['pending', 1500], ['paid', 4000]] as [$status, $cents]) {
        LibraryFine::create(['student_id' => $sid, 'type' => 'late', 'amount_cents' => $cents, 'status' => $status, 'reason' => 'Atraso']);
    }
    ServiceOrder::create(['folio' => 'SER-2026-REP00001', 'student_id' => $sid, 'service_type' => 'printing', 'quantity' => 10, 'quoted_amount_cents' => 2000, 'status' => 'ready']);
    SupportTicket::create(['student_id' => $sid, 'category' => 'network', 'subject' => 'Sin red', 'description' => 'Sin red en el laboratorio', 'priority' => 'high', 'status' => 'open']);
    $old = LibraryFine::create(['student_id' => $sid, 'type' => 'late', 'amount_cents' => 9900, 'status' => 'pending', 'reason' => 'Fuera de periodo']);
    $old->forceFill(['created_at' => now()->subDays(90)])->save();
});

test('the report page shows indicators computed from the database for the default 30 day period', function () {
    $this->actingAs($this->staff)->get('/servicios-estudiante/reportes')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('student-services/reports/Index')
            ->where('filters.desde', '2026-09-21')->where('filters.hasta', '2026-10-20')
            ->where('report.totales.registros', 5)
            ->where('report.servicios.1.clave', 'multas')->where('report.servicios.1.total', 3)
            ->where('report.servicios.1.importe_cents', 8000)
            ->where('report.servicios.1.por_estado.pending.registros', 2)->where('report.servicios.1.por_estado.pending.importe_cents', 4000)
            ->where('report.servicios.5.importe_cents', 2000)
            ->where('report.servicios.6.por_prioridad.high', 1));
    expect(LibraryFine::where('created_at', '>=', now()->subDays(29)->startOfDay())->sum('amount_cents'))->toBe(8000);
});

test('filters narrow the report by service and period and the CSV export uses the same criteria', function () {
    $this->actingAs($this->staff)->get('/servicios-estudiante/reportes?servicio=multas&desde=2026-07-01&hasta=2026-10-20')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('report.servicios', 1)->where('report.servicios.0.total', 4)->where('report.servicios.0.importe_cents', 17900));
    $csv = $this->get('/servicios-estudiante/reportes/servicios.csv?servicio=multas&desde=2026-07-01&hasta=2026-10-20')
        ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
    expect($csv)->toContain('desde,hasta,servicio,estado,registros,importe_cents')
        ->toContain('2026-07-01,2026-10-20,"Multas de biblioteca",pending,3,13900')
        ->toContain('2026-07-01,2026-10-20,"Multas de biblioteca",paid,1,4000');
});

test('invalid filters are rejected with clear messages', function () {
    $this->actingAs($this->staff);
    $this->get('/servicios-estudiante/reportes?desde=2026-10-20&hasta=2026-10-01')->assertSessionHasErrors(['hasta' => 'La fecha final debe ser igual o posterior a la inicial.']);
    $this->get('/servicios-estudiante/reportes?servicio=nominas')->assertSessionHasErrors('servicio');
    $this->get('/servicios-estudiante/reportes?desde=2024-01-01&hasta=2026-10-20')->assertSessionHasErrors(['desde' => 'El periodo no puede exceder un año.']);
});

test('students cannot open or export the services report', function () {
    $this->actingAs($this->student)->get('/servicios-estudiante/reportes')->assertForbidden();
    $this->actingAs($this->student)->get('/servicios-estudiante/reportes/servicios.csv')->assertForbidden();
});
