<?php

use App\Models\StudentServices\Library\Book;
use App\Models\StudentServices\Library\BookCopy;
use App\Models\StudentServices\Library\BookReservation;
use App\Models\StudentServices\Library\LibraryFine;
use App\Models\StudentServices\Library\Loan;
use App\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    useStudentServicesTestDatabase();
    $this->withoutVite();

    $this->user = User::factory()->create();
    $this->book = Book::create(['title' => 'Clean Code', 'status' => 'active']);
    $this->copy = BookCopy::create(['book_id' => $this->book->id, 'code' => 'EJ-100', 'status' => 'available']);
});

function lendCopy(object $test, string $studentId = 'student-1'): TestResponse
{
    return $test->actingAs($test->user)->post('/servicios-estudiante/biblioteca/prestamos', [
        'copy_id' => (string) $test->copy->id,
        'student_id' => $studentId,
        'loan_days' => 7,
    ]);
}

function pendingFineFor(string $studentId): LibraryFine
{
    return LibraryFine::create([
        'student_id' => $studentId,
        'type' => 'late',
        'amount_cents' => 2500,
        'status' => 'pending',
        'reason' => 'Atraso',
    ]);
}

test('a loan marks the copy as loaned and cannot be lent twice', function () {
    lendCopy($this)->assertSessionHasNoErrors();

    expect(Loan::count())->toBe(1)
        ->and($this->copy->fresh()->status)->toBe('loaned');

    lendCopy($this, 'student-2')->assertSessionHasErrors('loan');

    expect(Loan::count())->toBe(1);
});

test('a student with a pending fine cannot borrow until the fine is paid', function () {
    $fine = pendingFineFor('student-1');

    lendCopy($this)->assertSessionHasErrors(['loan' => 'El alumno tiene multas pendientes. Debe pagarlas o resolverlas antes de usar la biblioteca.']);

    expect(Loan::count())->toBe(0)
        ->and($this->copy->fresh()->status)->toBe('available');

    $this->actingAs($this->user)
        ->patch("/servicios-estudiante/biblioteca/multas/{$fine->id}/pagar", ['payment_reference_id' => 'PAY-77'])
        ->assertSessionHasNoErrors();

    lendCopy($this)->assertSessionHasNoErrors();

    expect(Loan::count())->toBe(1);
});

test('a waived fine also releases the block', function () {
    $fine = pendingFineFor('student-1');

    $this->actingAs($this->user)
        ->patch("/servicios-estudiante/biblioteca/multas/{$fine->id}/condonar", ['notes' => 'Primera vez'])
        ->assertSessionHasNoErrors();

    lendCopy($this)->assertSessionHasNoErrors();
});

test('renewals are blocked by pending fines and capped at the maximum', function () {
    lendCopy($this);
    $loan = Loan::first();
    $originalDue = $loan->due_at;

    $fine = pendingFineFor('student-1');

    $this->actingAs($this->user)
        ->patch("/servicios-estudiante/biblioteca/prestamos/{$loan->id}/renovar", ['additional_days' => 7])
        ->assertSessionHasErrors('loan');

    expect($loan->fresh()->renewal_count)->toBe(0);

    $fine->update(['status' => 'paid']);

    foreach ([1, 2] as $renewal) {
        $this->actingAs($this->user)
            ->patch("/servicios-estudiante/biblioteca/prestamos/{$loan->id}/renovar", ['additional_days' => 7])
            ->assertSessionHasNoErrors();
    }

    $this->actingAs($this->user)
        ->patch("/servicios-estudiante/biblioteca/prestamos/{$loan->id}/renovar", ['additional_days' => 7])
        ->assertSessionHasErrors('loan');

    expect($loan->fresh()->renewal_count)->toBe(2)
        ->and($loan->fresh()->due_at->equalTo($originalDue->copy()->addDays(14)))->toBeTrue();
});

test('returning a loan frees the copy and an overdue loan cannot be renewed', function () {
    lendCopy($this);
    $loan = Loan::first();
    $loan->update(['due_at' => now()->subDay()]);

    $this->actingAs($this->user)
        ->patch("/servicios-estudiante/biblioteca/prestamos/{$loan->id}/renovar", ['additional_days' => 7])
        ->assertSessionHasErrors('loan');

    expect($loan->fresh()->status)->toBe('overdue');

    $this->actingAs($this->user)
        ->patch("/servicios-estudiante/biblioteca/prestamos/{$loan->id}/devolver")
        ->assertSessionHasNoErrors();

    expect($loan->fresh()->status)->toBe('returned')
        ->and($this->copy->fresh()->status)->toBe('available');
});

test('a book reservation is refused while the student owes a fine', function () {
    pendingFineFor('student-1');

    $this->actingAs($this->user)->post('/servicios-estudiante/biblioteca/reservas', [
        'book_id' => (string) $this->book->id,
        'student_id' => 'student-1',
    ])->assertSessionHasErrors('reservation');

    expect(BookReservation::count())->toBe(0);
});

test('a reservation gets a copy assigned and becomes a loan when fulfilled', function () {
    $this->actingAs($this->user)->post('/servicios-estudiante/biblioteca/reservas', [
        'book_id' => (string) $this->book->id,
        'student_id' => 'student-1',
    ])->assertSessionHasNoErrors();

    $reservation = BookReservation::first();

    $this->actingAs($this->user)
        ->patch("/servicios-estudiante/biblioteca/reservas/{$reservation->id}/asignar")
        ->assertSessionHasNoErrors();

    expect($reservation->fresh()->status)->toBe('ready')
        ->and($this->copy->fresh()->status)->toBe('reserved');

    $this->actingAs($this->user)
        ->patch("/servicios-estudiante/biblioteca/reservas/{$reservation->id}/completar", ['loan_days' => 7])
        ->assertSessionHasNoErrors();

    expect($reservation->fresh()->status)->toBe('fulfilled')
        ->and(Loan::where('student_id', 'student-1')->count())->toBe(1)
        ->and($this->copy->fresh()->status)->toBe('loaned');
});
