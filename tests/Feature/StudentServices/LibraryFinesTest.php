<?php

use App\Models\StudentServices\Library\Book;
use App\Models\StudentServices\Library\BookCopy;
use App\Models\StudentServices\Library\LibraryFine;
use App\Models\StudentServices\Library\Loan;
use App\Models\User;
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

test('a pending fine can be paid with a reference or waived', function () {
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
