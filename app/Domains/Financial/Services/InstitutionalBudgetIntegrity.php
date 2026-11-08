<?php
namespace App\Domains\Financial\Services;

use App\Domains\Financial\Models\InstitutionalBonusBudget;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Internal invariant, not an authorization decision. Caller holds the budget write lock. */
final class InstitutionalBudgetIntegrity
{
    public function __construct(private readonly InstitutionalObligationQueryService $query) {}

    public function assertState(InstitutionalBonusBudget $budget): void
    {
        if (DB::connection('sqlsrv')->transactionLevel() < 1) {
            throw new LogicException('La comprobacion institucional requiere una transaccion y bloqueo del presupuesto.');
        }
        // The report reads all businesses and linked evidence for this budget, not just the current bonus.
        // Its DECIMAL(38,0) aggregates stay exact strings; never cast totals to PHP int/float.
        $id = strtolower($budget->public_id);
        $report = $this->query->report($id, null, 1, 1);
        if (($report['status'] ?? null) !== 'CONSISTENT' || ($report['consistent'] ?? null) !== true
            || count($report['budgets'] ?? []) !== 1 || ($report['budgets'][0]['budget_id'] ?? null) !== $id
            || ($report['budgets'][0]['consistent'] ?? null) !== true || ($report['budgets'][0]['findings'] ?? null) !== []) {
            throw new ConflictHttpException('El presupuesto o su evidencia requieren revision. No se aplico la operacion.');
        }
    }
}
