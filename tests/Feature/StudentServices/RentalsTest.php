<?php

use App\Models\StudentServices\Audit\ServiceAuditLog;
use App\Models\StudentServices\Payments\ServiceCharge;
use App\Models\StudentServices\Rentals\Asset;
use App\Models\StudentServices\Rentals\AssetCondition;
use App\Models\StudentServices\Rentals\Rental;
use App\Models\User;
use App\Services\StudentServices\Payments\PaymentException;
use App\Services\StudentServices\Payments\StudentPaymentGateway;
use App\Services\StudentServices\Rentals\RentalService;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useStudentServicesTestDatabase();
    $this->withoutVite();

    $this->user = User::factory()->create();
    $this->operator = User::factory()->create();
    $this->asset = Asset::create([
        'inventory_item_id' => 'INV-T-1',
        'name' => 'Laptop de prueba',
        'category' => 'Computadoras',
        'location' => 'Centro de préstamo',
        'status' => 'available',
        'description' => 'Equipo de prueba',
        'deposit_cents' => 50000,
    ]);
});

function rentAsset(object $test): Rental
{
    $test->actingAs($test->user)->post('/servicios-estudiante/renta-equipos', [
        'asset_id' => (string) $test->asset->id,
        'due_at' => now()->addDays(3)->toDateString(),
    ])->assertSessionHasNoErrors();

    return Rental::where('status', 'active')->firstOrFail();
}

/**
 * Operación en el mostrador (validación de servicios 5.11).
 */
function deskRental(object $test, string $action, array $data = []): TestResponse
{
    return $test->actingAs($test->operator)->post('/servicios-estudiante/validacion-servicios/validar', [
        'credential' => 'QR-'.$test->user->id,
        'method' => 'qr',
        'service' => 'rental',
        'action' => $action,
        'reference' => 'INV-T-1',
        ...$data,
    ]);
}

test('renting holds the asset deposit and shows it on the page', function () {
    $rental = rentAsset($this);

    expect($rental->deposit_status)->toBe('held')
        ->and($rental->deposit_cents)->toBe(50000)
        ->and($this->asset->fresh()->status)->toBe('rented')
        ->and(ServiceCharge::where('operation', 'hold')->where('amount_cents', 50000)->count())->toBe(1);

    $this->actingAs($this->user)
        ->get('/servicios-estudiante/renta-equipos')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('equipment.0.deposit', '500.00')
            ->where('rentals.0.deposit_status', 'held')
            ->where('rentals.0.picked_up', false));
});

test('a rented asset cannot be rented again', function () {
    rentAsset($this);

    $this->actingAs($this->user)->post('/servicios-estudiante/renta-equipos', [
        'asset_id' => (string) $this->asset->id,
        'due_at' => now()->addDays(3)->toDateString(),
    ])->assertSessionHasErrors('rental');

    expect(Rental::count())->toBe(1);
});

test('if the rental cannot be completed no deposit is left held', function () {
    AssetCondition::creating(fn () => throw new RuntimeException('Falla simulada'));

    $this->actingAs($this->user)->post('/servicios-estudiante/renta-equipos', [
        'asset_id' => (string) $this->asset->id,
        'due_at' => now()->addDays(3)->toDateString(),
    ])->assertSessionHasErrors('rental');

    expect(Rental::count())->toBe(0)
        ->and(ServiceCharge::count())->toBe(0)
        ->and($this->asset->fresh()->status)->toBe('available');
});

test('a student cannot self-return an asset that holds a deposit', function () {
    $rental = rentAsset($this);

    $this->actingAs($this->user)
        ->patch("/servicios-estudiante/renta-equipos/{$rental->id}/devolver", ['condition' => 'good'])
        ->assertSessionHasErrors('rental');

    expect($rental->fresh()->status)->toBe('active')
        ->and($rental->fresh()->deposit_status)->toBe('held');
});

test('a desk return in good condition releases the whole deposit', function () {
    $rental = rentAsset($this);

    deskRental($this, 'return', ['condition' => 'good'])->assertSessionHas('access_result.granted', true);

    expect($rental->fresh()->status)->toBe('returned')
        ->and($rental->fresh()->deposit_status)->toBe('released')
        ->and($rental->fresh()->damage_charge_cents)->toBe(0)
        ->and($this->asset->fresh()->status)->toBe('available')
        ->and($rental->fresh()->deposit_released_cents)->toBe(50000)
        ->and($rental->fresh()->deposit_settlement_reference)->toBe(ServiceCharge::where('operation', 'settle')->value('reference'));
});

test('damage found at the desk is charged from the deposit and the rest is released', function () {
    $rental = rentAsset($this);

    deskRental($this, 'return', ['condition' => 'damaged', 'damage_charge' => '150.00'])
        ->assertSessionHas('access_result.granted', true);

    expect($rental->fresh()->deposit_status)->toBe('charged')
        ->and($rental->fresh()->damage_charge_cents)->toBe(15000)
        ->and($this->asset->fresh()->status)->toBe('maintenance')
        ->and(ServiceCharge::where('operation', 'settle')->where('amount_cents', 15000)->count())->toBe(1)
        ->and($rental->fresh()->deposit_released_cents)->toBe(35000)
        ->and($rental->fresh()->deposit_settlement_reference)->toBe(ServiceCharge::where('operation', 'settle')->value('reference'))
        ->and(ServiceAuditLog::where('action', 'rental.deposit.charged')->first()->after['damage_charge_cents'])->toBe(15000);
});

test('a lost settlement response is recovered with the same deposit key and assessment', function () {
    $rental = rentAsset($this);
    $gateway = app(StudentPaymentGateway::class);
    $calls = 0;
    $this->mock(StudentPaymentGateway::class)->shouldReceive('settleDeposit')
        ->with($rental->deposit_hold_reference, 15000, 'Devolución: damaged', 'rental:'.$rental->id.':deposit-settle')
        ->twice()->andReturnUsing(function (...$arguments) use ($gateway, &$calls) {
            $receipt = $gateway->settleDeposit(...$arguments);
            if (++$calls === 1) {
                throw new PaymentException('Se perdió la respuesta de Finanzas.');
            }

            return $receipt;
        });

    deskRental($this, 'return', ['condition' => 'damaged', 'damage_charge' => '150.00'])
        ->assertSessionHas('access_result.granted', false);
    expect($rental->fresh()->deposit_status)->toBe('held');

    deskRental($this, 'return', ['condition' => 'good'])->assertSessionHas('access_result.granted', false);
    deskRental($this, 'return', ['condition' => 'damaged', 'damage_charge' => '200.00'])
        ->assertSessionHas('access_result.granted', true);

    $closed = $rental->fresh();
    expect($closed->damage_charge_cents)->toBe(15000)
        ->and($closed->deposit_released_cents)->toBe(35000)
        ->and($closed->deposit_settlement_reference)->toBe(ServiceCharge::where('operation', 'settle')->sole()->reference);
});

test('a damage charge above the deposit or on a good return is refused', function () {
    $rental = rentAsset($this);

    deskRental($this, 'return', ['condition' => 'damaged', 'damage_charge' => '600'])->assertSessionHas('access_result.granted', false);
    deskRental($this, 'return', ['condition' => 'good', 'damage_charge' => '10'])->assertSessionHas('access_result.granted', false);

    expect($rental->fresh()->status)->toBe('active')
        ->and($rental->fresh()->deposit_status)->toBe('held')
        ->and(ServiceCharge::where('operation', 'settle')->count())->toBe(0);
});

test('a lost asset charges the full deposit', function () {
    $rental = rentAsset($this);

    deskRental($this, 'return', ['condition' => 'lost'])->assertSessionHas('access_result.granted', true);

    expect($rental->fresh()->damage_charge_cents)->toBe(50000)
        ->and(ServiceCharge::where('operation', 'settle')->value('amount_cents'))->toBe(50000)
        ->and($rental->fresh()->deposit_released_cents)->toBe(0);
});

test('a return that failed after the deposit was settled can be retried without moving money again', function () {
    $rental = rentAsset($this);
    $rental->update(['deposit_status' => 'charged', 'damage_charge_cents' => 10000]);

    app(RentalService::class)->returnRental($rental->fresh(), 'damaged', null, 20000);

    expect($rental->fresh()->status)->toBe('returned')
        ->and($rental->fresh()->damage_charge_cents)->toBe(10000)
        ->and(ServiceCharge::where('operation', 'settle')->count())->toBe(0);
});

test('a rental can be cancelled before pickup, releasing the deposit, but not after', function () {
    $rental = rentAsset($this);

    $this->actingAs($this->user)
        ->patch("/servicios-estudiante/renta-equipos/{$rental->id}/cancelar")
        ->assertSessionHasNoErrors();

    expect($rental->fresh()->status)->toBe('cancelled')
        ->and($rental->fresh()->deposit_status)->toBe('released')
        ->and($this->asset->fresh()->status)->toBe('available');

    $second = rentAsset($this);

    deskRental($this, 'pickup')->assertSessionHas('access_result.granted', true);

    expect($second->fresh()->picked_up_at)->not->toBeNull();

    $this->actingAs($this->user)
        ->patch("/servicios-estudiante/renta-equipos/{$second->id}/cancelar")
        ->assertSessionHasErrors('rental');

    expect($second->fresh()->status)->toBe('active');
});

test('assets without a deposit can still be returned by the student', function () {
    $this->asset->update(['deposit_cents' => 0]);

    $rental = rentAsset($this);

    $this->actingAs($this->user)
        ->patch("/servicios-estudiante/renta-equipos/{$rental->id}/devolver", ['condition' => 'good'])
        ->assertSessionHasNoErrors();

    expect($rental->fresh()->status)->toBe('returned')
        ->and($rental->fresh()->deposit_status)->toBe('none')
        ->and(ServiceCharge::count())->toBe(0);
});
