<?php

use App\Models\StudentServices\Services\PrintJob;
use App\Models\StudentServices\Services\ServiceOrder;
use App\Models\User;
use App\Services\StudentServices\Services\ServiceOrderService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    useStudentServicesTestDatabase();
    $this->withoutVite();
    Storage::fake('local');

    $this->student = User::factory()->create();
});

function requestPrinting(User $student, array $overrides = []): TestResponse
{
    return test()->actingAs($student)->post('/servicios-estudiante/servicios-impresiones', [
        'service_type' => 'printing',
        'quantity' => 10,
        'color_mode' => 'bw',
        'paper_size' => 'Carta',
        'sides' => 'single',
        'file' => UploadedFile::fake()->create('tarea.pdf', 20, 'application/pdf'),
        ...$overrides,
    ]);
}

test('quotes are computed in integer cents', function (string $type, int $quantity, ?string $color, ?string $sides, int $expected) {
    expect(app(ServiceOrderService::class)->calculateQuote($type, $quantity, $color, $sides))->toBe($expected);
})->with([
    'b/w printing' => ['printing', 10, 'bw', 'single', 2000],
    'color printing double sided' => ['printing', 3, 'color', 'double', 1350],
    'b/w copy double sided rounds to the cent' => ['copy', 3, 'bw', 'double', 405],
    'scanning' => ['scanning', 2, 'bw', null, 600],
    'binding ignores sides' => ['binding', 1, null, 'double', 4500],
]);

test('a printing request stores the file, the print job and the quote', function () {
    requestPrinting($this->student)->assertSessionHasNoErrors();

    $order = ServiceOrder::first();
    $job = PrintJob::first();

    expect($order->quoted_amount_cents)->toBe(2000)
        ->and($order->status)->toBe('awaiting_payment')
        ->and($job->file_name)->toBe('tarea.pdf');

    Storage::disk('local')->assertExists($job->file_path);
});

test('a printing request without a file is rejected', function () {
    requestPrinting($this->student, ['file' => null])->assertSessionHasErrors('file');

    expect(ServiceOrder::count())->toBe(0);
});

test('an order goes from payment to processing, ready and delivered', function () {
    requestPrinting($this->student);
    $order = ServiceOrder::first();

    $this->actingAs($this->student)
        ->patch("/servicios-estudiante/servicios-impresiones/{$order->id}/pagar", ['payment_reference_id' => 'PAY-IMP-1'])
        ->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe('processing')
        ->and($order->fresh()->payment_status)->toBe('paid');

    $this->actingAs($this->student)
        ->patch("/servicios-estudiante/servicios-impresiones/{$order->id}/entregar")
        ->assertSessionHasErrors('order');

    $this->actingAs($this->student)->patch("/servicios-estudiante/servicios-impresiones/{$order->id}/listo")->assertSessionHasNoErrors();
    $this->actingAs($this->student)->patch("/servicios-estudiante/servicios-impresiones/{$order->id}/entregar")->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe('delivered');
});

test('a paid order cannot be cancelled and nobody else can pay or cancel it', function () {
    requestPrinting($this->student);
    $order = ServiceOrder::first();
    $other = User::factory()->create();

    $this->actingAs($other)
        ->patch("/servicios-estudiante/servicios-impresiones/{$order->id}/pagar", ['payment_reference_id' => 'PAY-X'])
        ->assertSessionHasErrors('order');

    $this->actingAs($other)
        ->patch("/servicios-estudiante/servicios-impresiones/{$order->id}/cancelar")
        ->assertSessionHasErrors('order');

    $this->actingAs($this->student)->patch("/servicios-estudiante/servicios-impresiones/{$order->id}/pagar", ['payment_reference_id' => 'PAY-OK']);

    $this->actingAs($this->student)
        ->patch("/servicios-estudiante/servicios-impresiones/{$order->id}/cancelar")
        ->assertSessionHasErrors('order');

    expect($order->fresh()->status)->toBe('processing');
});
