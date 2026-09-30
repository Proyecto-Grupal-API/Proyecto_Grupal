<?php

namespace App\Services;

use App\Models\AuditCorrelation;
use App\Models\AuditLog;
use App\Models\EarningRule;
use App\Models\FraudFlag;
use App\Models\PointCampaign;
use App\Models\PointsAccount;
use App\Models\PointsLedger;
use Illuminate\Support\Str;
use App\Services\PointsLedger\DTO\EarnPointsRequest;
use App\Services\PointsLedger\DTO\RedeemPointsRequest;
use App\Services\PointsLedger\DTO\ReversePointsRequest;
use App\Services\PointsLedger\Exception\DailyCapExceededException;
use App\Services\PointsLedger\Exception\InsufficientPointsException;
use App\Services\PointsLedger\Exception\LedgerEntryNotFoundException;
use App\Services\PointsLedger\Exception\NoActiveEarningRuleException;
use App\Services\PointsLedger\Result\LedgerOperationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * NOTA IMPORTANTE SOBRE TRANSACCIONES:
 * DB::transaction() en MongoDB solo funciona si el servidor está configurado como
 * replica set (aunque sea de un solo nodo, "rs0"). Un MongoDB standalone no soporta
 * transacciones multi-documento y estas llamadas fallarán en silencio o lanzarán
 * excepción según el driver. Si el docker-compose actual levanta mongodb en modo
 * standalone, hay que inicializarlo como replica set de un nodo antes de usar este
 * servicio en producción/pruebas de integración.
 *
 * NOTA SOBRE AuditLog / AuditCorrelation:
 * logAudit() usa los campos reales de App\Models\AuditLog. `correlation_id` es una FK
 * real hacia App\Models\AuditCorrelation (belongsTo), así que cada operación pública
 * (earn/redeem/reverse) crea su propio AuditCorrelation vía ensureCorrelation() antes
 * de escribir el AuditLog — así una entrada de auditoría siempre puede rastrear qué
 * otras entidades tocó esa operación (ledger, cuenta, fraud flag).
 * `actor_role`, `ip_address`, `device` siguen sin llenarse desde aquí porque este
 * servicio no tiene contexto de request/actor real — hay que decidir si se le pasan
 * como parámetros desde el controller/job que invoque al servicio (por ejemplo, cuando
 * un agente humano dispara un ajuste administrativo en vez de un evento automático).
 */
final class PointsLedgerService implements PointsLedgerServiceInterface
{
    public function earn(EarnPointsRequest $request): LedgerOperationResult
    {
        if ($replay = $this->findReplay($request->idempotencyKey, $request->studentId)) {
            return $replay;
        }

        $rule = EarningRule::where('business_id', $request->businessId)->get()
            ->first(fn (EarningRule $r) => $r->isActiveOn(Carbon::now()));

        if (! $rule) {
            throw NoActiveEarningRuleException::forBusiness($request->businessId);
        }

        $basePoints = $this->calculateBasePoints($rule, $request->saleAmount);
        $withMultiplier = (int) floor($basePoints * ($rule->multiplier ?? 1.0));
        $withCampaign = $this->applyCampaignMultiplier($withMultiplier, $request->businessId, $request->campaignId);

        $points = $rule->max_points_per_operation
            ? min($withCampaign, $rule->max_points_per_operation)
            : $withCampaign;

        $points = $this->applyDailyCap($request->studentId, $rule, $points);

        return DB::transaction(function () use ($request, $rule, $points) {
            $ledgerEntry = PointsLedger::create([
                'student_id' => $request->studentId,
                'idempotency_key' => $request->idempotencyKey,
                'type' => 'earn',
                'amount' => $points,
                'source_domain' => $request->sourceDomain,
                'source_reference' => $request->sourceReference,
                'related_ledger_id' => null,
                'rule_id' => (string) $rule->_id,
                'business_id' => $request->businessId,
                'description' => "Acumulación de puntos por venta {$request->sourceReference}",
                'metadata' => $request->metadata,
            ]);

            $account = $this->accountFor($request->studentId);
            $account->increment('balance', $points);
            $account->increment('lifetime_earned', $points);
            $account->update(['last_ledger_entry_id' => (string) $ledgerEntry->_id]);

            $correlationId = $this->ensureCorrelation(
                originDomain: $request->sourceDomain,
                description: "Acumulación de puntos por venta {$request->sourceReference}",
                relatedEntities: [
                    'points_ledger' => (string) $ledgerEntry->_id,
                    'points_account' => (string) $account->_id,
                    'business_id' => $request->businessId,
                ],
            );

            $this->logAudit(
                action: 'points.earned',
                entityType: 'PointsLedger',
                entityId: (string) $ledgerEntry->_id,
                studentId: $request->studentId,
                after: ['amount' => $points, 'business_id' => $request->businessId],
                sourceDomain: $request->sourceDomain,
                correlationId: $correlationId,
            );

            return new LedgerOperationResult($ledgerEntry, $account->fresh());
        });
    }

    public function redeem(RedeemPointsRequest $request): LedgerOperationResult
    {
        if ($replay = $this->findReplay($request->idempotencyKey, $request->studentId)) {
            return $replay;
        }

        $available = $this->getAvailableBalance($request->studentId);

        if ($available < $request->pointsRequired) {
            throw InsufficientPointsException::forStudent($request->studentId, $available, $request->pointsRequired);
        }

        return DB::transaction(function () use ($request) {
            $ledgerEntry = PointsLedger::create([
                'student_id' => $request->studentId,
                'idempotency_key' => $request->idempotencyKey,
                'type' => 'redeem',
                'amount' => -$request->pointsRequired,
                'source_domain' => 'rewards',
                'source_reference' => $request->redemptionId,
                'related_ledger_id' => null,
                'rule_id' => null,
                'business_id' => null,
                'description' => "Canje {$request->redemptionId}",
                'metadata' => $request->metadata,
            ]);

            $account = $this->accountFor($request->studentId);
            $account->decrement('balance', $request->pointsRequired);
            $account->increment('lifetime_redeemed', $request->pointsRequired);
            $account->update(['last_ledger_entry_id' => (string) $ledgerEntry->_id]);

            $correlationId = $this->ensureCorrelation(
                originDomain: 'rewards',
                description: "Canje {$request->redemptionId}",
                relatedEntities: [
                    'points_ledger' => (string) $ledgerEntry->_id,
                    'points_account' => (string) $account->_id,
                    'redemption_id' => $request->redemptionId,
                ],
            );

            $this->logAudit(
                action: 'points.redeemed',
                entityType: 'PointsLedger',
                entityId: (string) $ledgerEntry->_id,
                studentId: $request->studentId,
                after: ['amount' => -$request->pointsRequired, 'redemption_id' => $request->redemptionId],
                sourceDomain: 'rewards',
                correlationId: $correlationId,
            );

            return new LedgerOperationResult($ledgerEntry, $account->fresh());
        });
    }

    public function reverse(ReversePointsRequest $request): LedgerOperationResult
    {
        if ($replay = $this->findReplay($request->idempotencyKey, null)) {
            return $replay;
        }

        $original = PointsLedger::find($request->relatedLedgerId);

        if (! $original) {
            throw LedgerEntryNotFoundException::forId($request->relatedLedgerId);
        }

        $studentId = $original->student_id;
        $reversalAmount = -$original->amount; // signo opuesto al movimiento original

        return DB::transaction(function () use ($request, $original, $studentId, $reversalAmount) {
            // IMPORTANTE: el disponible se calcula ANTES de crear el ledger entry de
            // reverso. Si se calculara después, la suma del ledger ya incluiría el propio
            // movimiento que se está creando y el reverso terminaría autocancelándose en
            // el cálculo de shortfall.
            $availableBeforeReversal = $reversalAmount < 0 ? $this->getAvailableBalance($studentId) : null;

            // El movimiento de reverso SIEMPRE se registra completo, sin editar ni
            // borrar el original. Esto es lo que exige la regla de append-only.
            $ledgerEntry = PointsLedger::create([
                'student_id' => $studentId,
                'idempotency_key' => $request->idempotencyKey,
                'type' => 'reverse',
                'amount' => $reversalAmount,
                'source_domain' => $original->source_domain,
                'source_reference' => $original->source_reference,
                'related_ledger_id' => (string) $original->_id,
                'rule_id' => $original->rule_id,
                'business_id' => $original->business_id,
                'description' => "Reverso de {$original->_id}: {$request->reason}",
                'metadata' => $request->metadata,
            ]);

            $account = $this->accountFor($studentId);
            $shortfall = 0;

            if ($reversalAmount < 0) {
                // Se están retirando puntos previamente ganados. Si el saldo actual no
                // alcanza (porque ya se canjearon), solo se retira lo disponible y el
                // resto queda como deuda pendiente en pending_balance.
                $toRemove = abs($reversalAmount);
                $shortfall = max(0, $toRemove - $availableBeforeReversal);
                $actuallyRemoved = $toRemove - $shortfall;

                $account->decrement('balance', $actuallyRemoved);

                if ($shortfall > 0) {
                    $account->increment('pending_balance', $shortfall);

                    $fraudFlag = FraudFlag::create([
                        'student_id' => $studentId,
                        'business_id' => $original->business_id,
                        'reason' => 'points_reversal_shortfall',
                        'severity' => 'medium', // TODO: definir escala de severidad con Gobierno de Puntos (módulo 6)
                        'related_ledger_id' => (string) $ledgerEntry->_id,
                        'status' => 'open',
                        // El modelo no tiene un campo numérico para el monto: FraudFlag no trae
                        // "amount" en su $fillable, así que el shortfall solo queda legible en texto.
                        // Si Gobierno de Puntos necesita reportarlo/filtrarlo de forma estructurada,
                        // hay que agregar esa columna al modelo/migración.
                        'notes' => "Reverso de {$original->_id} no se pudo cubrir completo: "
                            . "faltaron {$shortfall} pts (ya habían sido canjeados).",
                    ]);
                }
            } else {
                // Se están devolviendo puntos de un redeem revertido: crédito directo, sin riesgo de faltante.
                $account->increment('balance', $reversalAmount);
            }

            $account->update(['last_ledger_entry_id' => (string) $ledgerEntry->_id]);

            $correlationId = $this->ensureCorrelation(
                originDomain: $original->source_domain,
                description: "Reverso de {$original->_id}: {$request->reason}",
                relatedEntities: array_filter([
                    'original_ledger' => (string) $original->_id,
                    'reversal_ledger' => (string) $ledgerEntry->_id,
                    'points_account' => (string) $account->_id,
                    'fraud_flag' => isset($fraudFlag) ? (string) $fraudFlag->_id : null,
                ]),
            );

            $this->logAudit(
                action: $shortfall > 0 ? 'points.reversed.partial_shortfall' : 'points.reversed',
                entityType: 'PointsLedger',
                entityId: (string) $ledgerEntry->_id,
                studentId: $studentId,
                before: ['original_ledger_id' => (string) $original->_id, 'original_amount' => $original->amount],
                after: ['reversal_amount' => $reversalAmount, 'shortfall' => $shortfall],
                sourceDomain: $original->source_domain,
                reason: $request->reason,
                correlationId: $correlationId,
            );

            return new LedgerOperationResult($ledgerEntry, $account->fresh(), shortfallAmount: $shortfall);
        });
    }

    public function reconcileAccount(string $studentId): PointsAccount
    {
        $account = $this->accountFor($studentId);
        $recalculatedBalance = $account->reconcileFromLedger(); // el modelo solo calcula, no persiste
        $account->update(['balance' => $recalculatedBalance]);

        return $account->fresh();
    }

    public function getAvailableBalance(string $studentId): int
    {
        return (int) PointsLedger::where('student_id', $studentId)->sum('amount');
    }

    // ------------------------------------------------------------------
    // Helpers privados
    // ------------------------------------------------------------------

    private function accountFor(string $studentId): PointsAccount
    {
        return PointsAccount::firstOrCreate(
            ['student_id' => $studentId],
            ['balance' => 0, 'pending_balance' => 0, 'lifetime_earned' => 0, 'lifetime_redeemed' => 0]
        );
    }

    /**
     * Si ya existe un ledger entry con esta idempotency_key, la operación es un reintento:
     * se devuelve el resultado existente en vez de duplicar efectos.
     */
    private function findReplay(string $idempotencyKey, ?string $studentId): ?LedgerOperationResult
    {
        $existing = PointsLedger::where('idempotency_key', $idempotencyKey)->first();

        if (! $existing) {
            return null;
        }

        $account = $this->accountFor($studentId ?? $existing->student_id);

        return new LedgerOperationResult($existing, $account, wasIdempotentReplay: true);
    }

    private function calculateBasePoints(EarningRule $rule, float $saleAmount): int
    {
        if ($rule->fixed_points) {
            return (int) $rule->fixed_points;
        }

        if (! $rule->unit_amount || $rule->unit_amount <= 0) {
            return 0;
        }

        return (int) floor(($saleAmount / $rule->unit_amount) * $rule->points_per_unit);
    }

    /**
     * $campaign->scope define a quién aplica: 'global' (todos los negocios) o 'business'
     * (solo los negocios listados en scope_ids). No hay un método isActiveOn() en el
     * modelo real, así que la vigencia se valida aquí mismo con starts_at/ends_at/status.
     */
    private function isCampaignApplicable(PointCampaign $campaign, string $businessId, \DateTimeInterface $date): bool
    {
        if ($campaign->status !== 'active') {
            return false;
        }

        if ($campaign->starts_at && $date < $campaign->starts_at) {
            return false;
        }

        if ($campaign->ends_at && $date > $campaign->ends_at) {
            return false;
        }

        return match ($campaign->scope) {
            'global' => true,
            'business' => in_array($businessId, $campaign->scope_ids ?? [], true),
            default => false,
        };
    }

    private function applyCampaignMultiplier(int $points, string $businessId, ?string $campaignId): int
    {
        $now = Carbon::now();

        $campaign = $campaignId
            ? PointCampaign::find($campaignId)
            : PointCampaign::where('status', 'active')->get()
                ->first(fn (PointCampaign $c) => $this->isCampaignApplicable($c, $businessId, $now));

        if (! $campaign || ! $this->isCampaignApplicable($campaign, $businessId, $now) || ! ($campaign->multiplier ?? null)) {
            return $points;
        }

        return (int) floor($points * $campaign->multiplier);
    }

    /**
     * Recorta $points para no exceder el daily_cap de la regla. Si el tope ya está
     * agotado (remaining <= 0), lanza excepción en vez de otorgar 0 silenciosamente.
     */
    private function applyDailyCap(string $studentId, EarningRule $rule, int $points): int
    {
        if (! $rule->daily_cap) {
            return $points;
        }

        $startOfDay = Carbon::now()->startOfDay();

        $earnedToday = (int) PointsLedger::where('student_id', $studentId)
            ->where('rule_id', (string) $rule->_id)
            ->where('type', 'earn')
            ->where('created_at', '>=', $startOfDay)
            ->sum('amount');

        $remaining = $rule->daily_cap - $earnedToday;

        if ($remaining <= 0) {
            throw DailyCapExceededException::forStudentAndRule($studentId, (string) $rule->_id, $rule->daily_cap);
        }

        return min($points, $remaining);
    }

    /**
     * Crea un AuditCorrelation nuevo para agrupar las entidades que tocó una sola
     * operación de negocio (ledger, cuenta, fraud flag, etc.) bajo un mismo
     * correlation_id, y devuelve ese id para pasarlo a logAudit().
     *
     * Nota: cada llamada crea una correlación nueva (no reutiliza una existente).
     * Si más adelante Equipo 2/3 empiezan a mandar su propio correlation_id en el
     * AnalyticsEvent original (por ejemplo, el mismo id que usaron para su propio
     * AuditLog al confirmar la venta), convendría aceptarlo como parámetro opcional
     * aquí y reutilizarlo en vez de generar uno nuevo, para poder rastrear la cadena
     * completa "venta -> puntos -> auditoría" con un solo id.
     */
    private function ensureCorrelation(string $originDomain, string $description, array $relatedEntities): string
    {
        $correlationId = (string) Str::uuid();

        AuditCorrelation::create([
            'correlation_id' => $correlationId,
            'origin_domain' => $originDomain,
            'description' => $description,
            'related_entities' => $relatedEntities,
        ]);

        return $correlationId;
    }

    private function logAudit(
        string $action,
        string $entityType,
        string $entityId,
        string $studentId,
        array $before = [],
        array $after = [],
        ?string $actorId = null,
        ?string $actorRole = null,
        ?string $sourceDomain = null,
        ?string $reason = null,
        ?string $correlationId = null,
    ): void {
        AuditLog::create([
            'actor_id' => $actorId ?? $studentId, // TODO: reemplazar por el actor real (agente/admin) cuando el caller lo tenga
            'actor_role' => $actorRole, // TODO: 'student'|'agent'|'admin'|'system' — pasar desde el controller/job
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => request()?->ip(), // null fuera de contexto HTTP (jobs, consola, webhooks)
            'device' => request()?->userAgent(),
            'correlation_id' => $correlationId,
            'source_domain' => $sourceDomain,
            'reason' => $reason,
        ]);
    }
}