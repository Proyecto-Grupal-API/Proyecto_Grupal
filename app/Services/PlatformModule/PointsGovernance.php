<?php

namespace App\Services\PlatformModule;

use App\Models\PlatformModule\PointsLedger;
use App\Models\PlatformModule\PointsPolicy;
use App\Models\PlatformModule\Redemption;
use Illuminate\Support\Carbon;

/**
 * Políticas de gobierno de puntos (7.6) que el motor aplica de verdad.
 * Sólo cuentan las políticas activas; si varias fijan el mismo límite, rige el más estricto.
 */
final class PointsGovernance
{
    /** Tope general de puntos que un alumno puede acumular por día, o null si no hay política activa que lo fije. */
    public function dailyCapPoints(): ?int
    {
        return $this->strictest('daily_cap_points');
    }

    /** Máximo de canjes por alumno y día, o null si no hay política activa que lo fije. */
    public function maxRedemptionsPerDay(): ?int
    {
        return $this->strictest('max_redemptions_per_day');
    }

    /** Puntos que aún puede acumular hoy el alumno bajo el tope general (null = sin tope). */
    public function remainingToday(string $studentId): ?int
    {
        $cap = $this->dailyCapPoints();
        if ($cap === null) {
            return null;
        }
        $earned = (int) PointsLedger::where('student_id', $studentId)->where('type', 'earn')
            ->where('created_at', '>=', Carbon::now()->startOfDay())->sum('amount');

        return max(0, $cap - $earned);
    }

    public function redemptionsToday(string $studentId): int
    {
        return Redemption::where('student_id', $studentId)->where('created_at', '>=', Carbon::now()->startOfDay())
            ->whereNotIn('status', ['cancelled', 'failed'])->count();
    }

    private function strictest(string $field): ?int
    {
        $values = PointsPolicy::where('status', 'active')->get()
            ->map(fn (PointsPolicy $p) => $p->{$field})->filter(fn ($v) => is_int($v) && $v > 0);

        return $values->isEmpty() ? null : (int) $values->min();
    }
}
