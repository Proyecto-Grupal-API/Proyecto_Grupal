<?php

namespace App\Http\Controllers\StudentServices\Library;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentServices\Library\StoreBookCopyRequest;
use App\Http\Requests\StudentServices\Library\UpdateBookCopyRequest;
use App\Models\StudentServices\Library\Book;
use App\Models\StudentServices\Library\BookCopy;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use MongoDB\BSON\ObjectId;

class BookCopyController extends Controller
{
    public function index(): Response
    {
        $copies = BookCopy::query()
            ->orderBy('code')
            ->get()
            ->map(function (BookCopy $copy) {
                $book = Book::find(
                    (string) $copy->book_id
                );

                return [
                    'id' => (string) $copy->id,
                    'book_id' => (string) $copy->book_id,
                    'book_title' => $book->title ?? 'Libro no encontrado',
                    'book_isbn' => $book?->isbn,
                    'code' => $copy->code,
                    'barcode' => $copy->barcode,
                    'location' => $copy->location,
                    'status' => $copy->status,
                    'notes' => $copy->notes,
                ];
            })
            ->values();

        $books = Book::query()
            ->where('status', 'active')
            ->orderBy('title')
            ->get()
            ->map(function (Book $book) {
                return [
                    'id' => (string) $book->id,
                    'title' => $book->title,
                    'isbn' => $book->isbn,
                ];
            })
            ->values();

        return Inertia::render(
            'student-services/library/Copies',
            [
                'copies' => $copies,
                'books' => $books,
            ]
        );
    }

    public function store(
        StoreBookCopyRequest $request
    ): RedirectResponse {
        $data = $request->validated();

        $book = Book::find($request->string('book_id')->value());

        if ($book === null || $book->status !== 'active') {
            return back()->withErrors([
                'book_id' => 'El libro seleccionado no existe o está inactivo.',
            ]);
        }

        $codeExists = BookCopy::query()
            ->where('code', trim($data['code']))
            ->exists();

        if ($codeExists) {
            return back()->withErrors([
                'code' => 'Ya existe un ejemplar con ese código.',
            ]);
        }

        if (! empty($data['barcode'])) {
            $barcodeExists = BookCopy::query()
                ->where(
                    'barcode',
                    trim($data['barcode'])
                )
                ->exists();

            if ($barcodeExists) {
                return back()->withErrors([
                    'barcode' => 'Ya existe un ejemplar con ese código de barras.',
                ]);
            }
        }

        BookCopy::create([
            'book_id' => $book->id,
            'code' => trim($data['code']),
            'barcode' => ! empty($data['barcode'])
                ? trim($data['barcode'])
                : null,
            'location' => trim($data['location']),
            'status' => 'available',
            'notes' => ! empty($data['notes'])
                ? trim($data['notes'])
                : null,
        ]);

        return back()->with(
            'success',
            'Ejemplar registrado correctamente.'
        );
    }

    public function update(
        UpdateBookCopyRequest $request,
        string $copyId
    ): RedirectResponse {
        $copy = BookCopy::findOrFail($copyId);

        $data = $request->validated();

        $book = Book::find($request->string('book_id')->value());

        if ($book === null || $book->status !== 'active') {
            return back()->withErrors([
                'book_id' => 'El libro seleccionado no existe o está inactivo.',
            ]);
        }

        /*
         * Si está prestado o reservado, no permitimos cambiar
         * el libro al que pertenece.
         */
        if (
            in_array(
                $copy->status,
                ['loaned', 'reserved'],
                true
            ) &&
            (string) $copy->book_id !== (string) $book->id
        ) {
            return back()->withErrors([
                'book_id' => 'No puedes cambiar el libro de un ejemplar prestado o reservado.',
            ]);
        }

        $codeExists = BookCopy::query()
            ->where('code', trim($data['code']))
            ->where(
                '_id',
                '!=',
                new ObjectId((string) $copy->id)
            )
            ->exists();

        if ($codeExists) {
            return back()->withErrors([
                'code' => 'Ya existe otro ejemplar con ese código.',
            ]);
        }

        if (! empty($data['barcode'])) {
            $barcodeExists = BookCopy::query()
                ->where(
                    'barcode',
                    trim($data['barcode'])
                )
                ->where(
                    '_id',
                    '!=',
                    new ObjectId((string) $copy->id)
                )
                ->exists();

            if ($barcodeExists) {
                return back()->withErrors([
                    'barcode' => 'Ya existe otro ejemplar con ese código de barras.',
                ]);
            }
        }

        $copy->update([
            'book_id' => $book->id,
            'code' => trim($data['code']),
            'barcode' => ! empty($data['barcode'])
                ? trim($data['barcode'])
                : null,
            'location' => trim($data['location']),
            'notes' => ! empty($data['notes'])
                ? trim($data['notes'])
                : null,
        ]);

        return back()->with(
            'success',
            'Ejemplar actualizado correctamente.'
        );
    }

    public function markMaintenance(
        string $copyId
    ): RedirectResponse {
        $copy = BookCopy::findOrFail($copyId);

        if ($copy->status !== 'available') {
            return back()->withErrors([
                'status' => 'Solo un ejemplar disponible puede enviarse a mantenimiento.',
            ]);
        }

        $copy->update([
            'status' => 'maintenance',
        ]);

        return back()->with(
            'success',
            'Ejemplar enviado a mantenimiento.'
        );
    }

    public function markLost(
        string $copyId
    ): RedirectResponse {
        $copy = BookCopy::findOrFail($copyId);

        if (
            ! in_array(
                $copy->status,
                ['available', 'maintenance'],
                true
            )
        ) {
            return back()->withErrors([
                'status' => 'No se puede marcar como extraviado un ejemplar prestado o reservado desde esta sección.',
            ]);
        }

        $copy->update([
            'status' => 'lost',
        ]);

        return back()->with(
            'success',
            'Ejemplar marcado como extraviado.'
        );
    }

    public function restoreAvailable(
        string $copyId
    ): RedirectResponse {
        $copy = BookCopy::findOrFail($copyId);

        if ($copy->status !== 'maintenance') {
            return back()->withErrors([
                'status' => 'Solo un ejemplar en mantenimiento puede regresar manualmente a disponible.',
            ]);
        }

        $copy->update([
            'status' => 'available',
        ]);

        return back()->with(
            'success',
            'Ejemplar disponible nuevamente.'
        );
    }
}
