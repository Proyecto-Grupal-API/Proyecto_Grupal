<?php

use App\Models\StudentServices\Library\Book;
use App\Models\StudentServices\Library\BookCopy;
use App\Models\StudentServices\Library\LibraryFine;
use App\Models\StudentServices\Library\Loan;
use App\Models\User;
use App\Services\StudentServices\Payments\StudentPaymentGateway;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    useStudentServicesTestDatabase();
    $this->withoutVite();

    $book = Book::create(['title' => 'Design Patterns', 'status' => 'active']);
    $copy = BookCopy::create(['book_id' => $book->id, 'code' => 'EJ-002', 'status' => 'loaned']);

    $this->loan = Loan::create([
        'copy_id' => $copy->id,
        'student_id' => 'student-1',
        'borrowed_at' => now()->subDays(10),
        'due_at' => now()->subDays(3),
        'status' => 'overdue',
        'renewal_count' => 0,
    ]);
});

test('registering a fine gives it a folio and the page lists it with its loan', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/servicios-estudiante/biblioteca/multas', [
        'loan_id' => (string) $this->loan->id,
        'type' => 'late',
        'amount_cents' => 4500,
        'reason' => 'Tres días de atraso',
    ])->assertSessionHasNoErrors();

    $fine = LibraryFine::first();

    expect($fine->folio)->toStartWith('MUL-')
        ->and($fine->student_id)->toBe('student-1');

    $this->actingAs($user)
        ->get('/servicios-estudiante/biblioteca/multas')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('student-services/library/Fines')
            ->where('fines.0.folio', $fine->folio)
            ->where('fines.0.book_title', 'Design Patterns')
            ->where('fines.0.copy_code', 'EJ-002')
            ->has('loans', 1));
});

test('a pending fine can be paid with confirmed evidence or waived', function () {
    $user = User::factory()->create();

    foreach (['late', 'damage'] as $type) {
        $this->actingAs($user)->post('/servicios-estudiante/biblioteca/multas', [
            'loan_id' => (string) $this->loan->id,
            'type' => $type,
            'amount_cents' => 1000,
            'reason' => 'Motivo',
        ]);
    }

    [$late, $damage] = [LibraryFine::where('type', 'late')->first(), LibraryFine::where('type', 'damage')->first()];

    $this->mock(StudentPaymentGateway::class)->shouldReceive('lookupFinePayment')
        ->with((string) $late->id, 'PAY-001')->once()->andReturn(studentServiceFinePayment($late, 'PAY-001'));

    $this->actingAs($user)
        ->patch("/servicios-estudiante/biblioteca/multas/{$late->id}/pagar", ['payment_reference_id' => 'PAY-001'])
        ->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->patch("/servicios-estudiante/biblioteca/multas/{$damage->id}/condonar", ['notes' => 'Primera vez'])
        ->assertSessionHasNoErrors();

    expect($late->fresh()->status)->toBe('paid')
        ->and($late->fresh()->payment_reference_id)->toBe('PAY-001')
        ->and($damage->fresh()->status)->toBe('waived');
});

test('fines created before folios existed get a readable fallback folio', function () {
    $fine = LibraryFine::create([
        'student_id' => 'student-1',
        'loan_id' => $this->loan->id,
        'type' => 'other',
        'amount_cents' => 100,
        'reason' => 'Dato previo',
        'status' => 'pending',
        'generated_at' => now(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/servicios-estudiante/biblioteca/multas')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('fines.0.folio', 'MUL-'.strtoupper(substr((string) $fine->id, -6))));
});


test('a reference without confirmed financial evidence leaves the fine pending', function () {
    $fine = LibraryFine::create([
        'student_id' => 'student-1', 'loan_id' => $this->loan->id, 'type' => 'late',
        'amount_cents' => 4500, 'reason' => 'Atraso', 'status' => 'pending', 'generated_at' => now(),
    ]);
    $this->actingAs(User::factory()->create())
        ->patch("/servicios-estudiante/biblioteca/multas/{$fine->id}/pagar", ['payment_reference_id' => 'PAY-WITHOUT-EVIDENCE'])
        ->assertSessionHasErrors('fine');
    expect($fine->fresh()->status)->toBe('pending');
});

test('finance evidence is matched to the fine and a payment cannot settle two fines', function () {
    $common = [
        'student_id' => 'student-1', 'loan_id' => $this->loan->id,
        'amount_cents' => 4500, 'reason' => 'Atraso', 'status' => 'pending', 'generated_at' => now(),
    ];
    $first = LibraryFine::create($common + ['type' => 'late']);
    $second = LibraryFine::create($common + ['type' => 'damage']);
    $ref = 'PAY-ONCE';
    $gateway = $this->mock(StudentPaymentGateway::class);
    $gateway->shouldReceive('lookupFinePayment')->with((string) $first->id, 'PAY-WRONG')->once()
        ->andReturn(array_replace(studentServiceFinePayment($first, 'PAY-WRONG'), ['amount_cents' => 4400]));
    $gateway->shouldReceive('lookupFinePayment')->with((string) $first->id, $ref)->once()
        ->andReturn(studentServiceFinePayment($first, $ref));
    $gateway->shouldReceive('lookupFinePayment')->with((string) $second->id, $ref)->once()
        ->andReturn(studentServiceFinePayment($second, $ref));

    $this->actingAs(User::factory()->create());
    $firstUrl = "/servicios-estudiante/biblioteca/multas/{$first->id}/pagar";
    $this->patch($firstUrl, ['payment_reference_id' => 'PAY-WRONG'])->assertSessionHasErrors('fine');
    expect($first->fresh()->status)->toBe('pending');
    $this->patch($firstUrl, ['payment_reference_id' => $ref])->assertSessionHasNoErrors();
    $this->patch($firstUrl, ['payment_reference_id' => $ref])->assertSessionHasNoErrors();
    $this->patch("/servicios-estudiante/biblioteca/multas/{$second->id}/pagar", ['payment_reference_id' => $ref])
        ->assertSessionHasErrors(['fine' => 'El pago ya está asociado a otra multa.']);
    expect($first->fresh()->verified_payment_id)->toBe($ref)->and($second->fresh()->status)->toBe('pending');
});
