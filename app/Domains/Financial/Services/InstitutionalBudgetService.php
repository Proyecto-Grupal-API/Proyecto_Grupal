<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Contracts\InstitutionalBudgetAuthorization;
use App\Domains\Financial\Models\{InstitutionalBonusBudget, InstitutionalBudgetOperation};
use App\Domains\Financial\Support\FinancialJobLock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Budget administration only. Never issues a bonus, reserves cash or pays a business. */
final class InstitutionalBudgetService
{
    public function __construct(private readonly InstitutionalBudgetAuthorization $authority,
        private readonly FinancialJobLock $locks) {}

    public function approve(string $actor, array $data, string $key): array
    {
        $this->authorize($actor, 'budget.approve');
        $data = validator($data, [
            'institution_reference' => ['required', 'string', 'max:100', 'regex:/\S/u'],
            'approval_reference' => ['required', 'string', 'max:150', 'regex:/\S/u'],
            'payer_reference' => ['required', 'string', 'max:150', 'regex:/\S/u'],
            'purpose' => ['required', 'string', 'max:500', 'regex:/\S/u'],
            'approved_amount_cents' => ['required'],
            'currency' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/'],
            'reason' => ['required', 'string', 'max:1000', 'regex:/\S/u'],
            'valid_from' => ['required', 'date', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/'],
            'expires_at' => ['required', 'date', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/'],
        ])->validate();
        $this->amount($data['approved_amount_cents']);
        foreach (['institution_reference','approval_reference','payer_reference','purpose','reason'] as $field) {
            $data[$field] = $this->text($data[$field], $field);
        }
        $start = CarbonImmutable::parse($data['valid_from'])->utc();
        $end = CarbonImmutable::parse($data['expires_at'])->utc();
        if ($end->lte($start)) throw new InvalidArgumentException('Vigencia de presupuesto no valida.');
        $data['valid_from'] = $start->toISOString(); $data['expires_at'] = $end->toISOString();
        return $this->run($actor, 'APPROVE', null, $data, $key, function () use ($data, $start, $end) {
            if ($end->lte(now())) throw new InvalidArgumentException('El presupuesto debe tener vigencia futura.');
            if (InstitutionalBonusBudget::where('institution_reference', $data['institution_reference'])
                ->where('approval_reference', $data['approval_reference'])->exists()) {
                throw new ConflictHttpException('La aprobacion ya esta registrada.');
            }
            $row = InstitutionalBonusBudget::create([
                'public_id' => (string) Str::uuid(), 'institution_reference' => $data['institution_reference'],
                'approval_reference' => $data['approval_reference'], 'payer_reference' => $data['payer_reference'],
                'purpose' => $data['purpose'], 'bonus_type' => 'BECA', 'currency' => $data['currency'],
                'status' => 'ACTIVE', 'approved_amount_cents' => $data['approved_amount_cents'],
                'committed_amount_cents' => 0, 'consumed_amount_cents' => 0, 'version' => 1,
                'valid_from' => $start, 'expires_at' => $end,
            ]);
            return [$row->fresh(), null];
        });
    }

    public function setApprovedAmount(string $actor, string $budgetId, int $expectedVersion,
        mixed $amountCents, string $reason, string $key): array
    {
        $budgetId = $this->uuid($budgetId);
        $this->authorize($actor, 'budget.adjust', $budgetId); $this->amount($amountCents);
        return $this->change($actor, 'ADJUST', $budgetId, $expectedVersion,
            ['approved_amount_cents' => $amountCents, 'reason' => $reason], $key,
            function (InstitutionalBonusBudget $row) use ($amountCents) {
                if ($row->consumed_amount_cents > $amountCents
                    || $row->committed_amount_cents > $amountCents - $row->consumed_amount_cents) {
                    throw new ConflictHttpException('El presupuesto no puede reducirse por debajo del importe comprometido y consumido.');
                }
                if ($row->approved_amount_cents === $amountCents) throw new ConflictHttpException('No hay cambio de importe.');
                $row->approved_amount_cents = $amountCents;
            });
    }

    public function setSuspended(string $actor, string $budgetId, int $expectedVersion,
        bool $suspended, string $reason, string $key): array
    {
        $budgetId = $this->uuid($budgetId); $action = $suspended ? 'SUSPEND' : 'RESUME';
        $this->authorize($actor, $suspended ? 'budget.suspend' : 'budget.resume', $budgetId);
        return $this->change($actor, $action, $budgetId, $expectedVersion,
            ['status' => $suspended ? 'SUSPENDED' : 'ACTIVE', 'reason' => $reason], $key,
            function (InstitutionalBonusBudget $row) use ($suspended) {
                $status = $suspended ? 'SUSPENDED' : 'ACTIVE';
                if ($row->status === $status) throw new ConflictHttpException('El presupuesto ya tiene ese estado.');
                if (!$suspended && $row->expires_at->lte(now())) throw new ConflictHttpException('No se reactiva un presupuesto vencido.');
                $row->status = $status;
            });
    }

    private function change(string $actor, string $action, string $budgetId, int $version,
        array $data, string $key, callable $mutate): array
    {
        if ($version < 1) throw new InvalidArgumentException('La version esperada debe ser positiva.');
        $data['reason'] = $this->text($data['reason'], 'reason', 1000);
        $data['expected_version'] = $version;
        return $this->run($actor, $action, $budgetId, $data, $key, function () use ($budgetId, $version, $mutate) {
            $row = InstitutionalBonusBudget::where('public_id', $budgetId)->lockForUpdate()->first();
            if (!$row) throw new ConflictHttpException('Presupuesto no disponible.');
            if ($row->version !== $version) throw new ConflictHttpException('El presupuesto cambio. Recarga su version.');
            $before = $this->snapshot($row); $mutate($row);
            if ($row->version === PHP_INT_MAX) throw new ConflictHttpException('No puede incrementarse la version.');
            ++$row->version; $row->save();
            return [$row->fresh(), $before];
        });
    }

    private function run(string $actor, string $action, ?string $budgetId, array $data, string $key, callable $work): array
    {
        if (!preg_match('/\A[A-Za-z0-9._:-]{1,200}\z/', $key)) throw new InvalidArgumentException('Llave de idempotencia no valida.');
        // SQL Server string keys may be case insensitive; use one canonical representation.
        $key = strtolower($key); $actor = strtolower($actor); ksort($data);
        $hash = hash('sha256', json_encode([$actor, $action, $budgetId, $data], JSON_THROW_ON_ERROR));
        $lockKey = 'institution-budget:'.hash('sha256', $key); $token = $this->locks->acquire($lockKey, 180);
        if (!$token) throw new ConflictHttpException('Solicitud presupuestaria en curso.');
        try {
            return DB::connection('sqlsrv')->transaction(function () use ($actor, $action, $data, $key, $hash, $work, $lockKey, $token) {
                $existing = InstitutionalBudgetOperation::where('idempotency_key', $key)->first();
                if ($existing) {
                    if (!hash_equals($existing->request_hash, $hash)) throw new ConflictHttpException('Llave reutilizada con otros datos o actor.');
                    return ['operation' => $existing, 'replayed' => true];
                }
                [$row, $before] = $work(); $this->locks->assertOwned($lockKey, $token, 180);
                $operation = InstitutionalBudgetOperation::create([
                    'public_id' => (string) Str::uuid(), 'budget_id' => $row->public_id, 'action' => $action,
                    'actor_id' => $actor, 'reason' => $data['reason'], 'idempotency_key' => $key,
                    'request_hash' => $hash, 'before_data' => $before, 'after_data' => $this->snapshot($row), 'created_at' => now(),
                ]);
                return ['operation' => $operation, 'replayed' => false];
            });
        } catch (QueryException $e) {
            if (in_array((int) ($e->errorInfo[1] ?? 0), [2601, 2627], true)) {
                throw new ConflictHttpException('La referencia de aprobacion o solicitud ya esta registrada.');
            }
            throw $e;
        } finally { $this->locks->release($lockKey, $token); }
    }

    private function authorize(string $actor, string $action, ?string $budgetId = null): void
    {
        if (!preg_match('/\A[0-9a-f]{24}\z/i', $actor)
            || !$this->authority->allows(strtolower($actor), $action, $budgetId)) throw new AuthorizationException();
    }
    private function uuid(string $id): string
    {
        if (!Str::isUuid($id)) throw new InvalidArgumentException('UUID de presupuesto no valido.');
        return strtolower($id);
    }
    private function amount(mixed $amount): void
    {
        if (!is_int($amount) || $amount < 1) throw new InvalidArgumentException('El importe debe ser un entero positivo en centavos.');
    }
    private function text(string $value, string $field, int $max = 1000): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Campo de presupuesto no valido: '.$field);
        }
        return $value;
    }
    public function snapshot(InstitutionalBonusBudget $row): array
    {
        return ['budget_id' => strtolower($row->public_id), 'institution_reference' => $row->institution_reference,
            'approval_reference' => $row->approval_reference, 'payer_reference' => $row->payer_reference,
            'purpose' => $row->purpose, 'bonus_type' => $row->bonus_type, 'currency' => $row->currency,
            'status' => $row->status, 'approved_amount_cents' => $row->approved_amount_cents,
            'committed_amount_cents' => $row->committed_amount_cents, 'consumed_amount_cents' => $row->consumed_amount_cents,
            'available_amount_cents' => $row->approved_amount_cents - $row->consumed_amount_cents - $row->committed_amount_cents,
            'version' => $row->version, 'valid_from' => $row->valid_from->toISOString(), 'expires_at' => $row->expires_at->toISOString(),
            'cash_backing_certified' => false];
    }
}
