<?php

namespace App\Models\StudentServices\Library;

use Carbon\CarbonInterface;
use MongoDB\Laravel\Eloquent\Model;

/**
 * @property-read string $id
 * @property string $isbn
 * @property string $title
 * @property array<int, string>|null $authors
 * @property string|null $publisher
 * @property string|null $edition
 * @property int|null $publication_year
 * @property string|null $category
 * @property string|null $description
 * @property string|null $cover
 * @property string $status
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Book extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'books';

    protected $fillable = [
        'isbn',
        'title',
        'authors',
        'publisher',
        'edition',
        'publication_year',
        'category',
        'description',
        'cover',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'publication_year' => 'integer',
        ];
    }
}
