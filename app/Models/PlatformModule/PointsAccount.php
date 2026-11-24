<?php

namespace App\Models\PlatformModule;

use MongoDB\Laravel\Eloquent\Model;

class PointsAccount extends Model
{
    protected $connection = 'platform';

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

    /**
     * Abona puntos a la cuenta. Si hay deuda pendiente por un reverso que no se pudo cubrir (pending_balance),
     * el abono la salda primero y sólo el resto pasa a saldo disponible. Así el saldo guardado siempre equivale
     * a max(0, ledger) y el pendiente a max(0, -ledger).
     */
    public function credit(int $points): void
    {
        if ($points <= 0) {
            return;
        }
        $settle = min((int) $this->pending_balance, $points);
        if ($settle > 0) {
            $this->decrement('pending_balance', $settle);
        }
        if ($points - $settle > 0) {
            $this->increment('balance', $points - $settle);
        }
    }
}
