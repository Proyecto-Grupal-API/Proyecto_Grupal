<?php
namespace App\Domains\Financial\Support;
use App\Domains\Financial\Models\Bonus;
use Illuminate\Support\Facades\{DB,Schema};
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Reject legacy writes on any institutional binding. Never repairs or caches evidence. */
final class InstitutionalBonusEvidence
{
    public static function assertUnfunded(Bonus $bonus): void
    {
        if ($bonus->getRawOriginal('institutional_budget_id') !== null) self::deny();
        foreach (['bonus_budget_commitments','institutional_bonus_obligations'] as $table) {
            // Older installations may not yet have the institutional schema. Query errors
            // are deliberately not swallowed or interpreted as absence of evidence.
            if (Schema::connection('sqlsrv')->hasTable($table)
                && DB::connection('sqlsrv')->table($table)->where('bonus_id',$bonus->public_id)->exists()) self::deny();
        }
    }
    private static function deny(): never
    {
        throw new ConflictHttpException('Este bono conserva evidencia institucional; requiere el flujo presupuestario.');
    }
}
