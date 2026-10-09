<?php

namespace App\Models\StudentServices\Library;

use MongoDB\Laravel\Eloquent\Model;

class Book extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'books';

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
