<?php

use App\Models\StudentServices\Library\Book;
use App\Models\StudentServices\Library\BookCopy;
use App\Models\StudentServices\Library\LibraryFine;
use App\Models\StudentServices\Library\Loan;
use App\Models\StudentServices\Lockers\Locker;
use App\Models\StudentServices\Lockers\LockerAssignment;
use App\Models\StudentServices\Reservations\Facility;
use App\Models\StudentServices\Reservations\Reservation;
use App\Models\StudentServices\ServiceAccess\ServiceCheckin;
use App\Models\StudentServices\ServiceAccess\ServiceEvent;
use App\Models\StudentServices\Services\ServiceOrder;
use App\Models\User;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useStudentServicesTestDatabase();
    $this->withoutVite();

    $this->travelTo(Carbon::parse('2026-10-12 09:55'));

    $this->operator = User::factory()->create();
    $this->student = User::factory()->create();
});

function scan(array $data)
{
    return test()->actingAs(test()->operator)->post('/servicios-estudiante/validacion-servicios/validar', [
        'credential' => 'QR-'.test()->student->id,
        'method' => 'qr',
        ...$data,
    ]);
}

function facilityReservationFor(User $student): Reservation
{
    $facility = Facility::create(['name' => 'Sala 3', 'type' => 'Sala', 'building' => 'B', 'capacity' => 2, 'cost_cents' => 0, 'active' => true]);

    return Reservation::create([
        'folio' => 'RES-20261012-ABCDE',
        'facility_id' => $facility->id,
        'student_id' => (string) $student->id,
        'start_at' => Carbon::parse('2026-10-12 10:00'),
        'end_at' => Carbon::parse('2026-10-12 11:00'),
        'status' => 'confirmed',
        'idempotency_key' => 'seed',
    ]);
}

test('an unknown credential is denied and still recorded', function () {
    $this->actingAs($this->operator)->post('/servicios-estudiante/validacion-servicios/validar', [
        'credential' => 'NFC-DESCONOCIDA',
        'method' => 'nfc',
        'service' => 'facility',
        'action' => 'checkin',
    ])->assertSessionHas('access_result.granted', false);

    expect(ServiceCheckin::first()->message)->toBe('Credencial no reconocida.')
        ->and(ServiceCheckin::first()->credential_hint)->toBe('••••CIDA')
        ->and(ServiceEvent::count())->toBe(0);
});

test('a facility reservation can be checked in by folio and checked out', function () {
    $reservation = facilityReservationFor($this->student);

    scan(['service' => 'facility', 'action' => 'checkin', 'reference' => 'res-20261012-abcde'])
        ->assertSessionHas('access_result.granted', true);

    expect($reservation->fresh()->status)->toBe('checked_in');

    scan(['service' => 'facility', 'action' => 'checkout'])
        ->assertSessionHas('access_result.granted', true);

    expect($reservation->fresh()->status)->toBe('completed')
        ->and(ServiceEvent::pluck('event_type')->all())->toBe(['facility.checked_in', 'facility.checked_out']);
});

test('check-in without folio finds the current reservation of the student', function () {
    $reservation = facilityReservationFor($this->student);

    scan(['service' => 'facility', 'action' => 'checkin'])->assertSessionHas('access_result.granted', true);

    expect($reservation->fresh()->status)->toBe('checked_in');
});

test('another student cannot use someone else reservation', function () {
    facilityReservationFor(User::factory()->create());

    scan(['service' => 'facility', 'action' => 'checkin', 'reference' => 'RES-20261012-ABCDE'])
        ->assertSessionHas('access_result.granted', false)
        ->assertSessionHas('access_result.message', 'La reserva pertenece a otro estudiante.');
});

test('scanning a book copy registers a loan unless the student has pending fines', function () {
    $book = Book::create(['title' => 'Clean Code', 'status' => 'active']);
    $copy = BookCopy::create(['book_id' => $book->id, 'code' => 'EJ-001', 'status' => 'available']);

    LibraryFine::create([
        'student_id' => (string) $this->student->id,
        'loan_id' => '65f000000000000000000001',
        'type' => 'late',
        'amount_cents' => 5000,
        'reason' => 'Atraso',
        'status' => 'pending',
        'generated_at' => now(),
    ]);

    scan(['service' => 'library', 'action' => 'loan', 'reference' => 'ej-001'])
        ->assertSessionHas('access_result.granted', false);

    expect(Loan::count())->toBe(0);

    LibraryFine::query()->update(['status' => 'paid']);

    scan(['service' => 'library', 'action' => 'loan', 'reference' => 'EJ-001'])
        ->assertSessionHas('access_result.granted', true);

    expect(ServiceCheckin::where('granted', true)->first()->reference)->toBe('EJ-001')
        ->and(Loan::first()->student_id)->toBe((string) $this->student->id)
        ->and($copy->fresh()->status)->toBe('loaned');

    scan(['service' => 'library', 'action' => 'return', 'reference' => 'EJ-001'])
        ->assertSessionHas('access_result.granted', true);

    expect(Loan::first()->status)->toBe('returned');
});

test('locker access is only granted to the assigned student', function () {
    $locker = Locker::create(['code' => 'LKR-A-001', 'qr_code' => 'QR-LKR-A-001', 'building' => 'A', 'zone' => 'PB', 'size' => 'small', 'status' => 'occupied']);

    LockerAssignment::create([
        'folio' => 'ASG-1',
        'locker_id' => $locker->id,
        'student_id' => (string) User::factory()->create()->id,
        'status' => 'active',
        'ends_at' => now()->addMonth(),
    ]);

    scan(['service' => 'locker', 'action' => 'entry', 'reference' => 'LKR-A-001'])
        ->assertSessionHas('access_result.message', 'El locker está asignado a otro estudiante.');

    LockerAssignment::query()->update(['student_id' => (string) $this->student->id]);

    scan(['service' => 'locker', 'action' => 'entry', 'reference' => 'qr-lkr-a-001'])
        ->assertSessionHas('access_result.granted', true);
});

test('a ready print order is delivered when its owner shows the credential', function () {
    $order = ServiceOrder::create([
        'folio' => 'SER-2026-ABCD1234',
        'student_id' => (string) $this->student->id,
        'service_type' => 'print',
        'quantity' => 10,
        'status' => 'ready',
    ]);

    scan(['service' => 'service', 'action' => 'delivery', 'reference' => 'SER-2026-ABCD1234'])
        ->assertSessionHas('access_result.granted', true);

    expect($order->fresh()->status)->toBe('delivered');
});

test('an action that does not belong to the service is rejected by validation', function () {
    scan(['service' => 'locker', 'action' => 'loan', 'reference' => 'X'])->assertSessionHasErrors('action');

    expect(ServiceCheckin::count())->toBe(0);
});

test('the validation page lists recorded events and the last result', function () {
    scan(['service' => 'facility', 'action' => 'checkin']);

    $this->actingAs($this->operator)
        ->get('/servicios-estudiante/validacion-servicios')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('student-services/service-access/Index')
            ->has('events', 1)
            ->where('stats.denied', 1)
            ->has('operations.facility', 2));
});
