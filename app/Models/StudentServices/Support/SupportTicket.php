<?php

namespace App\Models\StudentServices\Support;

use MongoDB\Laravel\Eloquent\Model;

class SupportTicket extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'support_tickets';

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
