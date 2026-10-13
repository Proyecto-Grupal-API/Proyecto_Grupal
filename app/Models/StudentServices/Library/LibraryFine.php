<?php

namespace App\Models\StudentServices\Library;

use Carbon\CarbonInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

/**
 * @property-read string $id
 * @property string|null $folio
 * @property string $student_id
 * @property ObjectId|string $loan_id
 * @property string $type
 * @property int $amount_cents
 * @property string $reason
 * @property string $status
 * @property CarbonInterface|null $generated_at
 * @property string|null $payment_reference_id
 * @property CarbonInterface|null $paid_at
 * @property string|null $notes
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class LibraryFine extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'library_fines';

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

    public function setLoanIdAttribute(mixed $value): void
    {
        $this->attributes['loan_id'] = $value instanceof ObjectId
            ? $value
            : new ObjectId((string) $value);
    }
}
