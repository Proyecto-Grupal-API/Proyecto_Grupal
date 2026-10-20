<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class RedemptionEvent extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'redemption_events';

    public $timestamps = true;

    protected $fillable = [
        'redemption_id',
        'from_status',
        'to_status',
        'actor_id',
        'actor_role',
        'note',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function redemption()
    {
        return $this->belongsTo(Redemption::class, 'redemption_id');
    }
}
