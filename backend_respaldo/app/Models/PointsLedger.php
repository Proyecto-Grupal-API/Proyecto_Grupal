<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;
use Illuminate\Database\Eloquent\MassPrunable;

class PointsLedger extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'points_ledger';

    public $timestamps = true;

    protected $fillable = [
        'student_id',
        'idempotency_key',
        'type',
        'amount',
        'source_domain',
        'source_reference',
        'related_ledger_id',
        'rule_id',
        'business_id',
        'description',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'integer',
        'metadata' => 'array',
    ];

    protected static function booted()
    {
        static::updating(function () {
            throw new \RuntimeException('points_ledger es append-only: no se permiten updates.');
        });
        static::deleting(function () {
            throw new \RuntimeException('points_ledger es append-only: no se permiten deletes.');
        });
    }

    public function account()
    {
        return $this->belongsTo(PointsAccount::class, 'student_id', 'student_id');
    }
}
