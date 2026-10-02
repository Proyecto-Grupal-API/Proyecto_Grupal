<?php

namespace App\Models\StudentServices\Library;

use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

class Loan extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'loans';

    protected $fillable = [
        'copy_id',
        'student_id',
        'borrowed_at',
        'due_at',
        'returned_at',
        'status',
        'renewal_count',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'borrowed_at' => 'datetime',
            'due_at' => 'datetime',
            'returned_at' => 'datetime',
            'renewal_count' => 'integer',
        ];
    }

    public function setCopyIdAttribute($value): void
    {
        $this->attributes['copy_id'] = $value instanceof ObjectId
            ? $value
            : new ObjectId((string) $value);
    }
}
