<?php

namespace App\Models\StudentServices\Library;

use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

class BookReservation extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'book_reservations';

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

    public function setBookIdAttribute($value): void
    {
        $this->attributes['book_id'] = $value instanceof ObjectId
            ? $value
            : new ObjectId((string) $value);
    }

    public function setAssignedCopyIdAttribute($value): void
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
