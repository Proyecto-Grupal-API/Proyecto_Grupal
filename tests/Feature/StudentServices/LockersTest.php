<?php

use App\Models\StudentServices\Audit\ServiceAuditLog;
use App\Models\StudentServices\Lockers\Locker;
use App\Models\StudentServices\Lockers\LockerAssignment;
use App\Models\StudentServices\Lockers\LockerPeriod;
use App\Models\StudentServices\Lockers\LockerRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useStudentServicesTestDatabase();
    $this->withoutVite();

    $this->user = User::factory()->create();
});

function openLockerPeriod(): LockerPeriod
{
    return LockerPeriod::create([
        'code' => '2026-B',
        'name' => 'Agosto - Diciembre 2026',
        'starts_at' => now()->subWeek(),
        'ends_at' => now()->addMonths(3),
        'prices_cents' => ['small' => 15050, 'medium' => 22000, 'large' => 30000],
        'status' => 'active',
    ]);
}

function freeLocker(string $code = 'L-001'): Locker
{
    return Locker::create(['code' => $code, 'qr_code' => "QR-{$code}", 'building' => 'A', 'zone' => '1', 'size' => 'small', 'status' => 'available']);
}

test('period prices typed in pesos are stored as integer cents', function () {
    $this->actingAs($this->user)->post('/servicios-estudiante/lockers/periodos', [
        'code' => '2027-A',
        'name' => 'Enero - Junio 2027',
        'starts_at' => now()->addMonths(3)->toDateString(),
        'ends_at' => now()->addMonths(8)->toDateString(),
        'prices' => ['small' => '150.5', 'medium' => '220', 'large' => '300.99'],
    ])->assertSessionHasNoErrors();

    $period = LockerPeriod::first();

    expect($period->prices_cents)->toBe(['small' => 15050, 'medium' => 22000, 'large' => 30099])
        ->and($period->toPayload()['prices'])->toBe(['small' => '150.50', 'medium' => '220.00', 'large' => '300.99']);
});

test('a paid request charges the period price in cents and gets a locker after payment', function () {
    $period = openLockerPeriod();
    $locker = freeLocker();

    $this->actingAs($this->user)->post('/servicios-estudiante/lockers/solicitudes', [
        'period_id' => (string) $period->id,
        'size' => 'small',
    ])->assertSessionHasNoErrors();

    $request = LockerRequest::first();

    expect($request->amount_cents)->toBe(15050)
        ->and($request->status)->toBe('pending');

    $this->actingAs($this->user)
        ->get('/servicios-estudiante/lockers/solicitudes')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('requests.0.amount', '150.50')
            ->where('requests.0.amount_cents', 15050));

    $this->actingAs($this->user)
        ->patch("/servicios-estudiante/lockers/solicitudes/{$request->id}/pagar", ['payment_reference' => 'PAY-L-1'])
        ->assertSessionHasNoErrors();

    expect(LockerAssignment::count())->toBe(1)
        ->and($locker->fresh()->status)->toBe('occupied');
});

test('a student cannot hold two requests in the same period and a payment reference is single use', function () {
    $period = openLockerPeriod();
    freeLocker('L-001');
    freeLocker('L-002');
    $other = User::factory()->create();

    $this->actingAs($this->user)->post('/servicios-estudiante/lockers/solicitudes', ['period_id' => (string) $period->id, 'size' => 'small']);
    $this->actingAs($this->user)
        ->post('/servicios-estudiante/lockers/solicitudes', ['period_id' => (string) $period->id, 'size' => 'small'])
        ->assertSessionHasErrors();

    $this->actingAs($other)->post('/servicios-estudiante/lockers/solicitudes', ['period_id' => (string) $period->id, 'size' => 'small']);

    [$first, $second] = LockerRequest::orderBy('created_at')->get()->all();

    $this->actingAs($this->user)->patch("/servicios-estudiante/lockers/solicitudes/{$first->id}/pagar", ['payment_reference' => 'PAY-SAME']);
    $this->actingAs($other)
        ->patch("/servicios-estudiante/lockers/solicitudes/{$second->id}/pagar", ['payment_reference' => 'PAY-SAME'])
        ->assertSessionHasErrors();

    expect(LockerRequest::count())->toBe(2)
        ->and($second->fresh()->status)->toBe('pending');
});

test('releasing an assignment frees the locker', function () {
    $period = openLockerPeriod();
    $locker = freeLocker();

    $this->actingAs($this->user)->post('/servicios-estudiante/lockers/solicitudes', ['period_id' => (string) $period->id, 'size' => 'small']);
    $request = LockerRequest::first();
    $this->actingAs($this->user)->patch("/servicios-estudiante/lockers/solicitudes/{$request->id}/pagar", ['payment_reference' => 'PAY-REL']);

    $assignment = LockerAssignment::first();

    $this->actingAs($this->user)
        ->patch("/servicios-estudiante/lockers/asignaciones/{$assignment->id}/liberar", ['reason' => 'Fin de semestre'])
        ->assertSessionHasNoErrors();

    expect($locker->fresh()->status)->toBe('available')
        ->and(ServiceAuditLog::where('action', 'locker.assignment.released')->value('reason'))->toBe('Fin de semestre');
});

test('the migration converts legacy peso amounts to cents', function () {
    $database = DB::connection('mongodb');
    $database->getCollection('locker_periods')->insertOne(['code' => 'OLD', 'prices' => ['small' => '150.00', 'medium' => 220.5]]);
    $database->getCollection('locker_periods')->insertOne(['code' => 'EDITED', 'prices' => ['small' => '150.00'], 'prices_cents' => ['small' => 17500]]);
    $database->getCollection('locker_requests')->insertOne(['folio' => 'OLD-1', 'amount' => '99.90']);

    $legacy = LockerPeriod::where('code', 'OLD')->first();
    expect($legacy->priceCentsFor('medium'))->toBe(22050);

    $previousDefault = config('database.default');
    config(['database.default' => 'mongodb']);
    (require database_path('migrations/2026_10_09_000000_convert_locker_amounts_to_cents.php'))->up();
    config(['database.default' => $previousDefault]);

    $period = $database->getCollection('locker_periods')->findOne(['code' => 'OLD']);
    $request = $database->getCollection('locker_requests')->findOne(['folio' => 'OLD-1']);

    expect((array) $period['prices_cents'])->toBe(['small' => 15000, 'medium' => 22050])
        ->and(isset($period['prices']))->toBeFalse()
        ->and($request['amount_cents'])->toBe(9990)
        ->and(isset($request['amount']))->toBeFalse();

    $edited = $database->getCollection('locker_periods')->findOne(['code' => 'EDITED']);

    expect((array) $edited['prices_cents'])->toBe(['small' => 17500])
        ->and(isset($edited['prices']))->toBeFalse();
});
