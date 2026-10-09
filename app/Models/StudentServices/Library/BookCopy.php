<?php

namespace App\Models\StudentServices\Library;

use Carbon\CarbonInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

/**
 * @property-read string $id
 * @property ObjectId|string|null $book_id
 * @property string $code
 * @property string|null $barcode
 * @property string|null $location
 * @property string $status
 * @property string|null $notes
 * @property string|null $inventory_code
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class BookCopy extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'book_copies';

    protected $fillable = [
        'book_id',
        'code',
        'barcode',
        'location',
        'status',
        'notes',
    ];

    public function setBookIdAttribute(mixed $value): void
    {
        $this->attributes['book_id'] = $value instanceof ObjectId
            ? $value
            : new ObjectId((string) $value);
    }
}
