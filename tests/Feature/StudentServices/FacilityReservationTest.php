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
