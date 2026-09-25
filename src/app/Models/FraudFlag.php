<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class FraudFlag extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'fraud_flags';

    protected $fillable = [
        'student_id',
        'business_id',
        'reason',
        'severity',
        'related_ledger_id',
        'related_redemption_id',
        'status',
        'reviewed_by',
        'reviewed_at',
        'notes',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];
}
