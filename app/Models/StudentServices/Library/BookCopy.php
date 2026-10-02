<?php

namespace App\Models\StudentServices\Library;

use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

class BookCopy extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'book_copies';

    protected $fillable = [
        'book_id',
        'code',
        'barcode',
        'location',
        'status',
        'notes',
    ];

    public function setBookIdAttribute($value): void
    {
        $this->attributes['book_id'] = $value instanceof ObjectId
            ? $value
            : new ObjectId((string) $value);
    }
}
