<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Contracts\FinancialRoleProvider;
use App\Domains\Financial\Data\LimitEvaluation;
use App\Domains\Financial\Data\LimitViolation;
use App\Domains\Financial\Enums\LimitAction;
use App\Domains\Financial\Enums\LimitChangeType;
use App\Domains\Financial\Enums\LimitMetric;
use App\Domains\Financial\Enums\LimitPeriod;
use App\Domains\Financial\Enums\LimitSubjectType;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\FinancialLimit;
use App\Domains\Financial\Models\FinancialLimitChange;
use App\Domains\Financial\Support\FinancialCorrelation;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\Wallet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * 2.10 Límites: configuración y evaluación.
 *
 * La evaluación es de solo lectura: no mueve dinero ni escribe en la
 * base de datos. El acumulado por periodo se calcula desde el ledger,
 * que es la fuente de verdad de los movimientos.
 */
class FinancialLimitService
{
    public function __construct(
        private readonly FinancialRoleProvider $roleProvider
    ) {
    }

    /**
     * @param  string|null  $changeReason  Motivo del alta para el historial.
     *         Si no se envía se usa el campo reason del límite.
     */
    public function create(
        array $data,
        string $actorId,
        ?string $changeReason = null
    ): FinancialLimit {
        $this->assertActor($actorId);

        $attributes = $this->normalize($data);
        $attributes['currency'] ??= 'MXN';
        $attributes['active'] ??= true;

        $this->assertStructure($attributes);

        return DB::connection('sqlsrv')->transaction(
            function () use ($attributes, $actorId, $changeReason) {
                $limit = FinancialLimit::create(array_merge($attributes, [
                    'public_id' => (string) Str::uuid(),
                    'active' => $attributes['active'] ?? true,
                    'created_by' => $actorId,
                ]))->fresh();

                $this->logChange(
                    $limit,
                    LimitChangeType::CREACION,
                    $actorId,
                    $changeReason ?? $limit->reason,
                    null
                );

                return $limit;
            }
        );
    }

    /**
     * Solo se pueden cambiar los valores y la vigencia. Sujeto,
     * operación, periodo y métrica definen qué mide el límite; para
     * cambiarlos se crea un límite nuevo y se desactiva el anterior.
     */
    public function update(
        FinancialLimit $limit,
        array $data,
        string $actorId,
        ?string $changeReason = null
    ): FinancialLimit {
        $this->assertActor($actorId);

        $immutable = [
            'subject_type',
            'subject_id',
            'operation',
            'period',
            'metric',
            'currency',
        ];

        foreach ($immutable as $field) {
            if (array_key_exists($field, $data)) {
                throw new InvalidArgumentException(
                    "El campo {$field} no se puede modificar; crea un límite nuevo."
                );
            }
        }

        $editable = array_intersect_key($data, array_flip([
            'name',
            'max_amount_cents',
            'max_count',
            'action',
            'active',
            'valid_from',
            'valid_until',
            'reason',
        ]));

        return DB::connection('sqlsrv')->transaction(
            function () use ($limit, $editable, $actorId, $changeReason) {
                $locked = FinancialLimit::where('public_id', $limit->public_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $merged = array_merge(
                    $this->normalize($locked->snapshot()),
                    $this->normalize($editable)
                );
                $this->assertStructure($merged);

                $before = $locked->snapshot();

                $locked->fill(array_merge(
                    array_intersect_key($merged, $editable),
                    ['updated_by' => $actorId]
                ));

                $locked->save();

                $fresh = $locked->fresh();

                $this->logChange(
                    $fresh,
                    LimitChangeType::MODIFICACION,
                    $actorId,
                    $changeReason ?? $fresh->reason,
                    $before
                );

                return $fresh;
            }
        );
    }

    private function logChange(
        FinancialLimit $limit,
        LimitChangeType $type,
        string $actorId,
        ?string $reason,
        ?array $before
    ): void {
        FinancialLimitChange::create([
            'public_id' => (string) Str::uuid(),
            'limit_id' => $limit->public_id,
            'change_type' => $type,
            'actor_id' => $actorId,
            'reason' => $reason !== null && trim($reason) !== ''
                ? Str::limit(trim($reason), 500, '')
                : null,
            'correlation_id' => app(FinancialCorrelation::class)->id(),
            'before' => $before,
            'after' => $limit->snapshot(),
        ]);
    }

    /**
     * Evalúa una operación contra los límites vigentes.
     *
     * @param  string|null  $excludeLedgerEntryId  Movimiento a excluir del
     *         acumulado (se usa al revisar una operación ya registrada para
     *         no contarla dos veces).
     * @param  array<int, LimitAction>|null  $actions  Filtra por acción.
     * @param  int|null  $beforeLedgerRowId  Solo acumula movimientos con id
     *         interno menor a este valor. Al revisar un movimiento ya
     *         registrado hace que el valor observado sea reproducible: no
     *         cambia si después se registran más movimientos en el periodo.
     */
    public function evaluate(
        Wallet $wallet,
        MovementType $operation,
        int $amountCents,
        ?CarbonInterface $at = null,
        ?string $excludeLedgerEntryId = null,
        ?array $actions = null,
        ?int $beforeLedgerRowId = null
    ): LimitEvaluation {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException(
                'El monto a evaluar debe ser mayor que cero.'
            );
        }

        $at = $at
            ? CarbonImmutable::instance($at)
            : CarbonImmutable::now();

        $limits = $this->applicableLimits(
            $wallet,
            $operation,
            $at,
            $actions
        );

        $usageByPeriod = [];
        $violations = [];

        foreach ($limits as $limit) {
            $window = [null, null];

            if ($limit->period === LimitPeriod::OPERACION) {
                $observed = $amountCents;
            } else {
                $key = $limit->period->value;
                $window = $this->periodWindow($limit->period, $at);

                $usageByPeriod[$key] ??= $this->usage(
                    $wallet,
                    $operation,
                    $limit->period,
                    $at,
                    $excludeLedgerEntryId,
                    $beforeLedgerRowId
                );

                $observed = $limit->metric === LimitMetric::MONTO
                    ? $usageByPeriod[$key]['amount_cents'] + $amountCents
                    : $usageByPeriod[$key]['count'] + 1;
            }

            $threshold = $limit->threshold();

            if ($observed > $threshold) {
                $violations[] = new LimitViolation(
                    $limit,
                    $observed,
                    $threshold,
                    $window[0],
                    $window[1]
                );
            }
        }

        return new LimitEvaluation(
            walletId: $wallet->public_id,
            operation: $operation,
            amountCents: $amountCents,
            limitsEvaluated: $limits->count(),
            violations: $violations
        );
    }

    /**
     * Límites activos y vigentes que aplican a la wallet y operación.
     *
     * @return Collection<int, FinancialLimit>
     */
    public function applicableLimits(
        Wallet $wallet,
        MovementType $operation,
        CarbonInterface $at,
        ?array $actions = null
    ): Collection {
        $atUtc = CarbonImmutable::instance($at)
            ->setTimezone(config('app.timezone', 'UTC'));

        $candidates = FinancialLimit::query()
            ->where('operation', $operation->value)
            ->where('active', true)
            ->where('currency', strtoupper((string) $wallet->currency))
            ->where(function ($query) use ($atUtc) {
                $query->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', $atUtc);
            })
            ->where(function ($query) use ($atUtc) {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>', $atUtc);
            })
            ->when($actions !== null, function ($query) use ($actions) {
                $query->whereIn(
                    'action',
                    array_map(fn (LimitAction $a) => $a->value, $actions)
                );
            })
            ->orderBy('id')
            ->get();

        $roles = null;

        return $candidates->filter(
            function (FinancialLimit $limit) use ($wallet, &$roles): bool {
                $subjectId = strtolower((string) $limit->subject_id);

                return match ($limit->subject_type) {
                    LimitSubjectType::GLOBAL => true,
                    LimitSubjectType::WALLET_TYPE =>
                        $subjectId === strtolower($wallet->type->value),
                    LimitSubjectType::OWNER =>
                        $subjectId === strtolower((string) $wallet->owner_id),
                    LimitSubjectType::ROLE => in_array(
                        $subjectId,
                        $roles ??= array_map(
                            'strtolower',
                            $this->roleProvider->rolesOf(
                                (string) $wallet->owner_type,
                                (string) $wallet->owner_id
                            )
                        ),
                        true
                    ),
                };
            }
        )->values();
    }

    /**
     * Inicio y fin (UTC, fin exclusivo) del periodo de negocio que
     * contiene $at.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function periodWindow(
        LimitPeriod $period,
        CarbonInterface $at
    ): array {
        $local = CarbonImmutable::instance($at)
            ->setTimezone(config('financial.business_timezone'));

        [$start, $end] = match ($period) {
            LimitPeriod::DIARIO => [
                $local->startOfDay(),
                $local->startOfDay()->addDay(),
            ],
            LimitPeriod::MENSUAL => [
                $local->startOfMonth(),
                $local->startOfMonth()->addMonth(),
            ],
            LimitPeriod::OPERACION => throw new InvalidArgumentException(
                'El periodo OPERACION no tiene ventana de tiempo.'
            ),
        };

        $appTz = config('app.timezone', 'UTC');

        return [
            $start->setTimezone($appTz),
            $end->setTimezone($appTz),
        ];
    }

    /**
     * @return array{amount_cents: int, count: int}
     */
    private function usage(
        Wallet $wallet,
        MovementType $operation,
        LimitPeriod $period,
        CarbonInterface $at,
        ?string $excludeLedgerEntryId,
        ?int $beforeLedgerRowId = null
    ): array {
        [$start, $end] = $this->periodWindow($period, $at);

        $query = LedgerEntry::query()
            ->where('wallet_id', $wallet->public_id)
            ->where('movement_type', $operation->value)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->when(
                $excludeLedgerEntryId !== null,
                fn ($q) => $q->where('public_id', '!=', $excludeLedgerEntryId)
            )
            ->when(
                $beforeLedgerRowId !== null,
                fn ($q) => $q->where('id', '<', $beforeLedgerRowId)
            );

        $sum = (int) (clone $query)->sum('amount_cents');
        $count = (int) (clone $query)->count();

        // Débitos se registran en negativo; el límite mide magnitud.
        return [
            'amount_cents' => abs($sum),
            'count' => $count,
        ];
    }

    private function normalize(array $data): array
    {
        $enumFields = [
            'subject_type' => LimitSubjectType::class,
            'operation' => MovementType::class,
            'period' => LimitPeriod::class,
            'metric' => LimitMetric::class,
            'action' => LimitAction::class,
        ];

        foreach ($enumFields as $field => $enum) {
            if (!array_key_exists($field, $data) || $data[$field] === null) {
                continue;
            }

            if ($data[$field] instanceof $enum) {
                continue;
            }

            $value = $enum::tryFrom(strtoupper((string) $data[$field]));

            if ($value === null) {
                throw new InvalidArgumentException(
                    "El valor de {$field} no es válido."
                );
            }

            $data[$field] = $value;
        }

        if (isset($data['currency'])) {
            $data['currency'] = strtoupper((string) $data['currency']);
        }

        foreach (['valid_from', 'valid_until'] as $field) {
            if (!empty($data[$field]) && !$data[$field] instanceof CarbonInterface) {
                $data[$field] = CarbonImmutable::parse($data[$field]);
            }
        }

        return $data;
    }

    private function assertStructure(array $a): void
    {
        foreach (['name', 'subject_type', 'operation', 'period', 'metric', 'action'] as $field) {
            if (!isset($a[$field]) || $a[$field] === '') {
                throw new InvalidArgumentException(
                    "El campo {$field} es obligatorio."
                );
            }
        }

        $subjectType = $a['subject_type'];
        $subjectId = $a['subject_id'] ?? null;

        if ($subjectType === LimitSubjectType::GLOBAL && $subjectId !== null && $subjectId !== '') {
            throw new InvalidArgumentException(
                'Un límite GLOBAL no debe indicar subject_id.'
            );
        }

        if ($subjectType !== LimitSubjectType::GLOBAL && ($subjectId === null || $subjectId === '')) {
            throw new InvalidArgumentException(
                'El límite requiere subject_id para el tipo de sujeto indicado.'
            );
        }

        if (
            $subjectType === LimitSubjectType::WALLET_TYPE
            && WalletType::tryFrom((string) $subjectId) === null
        ) {
            throw new InvalidArgumentException(
                'subject_id debe ser un tipo de wallet válido.'
            );
        }

        if ($a['metric'] === LimitMetric::MONTO) {
            if (!isset($a['max_amount_cents']) || !is_int($a['max_amount_cents'])) {
                throw new InvalidArgumentException(
                    'Un límite por MONTO requiere max_amount_cents entero.'
                );
            }

            if ($a['max_amount_cents'] < 0) {
                throw new InvalidArgumentException(
                    'max_amount_cents no puede ser negativo.'
                );
            }

            if (isset($a['max_count'])) {
                throw new InvalidArgumentException(
                    'Un límite por MONTO no debe indicar max_count.'
                );
            }
        }

        if ($a['metric'] === LimitMetric::CONTEO) {
            if ($a['period'] === LimitPeriod::OPERACION) {
                throw new InvalidArgumentException(
                    'Un límite por CONTEO requiere un periodo DIARIO o MENSUAL.'
                );
            }

            if (!isset($a['max_count']) || !is_int($a['max_count'])) {
                throw new InvalidArgumentException(
                    'Un límite por CONTEO requiere max_count entero.'
                );
            }

            if ($a['max_count'] < 0) {
                throw new InvalidArgumentException(
                    'max_count no puede ser negativo.'
                );
            }

            if (isset($a['max_amount_cents'])) {
                throw new InvalidArgumentException(
                    'Un límite por CONTEO no debe indicar max_amount_cents.'
                );
            }
        }

        if (isset($a['currency']) && !preg_match('/^[A-Z]{3}$/', $a['currency'])) {
            throw new InvalidArgumentException(
                'La moneda debe ser un código de tres letras.'
            );
        }

        if (
            !empty($a['valid_from'])
            && !empty($a['valid_until'])
            && $a['valid_until'] <= $a['valid_from']
        ) {
            throw new InvalidArgumentException(
                'valid_until debe ser posterior a valid_from.'
            );
        }
    }

    private function assertActor(string $actorId): void
    {
        if (trim($actorId) === '') {
            throw new InvalidArgumentException(
                'Se requiere el identificador del actor.'
            );
        }
    }
}
