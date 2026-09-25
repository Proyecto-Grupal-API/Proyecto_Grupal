<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class PointsAccount extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'points_accounts';

    protected $fillable = [
        'student_id',
        'balance',
        'pending_balance',
        'lifetime_earned',
        'lifetime_redeemed',
        'last_ledger_entry_id',
    ];

    protected $casts = [
        'balance' => 'integer',
        'pending_balance' => 'integer',
        'lifetime_earned' => 'integer',
        'lifetime_redeemed' => 'integer',
    ];

    public function ledgerEntries()
    {
        return $this->hasMany(PointsLedger::class, 'student_id', 'student_id');
    }

    public function reconcileFromLedger(): int
    {
        $sum = PointsLedger::where('student_id', $this->student_id)->sum('amount');
        return (int) $sum;
    }
}
