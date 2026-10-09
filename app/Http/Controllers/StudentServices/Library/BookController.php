<?php

namespace App\Http\Controllers\StudentServices\Library;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentServices\Library\StoreBookRequest;
use App\Http\Requests\StudentServices\Library\UpdateBookRequest;
use App\Models\StudentServices\Library\Book;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use MongoDB\BSON\ObjectId;

class BookController extends Controller
{
    public function index(): Response
    {
        $books = Book::query()
            ->where('status', 'active')
            ->orderBy('title')
            ->get()
            ->map(function (Book $book) {
                return [
                    'id' => (string) $book->id,
                    'isbn' => $book->isbn,
                    'title' => $book->title,
                    'authors' => $book->authors ?? [],
                    'publisher' => $book->publisher,
                    'edition' => $book->edition,
                    'publication_year' => $book->publication_year,
                    'category' => $book->category,
                    'description' => $book->description,
                    'cover' => $book->cover,
                    'status' => $book->status,
                ];
            })
            ->values();

        return Inertia::render(
            'student-services/library/Index',
            [
                'books' => $books,
            ]
        );
    }

    public function store(
        StoreBookRequest $request
    ): RedirectResponse {
        $data = $request->validated();

        if (
            ! empty($data['isbn']) &&
            Book::query()
                ->where('isbn', $data['isbn'])
                ->exists()
        ) {
            return back()->withErrors([
                'isbn' => 'Ya existe un libro registrado con ese ISBN.',
            ]);
        }

        $authors = $this->parseAuthors($data['authors']);

        Book::create([
            'isbn' => $data['isbn'] ?? null,
            'title' => $data['title'],
            'authors' => $authors,
            'publisher' => $data['publisher'] ?? null,
            'edition' => $data['edition'] ?? null,
            'publication_year' => $data['publication_year'] ?? null,
            'category' => $data['category'] ?? null,
            'description' => $data['description'] ?? null,
            'cover' => null,
            'status' => 'active',
        ]);

        return back()->with(
            'success',
            'Libro registrado correctamente.'
        );
    }

    public function update(
        UpdateBookRequest $request,
        string $bookId
    ): RedirectResponse {
        $book = Book::findOrFail($bookId);

        $data = $request->validated();

        if (! empty($data['isbn'])) {
            $isbnExists = Book::query()
                ->where('isbn', $data['isbn'])
                ->where(
                    '_id',
                    '!=',
                    new ObjectId((string) $book->id)
                )
                ->exists();

            if ($isbnExists) {
                return back()->withErrors([
                    'isbn' => 'Ya existe otro libro registrado con ese ISBN.',
                ]);
            }
        }

        $authors = $this->parseAuthors($data['authors']);

        $book->update([
            'isbn' => $data['isbn'] ?? null,
            'title' => $data['title'],
            'authors' => $authors,
            'publisher' => $data['publisher'] ?? null,
            'edition' => $data['edition'] ?? null,
            'publication_year' => $data['publication_year'] ?? null,
            'category' => $data['category'] ?? null,
            'description' => $data['description'] ?? null,
        ]);

        return back()->with(
            'success',
            'Libro actualizado correctamente.'
        );
    }

    public function deactivate(
        string $bookId
    ): RedirectResponse {
        $book = Book::findOrFail($bookId);

        if ($book->status === 'inactive') {
            return back()->with(
                'success',
                'El libro ya se encuentra desactivado.'
            );
        }

        $book->update([
            'status' => 'inactive',
        ]);

        return back()->with(
            'success',
            'Libro desactivado correctamente.'
        );
    }

    /**
     * @return list<string>
     */
    private function parseAuthors(string $authors): array
    {
        return array_values(
            array_filter(
                array_map(
                    'trim',
                    explode(',', $authors)
                )
            )
        );
    }
}
