<?php

use App\Models\StudentServices\Calendars\CalendarBlock;
use App\Models\StudentServices\Reservations\Facility;
use App\Models\StudentServices\Reservations\Reservation;
use App\Models\User;
use App\Services\StudentServices\Calendars\BookableResources;
use App\Services\StudentServices\Calendars\BookingService;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useStudentServicesTestDatabase();
    $this->withoutVite();

    // Lunes 12 de octubre de 2026, 8:00 a.m. (hora del campus).
    $this->travelTo(Carbon::parse('2026-10-12 08:00'));

    $this->facility = Facility::create([
        'name' => 'Sala de estudio 3',
        'type' => 'Sala',
        'building' => 'Edificio B',
        'capacity' => 1,
        'cost_cents' => 0,
        'active' => true,
    ]);
});

function reserveFacility(User $user, Facility $facility, string $start, string $end, ?string $key = null)
{
    return test()->actingAs($user)->post('/servicios-estudiante/reservas', [
        'facility_id' => (string) $facility->id,
        'date' => '2026-10-12',
        'start_time' => $start,
        'end_time' => $end,
        'idempotency_key' => $key ?? (string) Str::uuid(),
    ]);
}

test('the facility page renders rules and availability data', function () {
    $this->actingAs(User::factory()->create())
        ->get('/servicios-estudiante/reservas')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('student-services/reservations/Index')
            ->has('facilities', 1)
            ->where('facilities.0.rules.open_time', '07:30')
            ->has('bookedRanges')
            ->has('blocks'));
});

test('a full facility puts the next request on the waitlist and cancelling promotes it', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    reserveFacility($first, $this->facility, '10:00', '11:00')->assertSessionHasNoErrors();
    reserveFacility($second, $this->facility, '10:00', '11:00')->assertSessionHasNoErrors();

    $confirmed = Reservation::where('student_id', (string) $first->id)->first();
    $waiting = Reservation::where('student_id', (string) $second->id)->first();

    expect($confirmed->status)->toBe('confirmed')
        ->and($waiting->status)->toBe('waitlisted');

    $this->actingAs($first)
        ->patch("/servicios-estudiante/reservas/{$confirmed->id}/cancelar")
        ->assertSessionHasNoErrors();

    expect($waiting->fresh()->status)->toBe('confirmed')
        ->and($waiting->fresh()->promoted_at)->not->toBeNull();
});

test('back to back reservations do not consume the same seat', function () {
    reserveFacility(User::factory()->create(), $this->facility, '09:00', '10:00');
    reserveFacility(User::factory()->create(), $this->facility, '10:00', '11:00');

    expect(Reservation::where('status', 'confirmed')->count())->toBe(2);
});

test('the same idempotency key never creates two reservations', function () {
    $user = User::factory()->create();

    reserveFacility($user, $this->facility, '10:00', '11:00', 'same-key');
    reserveFacility($user, $this->facility, '10:00', '11:00', 'same-key');

    expect(Reservation::count())->toBe(1);
});

test('rules are enforced when reserving', function (string $start, string $end, string $message) {
    reserveFacility(User::factory()->create(), $this->facility, $start, $end)
        ->assertSessionHasErrors(['facility_id' => $message]);

    expect(Reservation::count())->toBe(0);
})->with([
    'after closing' => ['18:00', '19:00', 'El horario debe estar dentro de 07:30 – 18:30.'],
    'longer than allowed' => ['09:00', '13:00', 'La duración máxima por reserva es de 180 minutos.'],
    'misaligned' => ['09:10', '10:10', 'Las reservas deben ajustarse a franjas de 30 minutos.'],
]);

test('a student cannot hold two overlapping reservations', function () {
    $user = User::factory()->create();
    $other = Facility::create(['name' => 'Laboratorio', 'type' => 'Lab', 'building' => 'C', 'capacity' => 5, 'cost_cents' => 0, 'active' => true]);

    reserveFacility($user, $this->facility, '10:00', '11:00')->assertSessionHasNoErrors();
    reserveFacility($user, $other, '10:30', '11:30')->assertSessionHasErrors('facility_id');

    expect(Reservation::count())->toBe(1);
});

test('students cannot cancel inside the minimum cancellation window', function () {
    $user = User::factory()->create();

    reserveFacility($user, $this->facility, '08:30', '09:30');
    $reservation = Reservation::first();

    $this->actingAs($user)
        ->patch("/servicios-estudiante/reservas/{$reservation->id}/cancelar")
        ->assertSessionHasErrors(['reservation' => 'Ya no es posible cancelar: el límite es 60 minutos antes del inicio.']);

    expect($reservation->fresh()->status)->toBe('confirmed');
});

test('a calendar block rejects new bookings and cancels the affected ones', function () {
    $user = User::factory()->create();
    reserveFacility($user, $this->facility, '12:00', '13:00');

    $this->actingAs($user)->post('/servicios-estudiante/calendarios-cupos/bloqueos', [
        'resource_type' => BookableResources::FACILITY,
        'resource_id' => (string) $this->facility->id,
        'date' => '2026-10-12',
        'start_time' => '12:00',
        'end_time' => '14:00',
        'reason' => 'Mantenimiento preventivo',
    ])->assertSessionHasNoErrors();

    expect(CalendarBlock::count())->toBe(1)
        ->and(Reservation::first()->status)->toBe('cancelled')
        ->and(Reservation::first()->cancellation_reason)->toBe('Bloqueo de calendario: Mantenimiento preventivo');

    reserveFacility(User::factory()->create(), $this->facility, '13:00', '13:30')
        ->assertSessionHasErrors('facility_id');
});

test('missed reservations become no-shows and repeated no-shows block new bookings', function () {
    $user = User::factory()->create();

    foreach (['08:30', '09:00', '09:30'] as $index => $start) {
        Reservation::create([
            'folio' => "RES-TEST-{$index}",
            'facility_id' => $this->facility->id,
            'student_id' => (string) $user->id,
            'start_at' => Carbon::parse("2026-10-12 {$start}"),
            'end_at' => Carbon::parse("2026-10-12 {$start}")->addMinutes(30),
            'status' => 'confirmed',
            'idempotency_key' => "seed-{$index}",
        ]);
    }

    $this->travelTo(Carbon::parse('2026-10-12 10:30'));

    app(BookingService::class)->refreshStatuses(BookableResources::FACILITY);

    expect(Reservation::where('status', 'no_show')->count())->toBe(3);

    reserveFacility($user, $this->facility, '11:00', '12:00')
        ->assertSessionHasErrors(['facility_id' => 'Tienes 3 inasistencias en los últimos 30 días; no puedes reservar por ahora.']);
});

test('administrators can update capacity and rules of a resource', function () {
    $this->actingAs(User::factory()->create())
        ->patch("/servicios-estudiante/calendarios-cupos/facility/{$this->facility->id}/reglas", [
            'capacity' => 4,
            'open_time' => '09:00',
            'close_time' => '17:00',
            'slot_minutes' => 60,
            'min_booking_minutes' => 60,
            'max_booking_minutes' => 120,
            'cancel_before_minutes' => 30,
            'no_show_tolerance_minutes' => 10,
            'max_active_per_student' => 2,
            'max_advance_days' => 14,
            'max_no_shows' => 2,
            'no_show_window_days' => 15,
            'operating_days' => [1, 2, 3, 4, 5],
        ])
        ->assertSessionHasNoErrors();

    expect($this->facility->fresh()->capacity)->toBe(4);

    reserveFacility(User::factory()->create(), $this->facility, '08:00', '09:00')
        ->assertSessionHasErrors(['facility_id' => 'El horario debe estar dentro de 09:00 – 17:00.']);
});

test('the waitlist can be promoted manually only when there is room', function () {
    reserveFacility(User::factory()->create(), $this->facility, '10:00', '11:00');
    reserveFacility(User::factory()->create(), $this->facility, '10:00', '11:00');

    $waiting = Reservation::where('status', 'waitlisted')->first();

    $this->actingAs(User::factory()->create())
        ->patch("/servicios-estudiante/calendarios-cupos/espera/facility/{$waiting->id}/promover")
        ->assertSessionHasErrors(['waitlist' => 'Aún no hay cupo para esa franja; la solicitud sigue en espera.']);

    $this->facility->update(['capacity' => 2]);

    $this->actingAs(User::factory()->create())
        ->patch("/servicios-estudiante/calendarios-cupos/espera/facility/{$waiting->id}/promover")
        ->assertSessionHasNoErrors();

    expect($waiting->fresh()->status)->toBe('confirmed');
});

test('the calendars panel lists resources of every registered type', function () {
    $this->actingAs(User::factory()->create())
        ->get('/servicios-estudiante/calendarios-cupos')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('student-services/availability/Index')
            ->has('resources', 1)
            ->where('resources.0.resource_type', 'facility')
            ->has('blocks')
            ->has('waitlist'));
});

// P-16: Servicios no cobra reservas (el contrato de cobros con Finanzas sólo cubre lockers e impresiones). Mientras eso no
// cambie, una reserva de una instalación con costo no puede anunciarse como confirmada ni como pagada.
function paidFacility(): Facility
{
    return Facility::create(['name' => 'Auditorio Principal', 'type' => 'Auditorio', 'building' => 'Edificio A',
        'capacity' => 5, 'cost_cents' => 15000, 'active' => true]);
}

test('a reservation of a facility with a cost is recorded with payment pending and is never announced as confirmed or paid', function () {
    $auditorium = paidFacility();
    $student = User::factory()->create();

    reserveFacility($student, $auditorium, '10:00', '11:00')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'pago pendiente')
            && str_contains($message, '$150.00 MXN')
            && str_contains($message, 'No se ha realizado ningún cobro')
            && ! str_contains($message, 'Reserva confirmada')
            && ! str_contains(mb_strtolower($message), 'pagada'));

    $reservation = Reservation::where('student_id', (string) $student->id)->firstOrFail();

    expect($reservation->status)->toBe('confirmed')   // sigue ocupando su lugar en el calendario
        ->and($reservation->cost_cents)->toBe(15000)
        ->and($reservation->payment_status)->toBe('pending');

    $this->actingAs($student)->get('/servicios-estudiante/reservas')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('myReservations.0.status', 'confirmed')
            ->where('myReservations.0.payment_status', 'pending')
            ->where('myReservations.0.cost_cents', 15000)
            ->where('myReservations.0.status_label', 'Apartada · pago pendiente'));
});

test('a free facility keeps the plain confirmation and needs no payment', function () {
    $student = User::factory()->create();

    reserveFacility($student, $this->facility, '10:00', '11:00')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Reserva confirmada.');

    $reservation = Reservation::where('student_id', (string) $student->id)->firstOrFail();

    expect($reservation->cost_cents)->toBe(0)
        ->and($reservation->payment_status)->toBe('not_required');

    $this->actingAs($student)->get('/servicios-estudiante/reservas')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('myReservations.0.payment_status', 'not_required')
            ->where('myReservations.0.status_label', 'Confirmada'));
});

test('a reservation made before the payment state existed is shown as pending when its facility has a cost', function () {
    $auditorium = paidFacility();
    $student = User::factory()->create();

    // Como las que ya existen en una instalación: sin costo ni estado de pago guardados.
    Reservation::create(['folio' => 'RES-ANTERIOR', 'facility_id' => (string) $auditorium->id, 'student_id' => (string) $student->id,
        'start_at' => Carbon::parse('2026-10-12 10:00'), 'end_at' => Carbon::parse('2026-10-12 11:00'), 'status' => 'confirmed',
        'idempotency_key' => (string) Str::uuid()]);

    $this->actingAs($student)->get('/servicios-estudiante/reservas')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('myReservations.0.payment_status', 'pending')
            ->where('myReservations.0.cost_cents', 15000)
            ->where('myReservations.0.status_label', 'Apartada · pago pendiente'));
});

test('the payment state of a reservation with a cost survives the waitlist, the same request repeated and the cancellation', function () {
    $auditorium = paidFacility();
    $auditorium->update(['capacity' => 1]);
    $first = User::factory()->create();
    $second = User::factory()->create();

    reserveFacility($first, $auditorium, '10:00', '11:00', 'clave-repetida-0001')->assertSessionHasNoErrors();
    reserveFacility($first, $auditorium, '10:00', '11:00', 'clave-repetida-0001')->assertSessionHasNoErrors();   // misma clave: misma reserva
    reserveFacility($second, $auditorium, '10:00', '11:00')
        ->assertSessionHas('success', 'Sin cupo por ahora: quedaste en lista de espera.');

    expect(Reservation::where('student_id', (string) $first->id)->count())->toBe(1);

    $waiting = Reservation::where('student_id', (string) $second->id)->firstOrFail();
    expect($waiting->status)->toBe('waitlisted')->and($waiting->payment_status)->toBe('pending');

    $this->actingAs($second)->get('/servicios-estudiante/reservas')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('myReservations.0.status_label', 'En espera'));

    $this->travelTo(Carbon::parse('2026-10-11 08:00'));   // un día antes: dentro del plazo de cancelación
    $own = Reservation::where('student_id', (string) $first->id)->firstOrFail();
    $this->actingAs($first)->patch('/servicios-estudiante/reservas/'.$own->id.'/cancelar')->assertSessionHasNoErrors();

    $this->actingAs($first)->get('/servicios-estudiante/reservas')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('history.0.status_label', 'Cancelada')
            ->where('history.0.payment_status', 'pending'));   // nunca hubo cobro: tampoco hay nada que devolver

    // Ninguna reserva figura como pagada: Servicios no tiene con qué cobrarlas.
    expect(Reservation::whereNotIn('payment_status', ['pending', 'not_required'])->count())->toBe(0);
});
