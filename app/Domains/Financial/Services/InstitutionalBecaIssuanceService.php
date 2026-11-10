<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Contracts\{InstitutionalBudgetAuthorization, FinancialWebAuthorizer, BonusAuthorizationProvider};
use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Models\{InstitutionalBonusBudget, InstitutionalBudgetOperation, BonusBudgetCommitment, Bonus, BonusAdministrativeOperation};
use App\Domains\Financial\Support\{BonusIssuanceInput, FinancialJobLock};
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Atomic new BECA issuance only; no historical attachment, consumption or settlement. */
final class InstitutionalBecaIssuanceService
{
    public function __construct(private readonly InstitutionalBudgetAuthorization $authority,
        private readonly FinancialWebAuthorizer $access, private readonly BonusAuthorizationProvider $issuers,
        private readonly BonusAdministrationService $bonuses, private readonly InstitutionalBudgetService $budgets,
        private readonly FinancialJobLock $locks) {}

    public function issue(string $actor, string $budgetId, int $expectedVersion, array $data, string $key, ?array $audit = null, ?string $communityFingerprint = null, ?string $treasuryActor = null): array
    {
        if (!preg_match('/\A[0-9a-f]{24}\z/i', $actor)) throw new AuthorizationException();
        $actor = strtolower($actor);
        if (!Str::isUuid($budgetId) || $expectedVersion < 1) throw new InvalidArgumentException('Presupuesto o version no validos.');
        $budgetId = strtolower($budgetId);
        if ($treasuryActor !== null && ($communityFingerprint === null || !preg_match('/\A[0-9a-f]{24}\z/', $treasuryActor))) {
            throw new InvalidArgumentException('El aval de Tesorería requiere una referencia de Comunidad.');
        }
        $budgetActor = $treasuryActor ?? $actor;
        if (!$this->authority->allows($budgetActor, 'budget.commit', $budgetId)
            || !$this->access->allows($actor, 'bonus.issue')) throw new AuthorizationException();
        $award = validator($data, ['award_reference' => ['required', 'string', 'max:150', 'regex:/\S/u']])->validate()['award_reference'];
        $award = trim($award);
        if (preg_match('/[\x00-\x1f\x7f]/', $award)) throw new InvalidArgumentException('Referencia de otorgamiento no valida.');
        $data = BonusIssuanceInput::validate($data);
        if ($data['type'] !== 'BECA') throw new InvalidArgumentException('Este flujo solo admite BECA.');
        if (!is_int($data['amount_cents'])) throw new InvalidArgumentException('El monto debe ser un entero en centavos.');
        if (strtolower($data['beneficiary_id']) === $actor) throw new InvalidArgumentException('El emisor no puede asignarse una BECA.');
        if (!preg_match('/\A[A-Za-z0-9._:-]{1,200}\z/', $key)) throw new InvalidArgumentException('Llave de idempotencia no valida.');
        $key = strtolower($key);
        if ($communityFingerprint !== null || str_starts_with(strtolower($award), 'beca:') || str_starts_with($key, 'beca:')) {
            if ($treasuryActor === null || ! is_string($communityFingerprint) || ! preg_match('/\A[0-9a-f]{64}\z/', $communityFingerprint)
                || ! preg_match('/\Abeca:[0-9a-f]{24}\z/', $award) || $key !== $award || ($data['external_reference'] ?? null) !== $award) {
                throw new InvalidArgumentException('La referencia de Comunidad requiere su flujo de emisión.');
            }
        }
        $start = CarbonImmutable::parse($data['valid_from'])->utc(); $end = CarbonImmutable::parse($data['expires_at'])->utc();
        if ($end->lte($start)) throw new InvalidArgumentException('La vigencia del bono no es valida.');
        $restrictions = collect($data['restrictions'] ?? [])->map(fn ($r) => ['type' => $r['type'], 'target_id' => trim($r['target_id'])])
            ->sortBy(fn ($r) => $r['type'].':'.$r['target_id'])->values()->all();
        $payload = ['beneficiary_id' => strtolower($data['beneficiary_id']), 'type' => 'BECA', 'amount_cents' => $data['amount_cents'],
            'valid_from' => $start->toISOString(), 'expires_at' => $end->toISOString(),
            'combinable' => (bool) $data['combinable'], 'allows_partial_use' => (bool) $data['allows_partial_use'],
            'external_reference' => trim((string) ($data['external_reference'] ?? '')) ?: null,
            'reason' => trim($data['reason']), 'restrictions' => $restrictions];
        $evidence = $communityFingerprint === null ? [] : [
            'community_award_fingerprint' => $communityFingerprint,
            'community_treasury_actor_id' => $treasuryActor,
        ];
        $hash = hash('sha256', json_encode([$actor, 'ISSUE_BECA', $budgetId, $expectedVersion, $award, $payload, ...$evidence], JSON_THROW_ON_ERROR));
        // Same namespace as budget administration: a key cannot race across two budget actions.
        $lockKey = 'institution-budget:'.hash('sha256', $key); $token = $this->locks->acquire($lockKey, 180);
        if (!$token) throw new ConflictHttpException('Solicitud presupuestaria en curso.');
        try {
            return DB::connection('sqlsrv')->transaction(function () use ($actor, $budgetActor, $budgetId, $expectedVersion, $key, $payload,
                $hash, $start, $end, $audit, $lockKey, $token, $award, $evidence) {
                $budget = InstitutionalBonusBudget::where('public_id', $budgetId)->lockForUpdate()->first();
                if (!$budget) throw new ConflictHttpException('Presupuesto no disponible.');
                if (!$this->authority->allows($budgetActor,'budget.commit',$budgetId)
                    || !$this->access->allows($actor,'bonus.issue')) throw new AuthorizationException();
                app(InstitutionalBudgetIntegrity::class)->assertState($budget);
                $existing = InstitutionalBudgetOperation::where('idempotency_key', $key)->first();
                if ($existing) {
                    if (!hash_equals($existing->request_hash, $hash)) throw new ConflictHttpException('La llave ya corresponde a otra solicitud.');
                    if (!$this->issuers->canIssueBonus('USER', $actor, BonusType::BECA)) throw new AuthorizationException();
                    $link = BonusBudgetCommitment::where('issue_operation_id', $existing->public_id)->first();
                    $bonus = $link ? Bonus::where('public_id', $link->bonus_id)->first() : null;
                    if (!$link || !$bonus || strtolower($link->budget_id) !== $budgetId
                        || strtolower((string) $bonus->getRawOriginal('institutional_budget_id')) !== $budgetId
                        || $link->amount_cents !== $payload['amount_cents'] || $link->award_reference !== $award
                        || $bonus->original_amount_cents !== $payload['amount_cents'] || $bonus->currency !== 'MXN'
                        || $link->currency !== 'MXN' || $bonus->type !== BonusType::BECA
                        || $bonus->issuer_type !== 'USER' || strtolower($bonus->issuer_id) !== $actor
                        || strtolower($bonus->beneficiary_id) !== $payload['beneficiary_id']) {
                        throw new ConflictHttpException('La evidencia del compromiso requiere revision.');
                    }
                    return ['operation' => $existing, 'bonus_id' => strtolower($bonus->public_id), 'replayed' => true];
                }
                // Never attach a previous administrative emission, even with the same request key.
                if (BonusAdministrativeOperation::where('idempotency_key', $key)->exists()) {
                    throw new ConflictHttpException('La llave ya pertenece a una emision existente.');
                }
                if ($budget->version !== $expectedVersion) throw new ConflictHttpException('Presupuesto o version no disponibles.');
                if ($budget->status !== 'ACTIVE' || $budget->bonus_type !== 'BECA' || $budget->currency !== 'MXN'
                    || now()->lt($budget->valid_from) || now()->gte($budget->expires_at)) {
                    throw new ConflictHttpException('El presupuesto no esta habilitado para esta emision.');
                }
                if ($start->lt($budget->valid_from) || $end->gt($budget->expires_at) || $end->lte(now())) {
                    throw new ConflictHttpException('La vigencia del bono debe estar dentro del presupuesto.');
                }
                $before = $this->budgets->snapshot($budget);
                if ($payload['amount_cents'] > $before['available_amount_cents']) throw new ConflictHttpException('Presupuesto insuficiente.');
                if ($budget->version === PHP_INT_MAX) throw new ConflictHttpException('No puede incrementarse la version.');
                if (BonusBudgetCommitment::where('award_reference', $award)->exists()) {
                    throw new ConflictHttpException('El otorgamiento ya tiene una emision presupuestaria.');
                }
                // Existing issuance keeps its own web/issuer/beneficiary checks and SQL audit.
                $result = $this->bonuses->issue($actor, $payload, $key,
                    [...($audit ?? []), 'institutional_budget_id' => $budgetId, 'budget_review_version' => $expectedVersion]);
                if ($result['replayed']) throw new ConflictHttpException('No se permite financiar una emision anterior.');
                $bonusId = $result['operation']->bonus_id;
                $budget->committed_amount_cents += $payload['amount_cents']; ++$budget->version; $budget->save();
                $commitmentId = (string) Str::uuid();
                $this->locks->assertOwned($lockKey, $token, 180);
                $operation = InstitutionalBudgetOperation::create([
                    'public_id' => (string) Str::uuid(), 'budget_id' => $budget->public_id, 'action' => 'ISSUE_BECA',
                    'actor_id' => $actor, 'reason' => $payload['reason'], 'idempotency_key' => $key,
                    'request_hash' => $hash, 'before_data' => $before,
                    'after_data' => [...$this->budgets->snapshot($budget->fresh()), 'commitment_id' => $commitmentId,
                        'bonus_id' => strtolower($bonusId), 'award_reference' => $award, 'committed_delta_cents' => $payload['amount_cents'],
                        ...$evidence], 'created_at' => now(),
                ]);
                BonusBudgetCommitment::create(['public_id' => $commitmentId, 'budget_id' => $budget->public_id,
                    'award_reference' => $award, 'bonus_id' => $bonusId, 'issue_operation_id' => $operation->public_id,
                    'amount_cents' => $payload['amount_cents'], 'currency' => 'MXN', 'status' => 'COMMITTED']);
                // Initial immutable binding only. No existing/historical bonus is eligible for this branch.
                $bound = DB::connection('sqlsrv')->table('bonuses')->where('public_id', $bonusId)
                    ->whereNull('institutional_budget_id')->update(['institutional_budget_id' => $budget->public_id]);
                if ($bound !== 1) throw new ConflictHttpException('No pudo registrarse el vinculo presupuestario.');
                app(InstitutionalBudgetIntegrity::class)->assertState($budget);
                $this->locks->assertOwned($lockKey, $token, 180);
                return ['operation' => $operation, 'bonus_id' => strtolower($bonusId), 'replayed' => false];
            });
        } catch (QueryException $e) {
            if (in_array((int) ($e->errorInfo[1] ?? 0), [2601, 2627], true)) {
                throw new ConflictHttpException('La solicitud, bono u otorgamiento ya tiene evidencia registrada.');
            }
            throw $e;
        } finally { $this->locks->release($lockKey, $token); }
    }
}
