<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Contracts\BonusAuthorizationProvider;
use App\Domains\Financial\Contracts\FinancialWebAuthorizer;
use App\Domains\Financial\Enums\BonusRestrictionType;
use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Models\Bonus;
use App\Domains\Financial\Models\BonusAdministrativeOperation;
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
        private readonly BonusAuthorizationProvider $issuers, private readonly FinancialJobLock $locks) {}

    public function issue(string $userId, array $data, string $key): array
    {
        $this->authorize($userId, 'bonus.issue');
        $type = BonusType::from($data['type']);
        if (!$this->issuers->canIssueBonus('USER', $userId, $type)) {
            throw new AuthorizationException('El emisor no tiene autorización para este tipo de bono.');
        }
        $start = CarbonImmutable::parse($data['valid_from'])->setTimezone(config('app.timezone', 'UTC'));
        $end = CarbonImmutable::parse($data['expires_at'])->setTimezone(config('app.timezone', 'UTC'));
        $restrictions = collect($data['restrictions'] ?? [])->map(fn ($row) => [
            'type' => $row['type'], 'target_id' => trim($row['target_id']),
        ])->sortBy(fn ($row) => $row['type'] . ':' . $row['target_id'])->values()->all();
        $payload = ['beneficiary_id' => strtolower(trim($data['beneficiary_id'])), 'type' => $type->value,
            'amount_cents' => (int) $data['amount_cents'], 'valid_from' => $start->utc()->format('Y-m-d\TH:i:s.uP'),
            'expires_at' => $end->utc()->format('Y-m-d\TH:i:s.uP'), 'combinable' => (bool) ($data['combinable'] ?? false),
            'allows_partial_use' => (bool) ($data['allows_partial_use'] ?? false),
            'external_reference' => trim((string) ($data['external_reference'] ?? '')) ?: null,
            'restrictions' => $restrictions, 'reason' => trim($data['reason'])];
        return $this->run($userId, 'ISSUE', $payload, $key, function () use ($userId, $payload, $type, $start, $end) {
            $beneficiary = User::find($payload['beneficiary_id']);
            if (!$beneficiary || $beneficiary->account_activation_pending) {
                throw new InvalidArgumentException('El beneficiario no existe o su cuenta está pendiente de activación.');
            }
            if ($end->lte($start) || $end->lte(now())) {
                throw new InvalidArgumentException('La vigencia debe terminar después de su inicio y en una fecha futura.');
            }
            if (count(array_unique(array_map(fn ($row) => $row['type'] . ':' . $row['target_id'], $payload['restrictions'])))
                !== count($payload['restrictions'])) {
                throw new InvalidArgumentException('No se permiten restricciones duplicadas.');
            }
            if (in_array($type, [BonusType::NEGOCIO, BonusType::CATEGORIA], true)
                && !in_array($type->value, array_column($payload['restrictions'], 'type'), true)) {
                throw new InvalidArgumentException('Este tipo de bono requiere al menos una restricción del mismo tipo.');
            }
            $bonus = $this->bonuses->issue('USER', $payload['beneficiary_id'], 'USER', $userId, $type,
                $payload['amount_cents'], $start, $end, $payload['combinable'], $payload['allows_partial_use'], $payload['external_reference']);
            foreach ($payload['restrictions'] as $restriction) {
                $this->bonuses->addRestriction($bonus->public_id, BonusRestrictionType::from($restriction['type']), $restriction['target_id']);
            }
            // Initial issuance entry receives the administrator's actual reason atomically.
            $bonus->ledgerEntries()->where('movement_type', 'EMISION')->update(['reason' => $payload['reason'], 'actor_id' => 'user:' . $userId]);
            return [$bonus->fresh(), null];
        });
    }

    public function cancel(string $userId, Bonus $bonus, string $reason, string $key): array
    {
        $this->authorize($userId, 'bonus.cancel');
        if (!$this->isIssuer($userId, $bonus) && !$this->access->allows($userId, 'bonus.cancel.all')) {
            throw new AuthorizationException('No tienes permiso para cancelar bonos de otro emisor.');
        }
        return $this->run($userId, 'CANCEL', ['bonus_id' => strtolower($bonus->public_id), 'reason' => trim($reason)], $key,
            function () use ($userId, $bonus, $reason) {
                $locked = Bonus::where('public_id', $bonus->public_id)->lockForUpdate()->firstOrFail();
                // Recheck scope using locked persisted issuer, not stale request objects.
                if (!$this->isIssuer($userId, $locked) && !$this->access->allows($userId, 'bonus.cancel.all')) {
                    throw new AuthorizationException();
                }
                $before = $this->snapshot($locked);
                return [$this->bonuses->cancel($locked->public_id, 'user:' . $userId, trim($reason)), $before];
            });
    }

    private function run(string $userId, string $action, array $payload, string $key, callable $callback): array
    {
        $key = strtolower(trim($key));
        if (!preg_match('/\A[A-Za-z0-9._:-]+\z/', $key) || strlen($key) > 255 || trim($payload['reason'] ?? '') === '') {
            throw new InvalidArgumentException('La llave de idempotencia y el motivo son obligatorios.');
        }
        $actor = 'user:' . $userId;
        $hash = hash('sha256', json_encode([$actor, $action, $payload], JSON_THROW_ON_ERROR));
        $lockKey = 'bonus-admin:' . hash('sha256', $key);
        $token = $this->locks->acquire($lockKey, 180);
        if (!$token) { throw new InvalidArgumentException('Esta solicitud está en curso. Reintenta con la misma llave.'); }
        try {
            return DB::connection('sqlsrv')->transaction(function () use ($key, $hash, $actor, $action, $payload, $callback, $lockKey, $token) {
                $existing = BonusAdministrativeOperation::where('idempotency_key', $key)->first();
                if ($existing) {
                    if (!hash_equals($existing->request_hash, $hash)) {
                        throw new InvalidArgumentException('La llave ya se utilizó con otra solicitud o administrador.');
                    }
                    return ['operation' => $existing, 'replayed' => true];
                }
                [$bonus, $before] = $callback();
                $this->locks->assertOwned($lockKey, $token, 180);
                $operation = BonusAdministrativeOperation::create([
                    'public_id' => (string) Str::uuid(), 'bonus_id' => $bonus->public_id, 'action' => $action,
                    'actor_id' => $actor, 'idempotency_key' => $key, 'request_hash' => $hash, 'reason' => $payload['reason'],
                    'request_data' => $payload, 'before_data' => $before, 'after_data' => $this->snapshot($bonus), 'created_at' => now(),
                ]);
                return ['operation' => $operation, 'replayed' => false];
            });
        } finally { $this->locks->release($lockKey, $token); }
    }

    private function authorize(string $userId, string $action): void
    {
        if (!$this->access->allows($userId, $action)) { throw new AuthorizationException(); }
    }
    private function isIssuer(string $userId, Bonus $bonus): bool
    {
        return $bonus->issuer_type === 'USER' && strtolower($bonus->issuer_id) === strtolower($userId);
    }
    private function snapshot(Bonus $bonus): array
    {
        return ['id' => strtolower($bonus->public_id), 'status' => $bonus->status->value, 'type' => $bonus->type->value,
            'beneficiary_type' => $bonus->beneficiary_type, 'beneficiary_id' => $bonus->beneficiary_id,
            'issuer_type' => $bonus->issuer_type, 'issuer_id' => $bonus->issuer_id, 'currency' => $bonus->currency,
            'original_amount_cents' => $bonus->original_amount_cents, 'remaining_amount_cents' => $bonus->remaining_amount_cents,
            'valid_from' => $bonus->valid_from?->toISOString(), 'expires_at' => $bonus->expires_at?->toISOString(),
            'combinable' => $bonus->combinable, 'allows_partial_use' => $bonus->allows_partial_use,
            'restrictions' => $bonus->restrictions()->orderBy('restriction_type')->orderBy('target_id')->get()
                ->map(fn ($row) => ['type' => $row->restriction_type->value, 'target_id' => $row->target_id])->values()->all()];
    }
}
