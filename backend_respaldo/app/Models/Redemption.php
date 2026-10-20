<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Redemption extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'redemptions';

    protected $fillable = [
        'student_id',
        'reward_id',
        'reward_item_id',
        'points_spent',
        'status',
        'redemption_code',
        'ledger_entry_id',
        'stock_reservation_reference',
        'requested_at',
        'delivered_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'points_spent' => 'integer',
        'requested_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public const TRANSITIONS = [
        'requested'     => ['reserved', 'cancelled'],
        'reserved'      => ['stock_pending', 'confirmed', 'cancelled'],
        'stock_pending' => ['confirmed', 'cancelled'],
        'confirmed'     => ['delivered', 'cancelled'],
        'delivered'     => ['reversed'],
        'cancelled'     => [],
        'reversed'      => [],
    ];

    public function canTransitionTo(string $next): bool
    {
        return in_array($next, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function events()
    {
        return $this->hasMany(RedemptionEvent::class, 'redemption_id');
    }

    public function reward()
    {
        return $this->belongsTo(Reward::class, 'reward_id');
    }
}
