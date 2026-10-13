<?php

namespace App\Models\StudentServices\Library;

use Carbon\CarbonInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

/**
 * @property-read string $id
 * @property ObjectId|string $copy_id
 * @property string $student_id
 * @property CarbonInterface|null $borrowed_at
 * @property CarbonInterface|null $due_at
 * @property CarbonInterface|null $returned_at
 * @property string $status
 * @property int $renewal_count
 * @property string|null $notes
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Loan extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'loans';

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

    public function setCopyIdAttribute(mixed $value): void
    {
        $this->attributes['copy_id'] = $value instanceof ObjectId
            ? $value
            : new ObjectId((string) $value);
    }
}
