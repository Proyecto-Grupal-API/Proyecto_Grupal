<?php

namespace App\Models\StudentServices\Library;

use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

class LibraryFine extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'library_fines';

    protected $fillable = [
        'folio',
        'student_id',
        'loan_id',
        'type',
        'amount_cents',
        'reason',
        'status',
        'generated_at',
        'payment_reference_id',
        'paid_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'generated_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function setLoanIdAttribute($value): void
    {
        $this->attributes['loan_id'] = $value instanceof ObjectId
            ? $value
            : new ObjectId((string) $value);
    }
}
