<?php

namespace App\Models\StudentServices\Support;

use Carbon\CarbonInterface;
use MongoDB\Laravel\Eloquent\Model;

/**
 * @property-read string $id
 * @property string $folio
 * @property string $student_id
 * @property string $category
 * @property string $subject
 * @property string $description
 * @property string $priority
 * @property string|null $location
 * @property string $status
 * @property string|null $assigned_to
 * @property string|null $evidence_name
 * @property string|null $evidence_path
 * @property CarbonInterface|null $opened_at
 * @property CarbonInterface|null $sla_due_at
 * @property CarbonInterface|null $resolved_at
 * @property CarbonInterface|null $closed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class SupportTicket extends Model
{
    protected $connection = 'mongodb';

    protected $table = 'support_tickets';

    protected $fillable = [
        'folio',
        'student_id',

        'category',
        'subject',
        'description',
        'priority',

        'location',

        'status',
        'assigned_to',

        'evidence_name',
        'evidence_path',

        'opened_at',
        'sla_due_at',
        'resolved_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'sla_due_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
