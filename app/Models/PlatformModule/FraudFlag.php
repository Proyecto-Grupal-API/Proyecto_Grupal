<?php

namespace App\Models\PlatformModule;

use MongoDB\Laravel\Eloquent\Model;

class FraudFlag extends Model
{
    protected $connection = 'platform';

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

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    public const STATUS_OPEN = 'open';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_DISMISSED = 'dismissed';

    public const SEVERITIES = [
        self::SEVERITY_LOW,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_HIGH,
        self::SEVERITY_CRITICAL,
    ];

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_RESOLVED,
        self::STATUS_DISMISSED,
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function ledgerEntry()
    {
        return $this->belongsTo(PointsLedger::class, 'related_ledger_id');
    }

    public function redemption()
    {
        return $this->belongsTo(Redemption::class, 'related_redemption_id');
    }
}
