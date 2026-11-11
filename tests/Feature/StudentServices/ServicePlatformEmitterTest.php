<?php

use App\Models\PlatformModule\AnalyticsEvent;
use App\Models\StudentServices\Benefits\BenefitAssignment;
use App\Models\StudentServices\Library\Book;
use App\Models\StudentServices\Library\BookCopy;
use App\Models\StudentServices\Library\Loan;
use App\Models\StudentServices\Lockers\LockerAssignment;
use App\Models\StudentServices\Platform\ServicePlatformEvent;
use App\Models\StudentServices\Rentals\Asset;
use App\Models\StudentServices\Rentals\AssetCondition;
use App\Models\StudentServices\Rentals\Rental;
use App\Models\StudentServices\Reservations\Reservation;
use App\Models\StudentServices\ServiceAccess\ServiceCheckin;
use App\Models\User;
use App\Services\StudentServices\Library\LoanService;
use App\Services\StudentServices\Lockers\LockerAssignmentService;
use App\Services\StudentServices\Platform\ServicePlatformDelivery;
use App\Services\StudentServices\Platform\ServicePlatformEvents;
use App\Services\StudentServices\Platform\ServicePlatformObserver;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use MongoDB\BSON\ObjectId;

const E5_PLATFORM_SECRET = 'servicios-transporte-prueba-20261104';

beforeEach(function () {
    useStudentServicesTestDatabase();
    config([
        'platform.services_delivery.enabled' => true,
        'platform.services_delivery.analytics_enabled' => true,
        'platform.services_delivery.url' => 'http://app',
        'platform.ingest.secrets.services' => E5_PLATFORM_SECRET,
    ]);
    Http::preventStrayRequests();
});

/**
 * @return list<string>
 */
function e5PlatformEventNames(): array
{
    return ServicePlatformEvent::query()->orderBy('created_at')->pluck('event_name')->all();
}

function e5PlatformEvent(string $name): ServicePlatformEvent
{
    return ServicePlatformEvent::query()->where('event_name', $name)->firstOrFail();
}

function e5PlatformLoan(): Loan
{
    $book = Book::create(['title' => 'Cálculo', 'status' => 'active']);
    $copy = BookCopy::create(['book_id' => $book->id, 'code' => 'EJ-'.uniqid(), 'status' => 'available']);

    return app(LoanService::class)->createLoan($copy, 'student-1', 7);
}

test('a loan publishes its creation and an on-time return also publishes the rewardable action', function () {
    $loan = e5PlatformLoan();
    app(LoanService::class)->returnLoan($loan);

    expect(e5PlatformEventNames())->toBe([
        'services.loan.created.v1',
        'services.loan.returned.v1',
        'services.loan.returned_on_time.v1',
    ]);

    $created = e5PlatformEvent('services.loan.created.v1');
    expect($created->aggregate_id)->toBe((string) $loan->id)
        ->and($created->payload['student_id'])->toBe('student-1')
        ->and($created->payload['loan_id'])->toBe((string) $loan->id)
        ->and($created->status)->toBe(ServicePlatformEvent::PENDING)
        ->and(e5PlatformEvent('services.loan.returned.v1')->payload['on_time'])->toBeTrue();
});

test('a late return is published without the rewardable action', function () {
    $loan = e5PlatformLoan();
    $loan->update(['due_at' => now()->subDay()]);
    ServicePlatformEvent::query()->delete();

    app(LoanService::class)->returnLoan($loan->fresh());

    expect(e5PlatformEventNames())->toContain('services.loan.returned.v1')
        ->not->toContain('services.loan.returned_on_time.v1')
        ->and(e5PlatformEvent('services.loan.returned.v1')->payload['on_time'])->toBeFalse();
});

test('reservation check-in, promotion and no-show become dashboard events', function () {
    $facilityId = (string) new ObjectId;
    $booking = Reservation::create([
        'folio' => 'RES-1', 'facility_id' => $facilityId, 'student_id' => 'student-1',
        'start_at' => now()->addHour(), 'end_at' => now()->addHours(2), 'status' => 'waitlisted',
    ]);
    $booking->update(['status' => 'confirmed']);
    $booking->update(['status' => 'checked_in']);

    $missed = Reservation::create([
        'folio' => 'RES-2', 'facility_id' => $facilityId, 'student_id' => 'student-2',
        'start_at' => now()->addHour(), 'end_at' => now()->addHours(2), 'status' => 'confirmed',
    ]);
    $missed->update(['status' => 'no_show']);

    expect(e5PlatformEventNames())->toBe([
        'services.reservation.created.v1',
        'services.reservation.promoted.v1',
        'services.reservation.attended.v1',
        'services.reservation.created.v1',
        'services.reservation.no_show.v1',
    ]);
    expect(e5PlatformEvent('services.reservation.attended.v1')->payload)
        ->toMatchArray(['student_id' => 'student-1', 'resource_type' => 'facility', 'resource_id' => $facilityId]);
});

test('a rental returned on time in good condition is rewardable and a damaged one is not', function () {
    [$assetOne, $assetTwo] = [(string) new ObjectId, (string) new ObjectId];
    $good = Rental::create(['asset_id' => $assetOne, 'student_id' => 'student-1', 'requested_at' => now(), 'due_at' => now()->addDay(), 'status' => 'active']);
    AssetCondition::create(['rental_id' => $good->id, 'asset_id' => $assetOne, 'type' => 'return', 'condition' => 'good', 'recorded_at' => now()]);
    $good->update(['status' => 'returned', 'returned_at' => now()]);

    $damaged = Rental::create(['asset_id' => $assetTwo, 'student_id' => 'student-2', 'requested_at' => now(), 'due_at' => now()->addDay(), 'status' => 'active']);
    AssetCondition::create(['rental_id' => $damaged->id, 'asset_id' => $assetTwo, 'type' => 'return', 'condition' => 'damaged', 'recorded_at' => now()]);
    $damaged->update(['status' => 'returned', 'returned_at' => now(), 'damage_charge_cents' => 5000]);

    $ok = ServicePlatformEvent::query()->where('event_name', 'services.rental.returned_ok.v1')->get();
    expect($ok)->toHaveCount(1)
        ->and($ok->first()->aggregate_id)->toBe((string) $good->id)
        ->and(ServicePlatformEvent::query()->where('event_name', 'services.rental.returned.v1')->count())->toBe(2);
});

test('the real rental flow at the desk publishes the access and the rewardable return', function () {
    $this->withoutVite();
    $student = User::factory()->create();
    $operator = User::factory()->create();
    $asset = Asset::create([
        'inventory_item_id' => 'INV-E7-1', 'name' => 'Laptop', 'category' => 'Computadoras',
        'location' => 'Centro de préstamo', 'status' => 'available', 'deposit_cents' => 50000,
    ]);

    $this->actingAs($student)->post('/servicios-estudiante/renta-equipos', [
        'asset_id' => (string) $asset->id,
        'due_at' => now()->addDays(3)->toDateString(),
    ])->assertSessionHasNoErrors();

    $this->actingAs($operator)->post('/servicios-estudiante/validacion-servicios/validar', [
        'credential' => 'QR-'.$student->id, 'method' => 'qr', 'service' => 'rental',
        'action' => 'return', 'reference' => 'INV-E7-1', 'condition' => 'good',
    ])->assertSessionHas('access_result.granted', true);

    expect(e5PlatformEventNames())->toContain(
        'services.rental.created.v1',
        'services.access.validated.v1',
        'services.rental.returned.v1',
        'services.rental.returned_ok.v1',
    );
    expect(e5PlatformEvent('services.rental.returned_ok.v1')->payload['student_id'])->toBe((string) $student->id);
});

test('bulk locker releases, prints cancellations and denied accesses are still published', function () {
    $assignment = LockerAssignment::create([
        'folio' => 'LKR-ASG-1', 'locker_id' => (string) new ObjectId, 'period_id' => (string) new ObjectId, 'student_id' => 'student-1',
        'source' => 'paid', 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth(),
    ]);
    app(LockerAssignmentService::class)->release($assignment, 'Fin de uso');

    ServiceCheckin::create(['folio' => 'EVT-1', 'student_id' => null, 'method' => 'nfc', 'service' => 'lockers', 'action' => 'access', 'granted' => false, 'message' => 'Credencial no válida', 'scanned_at' => now()]);

    expect(e5PlatformEventNames())->toBe([
        'services.locker.assigned.v1',
        'services.locker.released.v1',
        'services.access.denied.v1',
    ]);
    expect(e5PlatformEvent('services.access.denied.v1')->payload['student_id'])->toBe('unidentified');

    $benefit = BenefitAssignment::create([
        'folio' => 'BEN-1', 'client_id' => 'equipo6-comunidad', 'benefit' => 'impresiones', 'student_id' => 'student-1',
        'quantity' => 100, 'consumed' => 0, 'remaining' => 100, 'status' => 'active',
        'cancellation' => ['released_quantity' => 100],
    ]);
    app(ServicePlatformObserver::class)->benefitCancelled($benefit);

    expect(e5PlatformEventNames())->toContain('services.benefit.assigned.v1', 'services.benefit.cancelled.v1')
        ->and(e5PlatformEvent('services.benefit.cancelled.v1')->payload['released_quantity'])->toBe(100);
});

test('payloads never carry personal data and the outbox never breaks the operation', function () {
    $event = app(ServicePlatformEvents::class)->emit('loan.created', 'loan-1', [
        'student_id' => 'student-1',
        'email' => 'alumno@campus.test',
        'detalle' => ['nombre' => 'Alumno', 'copy_id' => 'copy-1'],
    ]);

    expect($event->payload)->toBe(['student_id' => 'student-1', 'detalle' => ['copy_id' => 'copy-1']])
        ->and(app(ServicePlatformEvents::class)->emit('Bad Name', 'x', ['a' => 1]))->toBeNull();

    app()->instance(ServicePlatformEvents::class, new class extends ServicePlatformEvents
    {
        public function emit(string $entityAction, string $aggregateId, array $payload, ?CarbonInterface $occurredAt = null): ?ServicePlatformEvent
        {
            throw new RuntimeException('salida no disponible');
        }
    });

    $loan = e5PlatformLoan();

    expect($loan->exists)->toBeTrue()
        ->and(Loan::query()->count())->toBe(1);
});

test('delivery signs every request, keeps the same identity on retries and stops on acknowledgement', function () {
    app(ServicePlatformEvents::class)->emit('loan.created', 'loan-1', ['student_id' => 'student-1']);
    $event = ServicePlatformEvent::query()->firstOrFail();

    Http::fake(['http://app/*' => Http::sequence()
        ->push(['message' => 'error'], 503)
        ->push(['estado' => 'aceptado', 'event_id' => $event->event_id], 202)]);

    $first = app(ServicePlatformDelivery::class)->deliver();
    $event->refresh();

    expect($first)->toMatchArray(['sent' => 0, 'retry' => 1])
        ->and($event->status)->toBe(ServicePlatformEvent::PENDING)
        ->and($event->attempts)->toBe(1)
        ->and($event->last_error)->toBe('http_503')
        ->and($event->next_attempt_at->isFuture())->toBeTrue();

    // Antes de la espera no se reintenta.
    expect(app(ServicePlatformDelivery::class)->deliver()['retry'])->toBe(0);

    $this->travel(2)->minutes();
    expect(app(ServicePlatformDelivery::class)->deliver()['sent'])->toBe(1)
        ->and($event->fresh()->status)->toBe(ServicePlatformEvent::SENT)
        ->and(app(ServicePlatformDelivery::class)->deliver()['sent'])->toBe(0);

    $bodies = [];
    Http::assertSent(function (HttpRequest $request) use (&$bodies) {
        $time = $request->header('X-Platform-Timestamp')[0];
        $bodies[] = $request->body();

        return $request->url() === 'http://app/api/v1/plataforma/eventos'
            && $request->header('X-Platform-Domain')[0] === 'services'
            && hash_equals(
                hash_hmac('sha256', $time.'|POST|/api/v1/plataforma/eventos|'.hash('sha256', $request->body()), E5_PLATFORM_SECRET),
                $request->header('X-Platform-Signature')[0]
            );
    });
    Http::assertSentCount(2);
    expect(array_unique($bodies))->toHaveCount(1);
});

test('invalid content is rejected without retries and a wrong receipt is retried', function () {
    app(ServicePlatformEvents::class)->emit('loan.created', 'loan-1', ['student_id' => 'student-1']);
    app(ServicePlatformEvents::class)->emit('loan.created', 'loan-2', ['student_id' => 'student-2']);
    [$rejected, $unconfirmed] = ServicePlatformEvent::query()->orderBy('created_at')->get()->all();

    Http::fake(['http://app/*' => Http::sequence()
        ->push(['codigo' => 'sobre_invalido', 'mensaje' => 'x', 'correlacion_id' => 'c'], 422)
        ->push(['estado' => 'aceptado', 'event_id' => 'otro-id'], 202)
        ->push(['estado' => 'duplicado', 'event_id' => $unconfirmed->event_id], 200)]);

    expect(app(ServicePlatformDelivery::class)->deliver())->toMatchArray(['sent' => 0, 'retry' => 1, 'rejected' => 1])
        ->and($rejected->fresh()->status)->toBe(ServicePlatformEvent::REJECTED)
        ->and($rejected->fresh()->last_error)->toBe('http_422:sobre_invalido')
        ->and($unconfirmed->fresh()->status)->toBe(ServicePlatformEvent::PENDING)
        ->and($unconfirmed->fresh()->last_error)->toBe('invalid_receipt_202');

    $this->travel(2)->minutes();

    expect(app(ServicePlatformDelivery::class)->deliver()['sent'])->toBe(1)
        ->and($rejected->fresh()->status)->toBe(ServicePlatformEvent::REJECTED);
});

test('nothing is sent while disabled and a missing secret fails loudly', function () {
    app(ServicePlatformEvents::class)->emit('loan.created', 'loan-1', ['student_id' => 'student-1']);

    config(['platform.services_delivery.analytics_enabled' => false]);
    $this->artisan('servicios:publicar-eventos')->assertSuccessful();

    config(['platform.services_delivery.analytics_enabled' => true, 'platform.ingest.secrets.services' => 'corto']);
    $this->artisan('servicios:publicar-eventos')->assertFailed();

    Http::assertNothingSent();
    expect(ServicePlatformEvent::query()->firstOrFail()->attempts)->toBe(0);
});

test('turning on only the audit delivery does not start sending analytics events', function () {
    app(ServicePlatformEvents::class)->emit('loan.created', 'loan-1', ['student_id' => 'student-1']);

    // Un entorno que ya tenía la auditoría encendida y no conoce la bandera nueva.
    config(['platform.services_delivery' => ['enabled' => true, 'url' => 'http://app']]);

    expect(config('platform.services_delivery.analytics_enabled'))->toBeNull()
        ->and(app(ServicePlatformDelivery::class)->deliver())->toMatchArray(['enabled' => false, 'sent' => 0]);
    $this->artisan('servicios:publicar-eventos')->assertSuccessful();

    Http::assertNothingSent();
    expect(ServicePlatformEvent::query()->firstOrFail()->status)->toBe(ServicePlatformEvent::PENDING)
        ->and(ServicePlatformEvent::query()->firstOrFail()->attempts)->toBe(0);
});

test('the real receiver in this application accepts our events and recognises a lost response', function () {
    foreach (glob(database_path('migrations/platform/*.php')) as $file) {
        (require $file)->up();
    }
    config(['platform.ingest.enabled' => true]);

    // Reenvía las peticiones HTTP del emisor al receptor real de esta misma app.
    Http::fake(function (HttpRequest $request) {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        foreach (['X-Platform-Domain', 'X-Platform-Timestamp', 'X-Platform-Signature', 'X-Correlation-Id'] as $header) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $header))] = $request->header($header)[0] ?? '';
        }

        $response = $this->call('POST', parse_url($request->url(), PHP_URL_PATH), [], [], [], $server, $request->body());

        return Http::response($response->getContent(), $response->getStatusCode());
    });

    $loan = e5PlatformLoan();
    app(LoanService::class)->returnLoan($loan);

    // préstamo creado, devuelto y devuelto a tiempo
    expect(app(ServicePlatformDelivery::class)->deliver())->toMatchArray(['sent' => 3, 'retry' => 0, 'rejected' => 0]);

    $stored = AnalyticsEvent::query()->where('event_name', 'services.loan.created.v1')->firstOrFail();
    expect($stored->source_domain)->toBe('services')
        ->and($stored->aggregate_id)->toBe((string) $loan->id)
        ->and(AnalyticsEvent::query()->where('event_name', 'services.loan.returned_on_time.v1')->exists())->toBeTrue();

    // Respuesta perdida: el mismo sobre vuelve a salir y el receptor lo reconoce como duplicado.
    ServicePlatformEvent::query()->update(['status' => ServicePlatformEvent::PENDING, 'next_attempt_at' => now()->subMinute()]);

    expect(app(ServicePlatformDelivery::class)->deliver())->toMatchArray(['sent' => 3, 'rejected' => 0])
        ->and(AnalyticsEvent::query()->count())->toBe(3);
});
