<?php

namespace App\Models\StudentServices\Library;

use Carbon\CarbonInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

/**
 * @property-read string $id
 * @property ObjectId|string $book_id
 * @property string $student_id
 * @property ObjectId|string|null $assigned_copy_id
 * @property CarbonInterface|null $reserved_at
 * @property CarbonInterface|null $ready_at
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $fulfilled_at
 * @property string $status
 * @property string|null $notes
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class BookReservation extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'book_reservations';

    protected $fillable = [
        'book_id',
        'student_id',
        'assigned_copy_id',
        'reserved_at',
        'ready_at',
        'expires_at',
        'fulfilled_at',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'reserved_at' => 'datetime',
            'ready_at' => 'datetime',
            'expires_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    public function setBookIdAttribute(mixed $value): void
    {
        $this->attributes['book_id'] = $value instanceof ObjectId
            ? $value
            : new ObjectId((string) $value);
    }

    public function setAssignedCopyIdAttribute(mixed $value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['assigned_copy_id'] = null;

            return;
        }

        $this->attributes['assigned_copy_id'] = $value instanceof ObjectId
            ? $value
            : new ObjectId((string) $value);
    }
}
