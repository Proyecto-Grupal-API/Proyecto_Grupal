<?php
namespace Tests\Feature;

use App\Models\{AsignacionBeneficio, Mensaje, Role, ServiceClient, User};
use App\Models\StudentServices\Library\{Loan, LibraryFine};
use App\Models\StudentServices\Notifications\NotificationOutbox;
use App\Models\StudentServices\Services\ServiceOrder;
use App\Models\StudentServices\Support\{SupportTicket, TicketEvent};
use App\Models\StudentServices\Benefits\BenefitAssignment;
use App\Services\{ClienteModuloHttp, EntregaBeneficiosServicios, OAuthTokenService};
use App\Services\StudentServices\Benefits\{IdentityStudentDirectory, StudentDirectory};
use App\Services\StudentServices\Notifications\{NotificationPlanner, NotificationDelivery};
use App\Services\StudentServices\ServiceAccess\IdentityCredentialResolver;
use App\Services\StudentServices\Payments\{StudentPaymentGateway, PendingStudentPaymentGateway, PaymentException};
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\{Hash, Http};
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class IntegracionServiciosComunidadTest extends TestCase
{
    use RefreshMongoDatabase;
    private User $alumno;
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->alumno = User::factory()->create();
        foreach (['identidad', 'avisos', 'beneficios'] as $module) {
            config(['integracion_servicios.'.$module => ['url' => 'https://'.$module.'.test', 'client_id' => 'test-'.$module, 'client_secret' => 'test']]);
        }
        Http::preventStrayRequests();
        Http::fake(['*/api/oauth/token' => Http::response(['access_token' => 'test-http-token', 'expires_in' => 600]),
            'https://identidad.test/api/v1/students/*/status' => Http::response(['data' => ['user_id' => (string) $this->alumno->id, 'name' => 'Alumno', 'status' => 'active']])]);
    }

    public function test_directorio_consulta_identidad_por_http_y_no_infiere_estado_de_la_cuenta(): void
    {
        $dir = new IdentityStudentDirectory;
        $this->assertSame('active', $dir->status((string) $this->alumno->id));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/status') && $r->hasHeader('Authorization', 'Bearer test-http-token'));
        config(['integracion_servicios.identidad.url' => 'https://baja.test']);
        Http::fake(['https://baja.test/api/oauth/token' => Http::response(['access_token' => 'baja']), 'https://baja.test/api/v1/students/*/status' => Http::response(['data' => ['status' => 'suspended']])]);
        $this->assertSame('inactive', $dir->status((string) $this->alumno->id));
    }

    public function test_credenciales_qr_nfc_se_validan_con_sus_scopes_reales(): void
    {
        Http::fake(['https://identidad.test/api/v1/identity/*-validate' => Http::response(['ok' => true, 'identity' => ['user_id' => (string) $this->alumno->id, 'name' => 'Alumno']])]);
        $resolver = new IdentityCredentialResolver;
        foreach (['qr' => 'codigo-real', 'nfc' => 'ABCD1234'] as $method => $code) {
            $this->assertSame((string) $this->alumno->id, $resolver->resolve($code, $method)['student_id']);
            Http::assertSent(fn ($r) => str_ends_with($r->url(), '/'.$method.'-validate') && ($method === 'qr' ? $r['code'] : $r['credential_uid']) === $code);
            Http::assertSent(fn ($r) => str_ends_with($r->url(), '/oauth/token') && $r['scope'] === 'identity:'.$method.':validate');
        }
        $this->assertNull($resolver->resolve('email@campus.test', 'manual'));
    }

    public function test_beca_aprobada_se_entrega_por_api_y_reintentar_no_cambia_clave(): void
    {
        $a = AsignacionBeneficio::create(['estado' => 'pendiente_integracion', 'contrato' => ['equipo_destino' => 5, 'tipo' => 'impresiones',
            'clave_idempotencia' => 'beca:prueba', 'beneficiario_id' => (string) $this->alumno->id, 'cantidad' => 100]]);
        Http::fake(['https://beneficios.test/api/v1/servicios/asignaciones' => Http::response(['data' => ['asignacion_id' => 'beneficio-api-1', 'tipo' => 'impresiones', 'estado' => 'asignado']], 201)]);
        app(EntregaBeneficiosServicios::class)->entregar($a);
        $this->assertSame('asignada', $a->fresh()->estado);
        $this->assertSame('beneficio-api-1', $a->fresh()->recibo_servicios['asignacion_id']);
        app(EntregaBeneficiosServicios::class)->entregar($a->fresh());
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/asignaciones') && $r->hasHeader('Idempotency-Key', 'beca:prueba'));
        $this->assertSame(0, BenefitAssignment::count()); // Community only stores E5's receipt.
    }

    public function test_multa_crea_salida_durable_y_fallo_http_no_revierte_servicio(): void
    {
        Http::fake(['https://avisos.test/api/v1/comunidad/notificaciones' => Http::response([], 503)]);
        $fine = LibraryFine::create(['student_id' => (string) $this->alumno->id, 'loan_id' => (string) new \MongoDB\BSON\ObjectId, 'amount_cents' => 5000, 'reason' => 'Atraso', 'status' => 'pending']);
        $entry = NotificationOutbox::firstOrFail();
        app(NotificationDelivery::class)->deliver($entry);
        $this->assertSame('pending', $fine->fresh()->status);
        $this->assertSame('pending', $entry->fresh()->status);
        $this->assertSame(1, $entry->fresh()->attempts);
        $this->assertSame(0, Mensaje::count()); // E5 never writes the Community inbox.
        $originalKey = $entry->key;
        config(['integracion_servicios.avisos.url' => 'https://recuperada.test']);
        Http::fake(['https://recuperada.test/api/oauth/token' => Http::response(['access_token' => 'retry']),
            'https://recuperada.test/api/v1/comunidad/notificaciones' => Http::response(['notificacion_id' => 'mensaje-externo', 'estado' => 'entregada'], 201)]);
        app(NotificationDelivery::class)->deliver($entry->fresh());
        $this->assertSame('delivered', $entry->fresh()->status);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://recuperada.test/api/v1/comunidad') && $r->hasHeader('Idempotency-Key', $originalKey));
        app(NotificationPlanner::class)->scan();
        $this->assertSame(1, NotificationOutbox::count());
    }

    public function test_cancelacion_retira_aviso_por_api_incluso_con_respuesta_original_perdida(): void
    {
        $order = ServiceOrder::create(['student_id' => (string) $this->alumno->id, 'folio' => 'ORD-1', 'status' => 'ready', 'ready_at' => now()]);
        $entry = NotificationOutbox::firstOrFail();
        $order->update(['status' => 'cancelled']);
        Http::fake(['https://avisos.test/api/v1/comunidad/notificaciones?*' => Http::response(['notificacion_id' => 'aviso-recibido', 'estado' => 'entregada']),
            'https://avisos.test/api/v1/comunidad/notificaciones/aviso-recibido/retiro' => Http::response(['estado' => 'retirada'])]);
        app(NotificationDelivery::class)->deliver($entry->fresh());
        $this->assertSame('withdrawn', $entry->fresh()->status);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/aviso-recibido/retiro'));
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/notificaciones'));
    }

    public function test_planificador_produce_recordatorio_y_vencimiento_sin_duplicar(): void
    {
        $loan = Loan::create(['student_id' => (string) $this->alumno->id, 'copy_id' => (string) new \MongoDB\BSON\ObjectId, 'status' => 'active', 'due_at' => now()->addHours(12)]);
        $planner = app(NotificationPlanner::class);
        $planner->scan(); $planner->scan();
        $this->assertSame(1, NotificationOutbox::count());
        $this->assertSame('prestamo_por_vencer', NotificationOutbox::first()->payload['tipo']);
        $this->travel(13)->hours(); $planner->scan();
        $this->assertSame(2, NotificationOutbox::count());
        $this->assertTrue(NotificationOutbox::where('payload.tipo', 'prestamo_por_vencer')->first()->withdraw_pending);
        $this->assertSame('alta', NotificationOutbox::where('payload.tipo', 'prestamo_vencido')->first()->payload['importancia']);
    }

    public function test_comentario_del_personal_avisa_al_titular_y_no_a_quien_comenta(): void
    {
        $ticket = SupportTicket::create(['student_id' => (string) $this->alumno->id, 'folio' => 'SUP-1', 'status' => 'open']);
        TicketEvent::create(['ticket_id' => (string) $ticket->id, 'event_type' => 'comment', 'actor_id' => 'staff-1', 'message' => 'Ya lo revisamos']);
        $this->assertSame((string) $this->alumno->id, NotificationOutbox::firstOrFail()->payload['destinatario_id']);
        TicketEvent::create(['ticket_id' => (string) $ticket->id, 'event_type' => 'comment', 'actor_id' => (string) $this->alumno->id, 'message' => 'Gracias']);
        $this->assertSame(1, NotificationOutbox::count());
    }

    public function test_alumno_ve_sus_registros_y_no_puede_operar_catalogos_ni_ajenos(): void
    {
        $own = Loan::create(['student_id' => (string) $this->alumno->id, 'copy_id' => (string) new \MongoDB\BSON\ObjectId, 'status' => 'active', 'due_at' => now()->addDays(3)]);
        $other = Loan::create(['student_id' => 'otro-alumno', 'copy_id' => (string) new \MongoDB\BSON\ObjectId, 'status' => 'active', 'due_at' => now()->addDays(3)]);
        $this->actingAs($this->alumno)->get('/servicios-estudiante/biblioteca/prestamos')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('loans', 1)->where('loans.0.id', (string) $own->id));
        $this->patchJson('/servicios-estudiante/biblioteca/prestamos/'.$other->id.'/renovar', ['additional_days' => 3])->assertNotFound();
        $this->postJson('/servicios-estudiante/biblioteca/libros', ['title' => 'No autorizado'])->assertForbidden();
        $this->get('/servicios-estudiante/validacion-servicios')->assertForbidden();
    }

    public function test_administracion_usa_roles_vigentes_y_politica_2fa_del_equipo_1(): void
    {
        $admin = User::factory()->create(); $admin->assignRole(Role::ADMIN);
        $this->actingAs($admin)->get('/servicios-estudiante/validacion-servicios')->assertRedirectToRoute('two-factor.enrollment');
        $admin = withConfirmedTestTwoFactor($admin);
        $this->actingAs($admin)->get('/servicios-estudiante/validacion-servicios')->assertOk();
        $this->actingAs($this->alumno)->get('/servicios-estudiante/lockers/periodos')->assertForbidden();
    }

    public function test_pagos_no_se_simulan_mientras_falta_cartera(): void
    {
        $this->assertInstanceOf(PendingStudentPaymentGateway::class, app(StudentPaymentGateway::class));
        $order = ServiceOrder::create(['student_id' => (string) $this->alumno->id, 'status' => 'pending_payment', 'payment_status' => 'pending']);
        $this->actingAs($this->alumno)->patchJson('/servicios-estudiante/servicios-impresiones/'.$order->id.'/pagar', ['payment_reference_id' => 'inventado'])->assertServiceUnavailable();
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->expectException(PaymentException::class);
        app(StudentPaymentGateway::class)->hold((string) $this->alumno->id, 10000, 'Renta', 'renta:1', 'hold:1');
    }
    public function test_reserva_interpreta_la_hora_del_campus_y_guarda_el_instante_correcto(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-12T15:00:00Z'));
        $facility = \App\Models\StudentServices\Reservations\Facility::create(['name' => 'Sala local', 'type' => 'Sala', 'building' => 'A', 'capacity' => 2, 'cost_cents' => 0, 'active' => true]);
        $this->actingAs($this->alumno)->post('/servicios-estudiante/reservas', ['facility_id' => (string) $facility->id,
            'date' => '2026-10-12', 'start_time' => '10:00', 'end_time' => '11:00', 'idempotency_key' => 'horario:local'])
            ->assertSessionHasNoErrors();
        $booking = \App\Models\StudentServices\Reservations\Reservation::firstOrFail();
        $this->assertSame('2026-10-12T16:00:00+00:00', $booking->start_at->utc()->toIso8601String());
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_actualizar_multa_avisa_cada_cambio_real_sin_duplicar_por_otros_campos(): void
    {
        $fine = LibraryFine::create(['student_id' => (string) $this->alumno->id, 'loan_id' => (string) new \MongoDB\BSON\ObjectId, 'amount_cents' => 5000, 'reason' => 'Atraso', 'status' => 'pending']);
        $fine->update(['notes' => 'Revisión administrativa']);
        $this->assertSame(1, NotificationOutbox::count());
        $fine->update(['amount_cents' => 6000]);
        $fine->update(['amount_cents' => 5000]);
        $this->assertSame(3, NotificationOutbox::count());
        $this->assertSame(2, NotificationOutbox::where('withdraw_pending', true)->count());
    }

    public function test_solicitud_personal_no_se_crea_si_identidad_informa_baja(): void
    {
        config(['integracion_servicios.identidad.url' => 'https://inactiva.test']);
        Http::fake(['https://inactiva.test/api/oauth/token' => Http::response(['access_token' => 'inactiva']),
            'https://inactiva.test/api/v1/students/*/status' => Http::response(['data' => ['status' => 'suspended']])]);
        $this->actingAs($this->alumno)->postJson('/servicios-estudiante/biblioteca/reservas', ['student_id' => 'otro-usuario', 'book_id' => 'libro-prueba'])->assertUnprocessable();
        $this->assertSame(0, \App\Models\StudentServices\Library\BookReservation::count());
    }

    public function test_solicitud_del_personal_rechaza_identificador_malformado_antes_de_consultar_identidad(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::ADMIN);
        $admin = withConfirmedTestTwoFactor($admin);
        $this->actingAs($admin);
        foreach ([['otro-alumno'], 123] as $studentId) {
            $this->postJson('/servicios-estudiante/biblioteca/prestamos', ['student_id' => $studentId])
                ->assertUnprocessable()->assertJsonValidationErrors('student_id');
        }
        Http::assertNothingSent();
        $this->assertSame(0, Loan::count());
    }

    public function test_rutas_de_servicios_no_reemplazan_las_preferencias_de_identidad(): void
    {
        $this->assertSame('/student-services', route('student-services.index', absolute: false));
        $this->assertSame('/servicios-estudiante', rtrim(route('module5.index', absolute: false), '/'));
    }

}
