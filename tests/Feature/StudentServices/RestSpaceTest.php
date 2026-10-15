<?php

use App\Models\StudentServices\Calendars\ResourceCalendar;
use App\Models\StudentServices\RestSpaces\RestBooking;
use App\Models\StudentServices\RestSpaces\RestSpace;
use App\Models\User;
use App\Services\StudentServices\Calendars\BookableResources;
use App\Services\StudentServices\RestSpaces\RestBookingService;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useStudentServicesTestDatabase();
    $this->withoutVite();

    $this->travelTo(Carbon::parse('2026-10-12 09:00'));

    $this->space = RestSpace::create([
        'code' => 'ZD-CAP-001',
        'name' => 'Cápsula de descanso 1',
        'type' => 'capsule',
        'location' => 'Biblioteca',
        'capacity' => 1,
        'description' => 'Cápsula individual.',
        'status' => 'available',
        'active' => true,
    ]);

    ResourceCalendar::create([
        ...BookableResources::definition(BookableResources::REST_SPACE)['defaults'],
        'resource_type' => BookableResources::REST_SPACE,
        'resource_id' => (string) $this->space->id,
        'max_booking_minutes' => 45,
    ]);
});

function bookRestSpace(User $user, RestSpace $space, string $start, int $minutes)
{
    return test()->actingAs($user)->post('/servicios-estudiante/zonas-descanso/reservas', [
        'rest_space_id' => (string) $space->id,
        'date' => '2026-10-12',
        'start_time' => $start,
        'duration_minutes' => $minutes,
        'idempotency_key' => (string) Str::uuid(),
    ]);
}

test('the rest spaces page shows the catalog with rules and live status', function () {
    $this->actingAs(User::factory()->create())
        ->get('/servicios-estudiante/zonas-descanso')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('student-services/rest-spaces/Index')
            ->has('spaces', 1)
            ->where('spaces.0.status', 'available')
            ->where('spaces.0.rules.max_booking_minutes', 45)
            ->has('bookings', 0));
});

test('a student can book a rest space within its maximum time', function () {
    $user = User::factory()->create();

    bookRestSpace($user, $this->space, '10:00', 30)->assertSessionHasNoErrors();

    $booking = RestBooking::first();

    expect($booking->status)->toBe('confirmed')
        ->and($booking->folio)->toStartWith('ZD-')
        ->and($booking->end_at->format('H:i'))->toBe('10:30');
});

test('bookings longer than the space maximum are rejected', function () {
    bookRestSpace(User::factory()->create(), $this->space, '10:00', 60)
        ->assertSessionHasErrors(['booking' => 'La duración máxima por reserva es de 45 minutos.']);
});

test('a second student goes to the waitlist and a student can only hold one active booking', function () {
    $first = User::factory()->create();

    bookRestSpace($first, $this->space, '10:00', 30)->assertSessionHasNoErrors();
    bookRestSpace(User::factory()->create(), $this->space, '10:00', 30)->assertSessionHasNoErrors();

    expect(RestBooking::where('status', 'waitlisted')->count())->toBe(1);

    bookRestSpace($first, $this->space, '12:00', 15)
        ->assertSessionHasErrors(['booking' => 'Alcanzaste el límite de 1 reserva(s) activa(s) para este servicio.']);
});

test('putting a space under maintenance cancels its upcoming bookings', function () {
    bookRestSpace(User::factory()->create(), $this->space, '10:00', 30);

    $this->actingAs(User::factory()->create())
        ->patch("/servicios-estudiante/zonas-descanso/espacios/{$this->space->id}/mantenimiento")
        ->assertSessionHasNoErrors();

    expect(RestBooking::first()->status)->toBe('cancelled')
        ->and($this->space->fresh()->status)->toBe('maintenance');

    bookRestSpace(User::factory()->create(), $this->space, '11:00', 15)
        ->assertSessionHasErrors(['booking' => 'Este espacio no está disponible para reservar.']);
});

test('a space shows as occupied while its capacity is in use', function () {
    bookRestSpace(User::factory()->create(), $this->space, '09:00', 30)->assertSessionHasNoErrors();

    expect(app(RestBookingService::class)->liveStatus($this->space->fresh()))->toBe('occupied');
});

test('students can only cancel their own bookings', function () {
    bookRestSpace(User::factory()->create(), $this->space, '11:00', 30);

    $this->actingAs(User::factory()->create())
        ->patch('/servicios-estudiante/zonas-descanso/reservas/'.RestBooking::first()->id.'/cancelar')
        ->assertForbidden();
});

test('new rest spaces can be registered with a unique code', function () {
    $user = User::factory()->create();

    $payload = [
        'code' => 'zd-chr-009',
        'name' => 'Sillón 9',
        'type' => 'chair',
        'location' => 'Centro estudiantil',
        'capacity' => 1,
    ];

    $this->actingAs($user)->post('/servicios-estudiante/zonas-descanso/espacios', $payload)->assertSessionHasNoErrors();
    $this->actingAs($user)->post('/servicios-estudiante/zonas-descanso/espacios', $payload)
        ->assertSessionHasErrors(['space' => 'Ya existe un espacio con el código ZD-CHR-009.']);

    expect(RestSpace::where('code', 'ZD-CHR-009')->count())->toBe(1);
});
