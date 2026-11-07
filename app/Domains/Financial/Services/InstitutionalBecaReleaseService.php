<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Contracts\{InstitutionalBudgetAuthorization, FinancialWebAuthorizer};
use App\Domains\Financial\Enums\{BonusStatus, BonusType, BonusMovementType};
use App\Domains\Financial\Models\{Bonus, BonusLedgerEntry, BonusBudgetCommitment, InstitutionalBonusBudget, InstitutionalBudgetOperation};
use App\Domains\Financial\Support\FinancialJobLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Release of untouched awards only. Consumption and merchant obligations remain disabled. */
final class InstitutionalBecaReleaseService
{
    public function __construct(private readonly InstitutionalBudgetAuthorization $authority,
        private readonly FinancialWebAuthorizer $access, private readonly InstitutionalBudgetService $budgets,
        private readonly FinancialJobLock $locks) {}

    public function cancel(string $actor, string $budgetId, string $bonusId, int $version, string $reason, string $key): array
    {
        return $this->release($actor, $budgetId, $bonusId, $version, $reason, $key, false);
    }

    public function expire(string $actor, string $budgetId, string $bonusId, int $version, string $reason, string $key): array
    {
        return $this->release($actor, $budgetId, $bonusId, $version, $reason, $key, true);
    }

    private function release(string $actor, string $budgetId, string $bonusId, int $version, string $reason, string $key, bool $expiry): array
    {
        if (!preg_match('/\A[0-9a-f]{24}\z/i', $actor)) throw new AuthorizationException();
        if (!Str::isUuid($budgetId) || !Str::isUuid($bonusId) || $version < 1
            || !preg_match('/\A[A-Za-z0-9._:-]{1,200}\z/', $key)
            || trim($reason) === '' || mb_strlen($reason) > 1000 || preg_match('/[\x00-\x1f\x7f]/', $reason)) {
            throw new InvalidArgumentException('Datos de liberacion no validos.');
        }
        $actor = strtolower($actor); $budgetId = strtolower($budgetId); $bonusId = strtolower($bonusId);
        $key = strtolower($key); $reason = trim($reason);
        $action = $expiry ? 'EXPIRE_BECA' : 'CANCEL_BECA';
        $target = $expiry ? BonusStatus::EXPIRADO : BonusStatus::CANCELADO;
        $movement = $expiry ? BonusMovementType::EXPIRACION : BonusMovementType::CANCELACION;
        if (!$this->authority->allows($actor, $expiry ? 'budget.expire' : 'budget.release', $budgetId)
            || (!$expiry && !$this->access->allows($actor, 'bonus.cancel'))) throw new AuthorizationException();
        $hash = hash('sha256', json_encode([$actor, $action, $budgetId, $bonusId, $version, $reason], JSON_THROW_ON_ERROR));
        $lockKey = 'institution-budget:'.hash('sha256', $key); $token = $this->locks->acquire($lockKey, 180);
        if (!$token) throw new ConflictHttpException('Solicitud presupuestaria en curso.');
        try {
            return DB::connection('sqlsrv')->transaction(function () use ($actor, $budgetId, $bonusId, $version, $reason,
                $key, $hash, $action, $target, $movement, $expiry, $lockKey, $token) {
                // Same order as issuance: budget before bonus. No external HTTP while holding these locks.
                $budget = InstitutionalBonusBudget::where('public_id', $budgetId)->lockForUpdate()->first();
                $bonus = Bonus::where('public_id', $bonusId)->lockForUpdate()->first();
                $link = BonusBudgetCommitment::where('bonus_id', $bonusId)->first();
                $issue = $link ? InstitutionalBudgetOperation::where('public_id', $link->issue_operation_id)->first() : null;
                if (!$budget || !$bonus || !$link || !$issue || $issue->action !== 'ISSUE_BECA'
                    || strtolower((string) $bonus->getRawOriginal('institutional_budget_id')) !== $budgetId
                    || strtolower($link->budget_id) !== $budgetId || strtolower($issue->budget_id) !== $budgetId
                    || strtolower((string) ($issue->after_data['bonus_id'] ?? '')) !== $bonusId
                    || strtolower((string) ($issue->after_data['commitment_id'] ?? '')) !== strtolower($link->public_id)
                    || ($issue->after_data['award_reference'] ?? null) !== $link->award_reference
                    || ($issue->after_data['committed_delta_cents'] ?? null) !== $link->amount_cents
                    || $bonus->type !== BonusType::BECA || $budget->bonus_type !== 'BECA'
                    || $bonus->currency !== 'MXN' || $budget->currency !== 'MXN' || $link->currency !== 'MXN'
                    || $link->status !== 'COMMITTED' || $link->amount_cents <= 0
                    || $link->amount_cents !== $bonus->original_amount_cents) {
                    throw new ConflictHttpException('La evidencia de emision requiere revision.');
                }
                $existing = InstitutionalBudgetOperation::where('idempotency_key', $key)->first();
                if ($existing) {
                    if (!hash_equals($existing->request_hash, $hash)) throw new ConflictHttpException('La llave pertenece a otra solicitud.');
                    $entries = BonusLedgerEntry::where('bonus_id', $bonusId)->where('reference_type', 'BUDGET_RELEASE')
                        ->where('reference_id', $existing->public_id)->get();
                    $entry = $entries->first();
                    if ($existing->action !== $action || strtolower($existing->budget_id) !== $budgetId
                        || strtolower((string) ($existing->after_data['bonus_id'] ?? '')) !== $bonusId
                        || ($existing->after_data['released_amount_cents'] ?? null) !== $link->amount_cents
                        || $bonus->status !== $target || $bonus->remaining_amount_cents !== 0
                        || $entries->count() !== 1 || !$entry || $entry->movement_type !== $movement
                        || $entry->amount_cents !== -$link->amount_cents || $entry->remaining_after_cents !== 0
                        || $entry->actor_id !== $actor || $entry->reason !== $reason
                        || BonusLedgerEntry::where('bonus_id', $bonusId)->count() !== 2
                        || BonusLedgerEntry::where('bonus_id', $bonusId)->where('movement_type', BonusMovementType::EMISION->value)
                            ->where('amount_cents', $link->amount_cents)->where('remaining_after_cents', $link->amount_cents)->count() !== 1) {
                        throw new ConflictHttpException('La evidencia de liberacion requiere revision.');
                    }
                    return ['operation' => $existing, 'bonus_id' => $bonusId, 'replayed' => true];
                }
                if ($budget->version !== $version || $budget->version === PHP_INT_MAX) throw new ConflictHttpException('Version presupuestaria no disponible.');
                if (!in_array($bonus->status, [BonusStatus::ACTIVO, BonusStatus::PENDIENTE], true)
                    || !$bonus->expires_at || !$bonus->valid_from
                    || ($expiry ? now()->lt($bonus->expires_at) : now()->gte($bonus->expires_at))) {
                    throw new ConflictHttpException('El estado o la vigencia no admiten esta liberacion.');
                }
                // No partial/legacy consumption is accepted until its budget and obligation lifecycle exists.
                $entries = BonusLedgerEntry::where('bonus_id', $bonusId)->get(); $initial = $entries->first();
                if ($bonus->remaining_amount_cents !== $link->amount_cents || $entries->count() !== 1 || !$initial
                    || $initial->movement_type !== BonusMovementType::EMISION || $initial->amount_cents !== $link->amount_cents
                    || $initial->remaining_after_cents !== $link->amount_cents
                    || $budget->committed_amount_cents < $link->amount_cents) {
                    throw new ConflictHttpException('El bono tiene consumo o evidencia inconsistente; requiere revision.');
                }
                $before = [...$this->budgets->snapshot($budget), 'bonus_id' => $bonusId,
                    'bonus_status' => $bonus->status->value, 'bonus_remaining_cents' => $bonus->remaining_amount_cents];
                $budget->committed_amount_cents -= $link->amount_cents; ++$budget->version; $budget->save();
                $bonus->remaining_amount_cents = 0; $bonus->status = $target; $bonus->save();
                $this->locks->assertOwned($lockKey, $token, 180);
                $operation = InstitutionalBudgetOperation::create([
                    'public_id' => (string) Str::uuid(), 'budget_id' => $budget->public_id, 'action' => $action,
                    'actor_id' => $actor, 'reason' => $reason, 'idempotency_key' => $key, 'request_hash' => $hash,
                    'before_data' => $before, 'after_data' => [...$this->budgets->snapshot($budget),
                        'bonus_id' => $bonusId, 'bonus_status' => $target->value, 'bonus_remaining_cents' => 0,
                        'commitment_id' => strtolower($link->public_id), 'released_amount_cents' => $link->amount_cents], 'created_at' => now(),
                ]);
                BonusLedgerEntry::create(['public_id' => (string) Str::uuid(), 'bonus_id' => $bonus->public_id,
                    'movement_type' => $movement, 'amount_cents' => -$link->amount_cents, 'remaining_after_cents' => 0,
                    'reference_type' => 'BUDGET_RELEASE', 'reference_id' => $operation->public_id, 'actor_id' => $actor, 'reason' => $reason]);
                return ['operation' => $operation, 'bonus_id' => $bonusId, 'replayed' => false];
            });
        } catch (QueryException $e) {
            if (in_array((int) ($e->errorInfo[1] ?? 0), [2601, 2627], true)) throw new ConflictHttpException('La llave ya tiene evidencia registrada.');
            throw $e;
        } finally { $this->locks->release($lockKey, $token); }
    }
}
