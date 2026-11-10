<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Contracts\BonusAuthorizationProvider;
use App\Domains\Financial\Contracts\FinancialWebAuthorizer;
use App\Domains\Financial\Enums\BonusRestrictionType;
use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Models\Bonus;
use App\Domains\Financial\Models\BonusAdministrativeOperation;
use App\Domains\Financial\Support\BonusIssuanceInput;
use App\Domains\Financial\Support\FinancialJobLock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Atomic audited web boundary, while preserving the existing bonus domain service. */
class BonusAdministrationService
{
    public function __construct(private readonly BonusService $bonuses, private readonly FinancialWebAuthorizer $access,
        private readonly BonusAuthorizationProvider $issuers, private readonly FinancialJobLock $locks,
        private readonly \App\Services\CouncilBecaWebAuthorization $councilWeb) {}

    public function issue(string $userId, array $data, string $key, ?array $audit = null, ?\App\Domains\Financial\Data\BonusIssuerContext $trustedContext = null, ?string $trustedBudget = null, ?string $trustedTreasury = null): array
    {
        if ($trustedContext === null) $this->authorize($userId, 'bonus.issue');
        $data = BonusIssuanceInput::validate($data);
        $this->validateKeyAndReason($key, $data['reason']);
        $type = BonusType::from($data['type']);
        $context = $trustedContext;
        if ($context !== null && ($type !== BonusType::BECA || $context->scopeType !== 'council'
            || ! preg_match('/\A[0-9a-f]{24}\z/', (string) $context->scopeId))) {
            throw new InvalidArgumentException('Sólo el flujo interno BECA admite el Consejo acreditado.');
        }
        if ($context !== null) $this->authorizeCouncil($userId, $context, $trustedBudget, $trustedTreasury);
        $start = CarbonImmutable::parse($data['valid_from'])->setTimezone(config('app.timezone', 'UTC'));
        $end = CarbonImmutable::parse($data['expires_at'])->setTimezone(config('app.timezone', 'UTC'));
        $restrictions = collect($data['restrictions'] ?? [])->map(fn ($row) => [
            'type' => $row['type'], 'target_id' => trim($row['target_id']),
        ])->sortBy(fn ($row) => $row['type'].':'.$row['target_id'])->values()->all();
        $payload = ['beneficiary_id' => strtolower(trim($data['beneficiary_id'])), 'type' => $type->value,
            'amount_cents' => (int) $data['amount_cents'], 'valid_from' => $start->utc()->format('Y-m-d\TH:i:s.uP'),
            'expires_at' => $end->utc()->format('Y-m-d\TH:i:s.uP'), 'combinable' => (bool) ($data['combinable'] ?? false),
            'allows_partial_use' => (bool) ($data['allows_partial_use'] ?? false),
            'external_reference' => trim((string) ($data['external_reference'] ?? '')) ?: null,
            'restrictions' => $restrictions, 'reason' => trim($data['reason']), ...($context?->toArray() ?? [])];

        $this->validateEmission($userId, $payload, $type, $start, $end);
        if (! ($context === null ? $this->issuers->canIssueBonus('USER', $userId, $type)
            : ($this->issuers instanceof \App\Domains\Financial\Contracts\ContextualBonusAuthorizationProvider
                && $this->issuers->canIssueInContext('USER', $userId, $type, $context)))) {
            throw new AuthorizationException('El emisor no tiene autorización para este tipo de bono.');
        }

        return $this->run($userId, 'ISSUE', $payload, $key, function () use ($userId, $payload, $type, $start, $end, $context, $trustedBudget, $trustedTreasury) {
            if ($context !== null) $this->authorizeCouncil($userId, $context, $trustedBudget, $trustedTreasury);
            $this->validateEmission($userId, $payload, $type, $start, $end);
            if ($context?->scopeType === 'business') {
                $businesses = array_column(array_filter($payload['restrictions'], fn ($r) => $r['type'] === 'NEGOCIO'), 'target_id');
                if ($businesses !== [$context->scopeId]) {
                    throw new InvalidArgumentException('Una emisión de negocio debe restringirse únicamente al negocio autorizado.');
                }
            }
            $bonus = $this->bonuses->issue('USER', $payload['beneficiary_id'], 'USER', $userId, $type,
                $payload['amount_cents'], $start, $end, $payload['combinable'], $payload['allows_partial_use'], $payload['external_reference'], $context);
            foreach ($payload['restrictions'] as $restriction) {
                $this->bonuses->addRestriction($bonus->public_id, BonusRestrictionType::from($restriction['type']), $restriction['target_id']);
            }
            // Initial issuance entry receives the administrator's actual reason atomically.
            $bonus->ledgerEntries()->where('movement_type', 'EMISION')->update(['reason' => $payload['reason'], 'actor_id' => 'user:'.$userId]);

            return [$bonus->fresh(), null];
        }, $audit);
    }

    public function cancel(string $userId, Bonus $bonus, string $reason, string $key, ?array $audit = null): array
    {
        $this->authorize($userId, 'bonus.cancel');
        if (! $this->isIssuer($userId, $bonus) && ! $this->access->allows($userId, 'bonus.cancel.all')) {
            throw new AuthorizationException;
        }

        return $this->run($userId, 'CANCEL', ['bonus_id' => strtolower($bonus->public_id), 'reason' => trim($reason)], $key,
            function () use ($userId, $bonus, $reason) {
                $locked = Bonus::where('public_id', $bonus->public_id)->lockForUpdate()->firstOrFail();
                if (! $this->isIssuer($userId, $locked) && ! $this->access->allows($userId, 'bonus.cancel.all')) {
                    throw new AuthorizationException;
                }
                $before = $this->snapshot($locked);

                return [$this->bonuses->cancel($locked->public_id, 'user:'.$userId, trim($reason)), $before];
            }, $audit);
    }

    private function validateEmission(string $userId, array $payload, BonusType $type, CarbonImmutable $start, CarbonImmutable $end): void
    {
        if (strtolower($userId) === $payload['beneficiary_id']) {
            throw new InvalidArgumentException('El emisor no puede asignarse un bono a sí mismo.');
        }
        $beneficiary = User::find($payload['beneficiary_id']);
        if (! $beneficiary || $beneficiary->deleted_at || $beneficiary->account_activation_pending || $beneficiary->must_change_password || ! $beneficiary->hasVerifiedEmail()) {
            throw new InvalidArgumentException('El beneficiario no existe o no está activo y verificado.');
        }
        if ($end->lte($start) || $end->lte(now())) {
            throw new InvalidArgumentException('La vigencia debe terminar después de su inicio y en una fecha futura.');
        }
        if (count(array_unique(array_map(fn ($row) => $row['type'].':'.$row['target_id'], $payload['restrictions'])))
            !== count($payload['restrictions'])) {
            throw new InvalidArgumentException('No se permiten restricciones duplicadas.');
        }
        if (in_array($type, [BonusType::NEGOCIO, BonusType::CATEGORIA], true)
            && ! in_array($type->value, array_column($payload['restrictions'], 'type'), true)) {
            throw new InvalidArgumentException('Este tipo de bono requiere al menos una restricción del mismo tipo.');
        }
    }

    private function validateKeyAndReason(string $key, string $reason): void
    {
        $key = trim($key);
        if (! preg_match('/\A[A-Za-z0-9._:-]+\z/', $key) || strlen($key) > 255 || trim($reason) === '') {
            throw new InvalidArgumentException('La llave de idempotencia y el motivo son obligatorios.');
        }
    }

    private function run(string $userId, string $action, array $payload, string $key, callable $callback, ?array $audit = null): array
    {
        $key = strtolower(trim($key));
        $this->validateKeyAndReason($key, $payload['reason'] ?? '');
        $actor = 'user:'.$userId;
        $hash = hash('sha256', json_encode([$actor, $action, $payload], JSON_THROW_ON_ERROR));
        $lockKey = 'bonus-admin:'.hash('sha256', $key);
        $token = $this->locks->acquire($lockKey, 180);
        if (! $token) {
            throw new InvalidArgumentException('Esta solicitud está en curso. Reintenta con la misma llave.');
        }
        try {
            return DB::connection('sqlsrv')->transaction(function () use ($key, $hash, $actor, $action, $payload, $callback, $lockKey, $token, $audit) {
                $existing = BonusAdministrativeOperation::where('idempotency_key', $key)->first();
                if ($existing) {
                    if (! hash_equals($existing->request_hash, $hash)) {
                        throw new InvalidArgumentException('La llave ya se utilizó con otra solicitud o administrador.');
                    }

                    return ['operation' => $existing, 'replayed' => true];
                }
                [$bonus, $before] = $callback();
                $this->locks->assertOwned($lockKey, $token, 180);
                $operation = BonusAdministrativeOperation::create([
                    'public_id' => (string) Str::uuid(), 'bonus_id' => $bonus->public_id, 'action' => $action,
                    'actor_id' => $actor, 'idempotency_key' => $key, 'request_hash' => $hash, 'reason' => $payload['reason'],
                    'request_data' => $audit === null ? $payload : [...$payload, 'audit_context' => $audit], 'before_data' => $before, 'after_data' => $this->snapshot($bonus), 'created_at' => now(),
                ]);

                return ['operation' => $operation, 'replayed' => false];
            });
        } finally {
            $this->locks->release($lockKey, $token);
        }
    }

    private function authorizeCouncil(string $actor, \App\Domains\Financial\Data\BonusIssuerContext $context,
        ?string $budget, ?string $treasury): void
    {
        if ($context->scopeType !== 'council' || $budget === null || $treasury === null
            || ! $this->councilWeb->allows($actor, $context->scopeId, $budget, $treasury)) {
            throw new AuthorizationException('La emisión BECA requiere autorización web contextual vigente.');
        }
    }

    private function authorize(string $userId, string $action): void
    {
        if (! $this->access->allows($userId, $action)) {
            throw new AuthorizationException;
        }
    }

    private function isIssuer(string $userId, Bonus $bonus): bool
    {
        return $bonus->issuer_type === 'USER' && strtolower($bonus->issuer_id) === strtolower($userId);
    }

    private function snapshot(Bonus $bonus): array
    {
        return ['id' => strtolower($bonus->public_id), 'status' => $bonus->status->value, 'type' => $bonus->type->value,
            'beneficiary_type' => $bonus->beneficiary_type, 'beneficiary_id' => $bonus->beneficiary_id,
            'issuer_type' => $bonus->issuer_type, 'issuer_id' => $bonus->issuer_id,
            'issuer_scope_type' => $bonus->issuer_scope_type, 'issuer_scope_id' => $bonus->issuer_scope_id, 'currency' => $bonus->currency,
            'original_amount_cents' => $bonus->original_amount_cents, 'remaining_amount_cents' => $bonus->remaining_amount_cents,
            'valid_from' => $bonus->valid_from?->toISOString(), 'expires_at' => $bonus->expires_at?->toISOString(),
            'combinable' => $bonus->combinable, 'allows_partial_use' => $bonus->allows_partial_use,
            'restrictions' => $bonus->restrictions()->orderBy('restriction_type')->orderBy('target_id')->get()
                ->map(fn ($row) => ['type' => $row->restriction_type->value, 'target_id' => $row->target_id])->values()->all()];
    }
}
