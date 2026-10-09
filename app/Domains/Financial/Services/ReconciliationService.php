<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Contracts\CashReconciliationSource;
use App\Domains\Financial\Data\CashDifference;
use App\Domains\Financial\Enums\CashReconciliationStatus;
use App\Domains\Financial\Enums\DifferenceStatus;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\ReconciliationCheck;
use App\Domains\Financial\Enums\ReconciliationScope;
use App\Domains\Financial\Enums\ReconciliationStatus;
use App\Domains\Financial\Enums\ReconciliationTrigger;
use App\Domains\Financial\Exceptions\ReconciliationInProgressException;
use App\Domains\Financial\Enums\TopUpStatus;
use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Enums\WithdrawalStatus;
use App\Domains\Financial\Models\Bonus;
use App\Domains\Financial\Models\BonusLedgerEntry;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\FinancialRefundRequest;
use App\Domains\Financial\Models\PurchaseRefundRequest;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\PurchasePayment;
use App\Domains\Financial\Models\PurchasePaymentBonus;
use App\Domains\Financial\Models\Reconciliation;
use App\Domains\Financial\Models\ReconciliationDifference;
use App\Domains\Financial\Models\ReconciliationDifferenceObservation;
use App\Domains\Financial\Support\FinancialJobLock;
use App\Domains\Financial\Models\TopUp;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Models\Withdrawal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * 2.10 Conciliación: compara la información financiera esperada contra
 * la registrada y guarda las diferencias.
 *
 * Es de solo lectura sobre wallets, ledger, bonos y operaciones: nunca
 * corrige saldos. Solo escribe en reconciliations y
 * reconciliation_differences.
 *
 * Comprobaciones de estado (al momento de ejecutar):
 *  - saldo de wallet vs suma del ledger y vs último movimiento;
 *  - saldo de bono vs suma del ledger de bonos.
 * Comprobaciones del día de negocio:
 *  - transacciones pendientes o sin movimientos;
 *  - transferencias cuyo total no suma cero;
 *  - recargas/retiros completados sin movimiento o con monto distinto;
 *  - compras con totales descuadrados o sin el cargo a wallet esperado.
 */
class ReconciliationService
{
    private const CHUNK = 200;

    /** @var array<int, array<string, mixed>> */
    private array $differences = [];

    /** @var array<string, int> */
    private array $summary = [];

    /** @var array<int, string>|null  IDs de wallet en minúsculas */
    private ?array $scopeWalletIds = null;

    private CarbonImmutable $windowStart;

    private CarbonImmutable $windowEnd;

    private bool $replayed = false;

    /** @var array<string, string> */
    private array $activeLocks = [];

    /**
     * true si la última llamada a run() devolvió una corrida ya registrada
     * con la misma llave de idempotencia (no se ejecutó otra).
     */
    public function wasReplay(): bool
    {
        return $this->replayed;
    }

    public function __construct(
        private readonly CashReconciliationSource $cashSource,
        private readonly FinancialJobLock $locks
    ) {
    }

    /**
     * Ejecuta una conciliación de diagnóstico.
     *
     * - Una sola corrida a la vez por fecha de negocio (lock en base de
     *   datos); una segunda solicitud concurrente recibe
     *   ReconciliationInProgressException.
     * - Con $idempotencyKey, repetir la misma solicitud devuelve la
     *   corrida ya registrada en lugar de ejecutar otra.
     * - Cada corrida se conserva. Una diferencia ya conocida (misma fecha,
     *   comprobación, entidad y valores) no se duplica: se registra como
     *   observación de la corrida.
     *
     * @param  CarbonInterface|string  $businessDate  Fecha (Y-m-d) en la
     *         zona horaria de negocio.
     * @param  array<int, string>|null  $walletIds  Limita la conciliación a
     *         esas wallets. null = todo el módulo.
     *
     * @throws ReconciliationInProgressException
     */
    public function run(
        CarbonInterface|string $businessDate,
        string $executedBy,
        ?array $walletIds = null,
        ?ReconciliationTrigger $trigger = null,
        ?string $idempotencyKey = null,
        int $attempt = 1
    ): Reconciliation {
        $this->replayed = false;
        $this->activeLocks = [];

        if (trim($executedBy) === '') {
            throw new InvalidArgumentException(
                'Se requiere el identificador de quien ejecuta la conciliación.'
            );
        }

        $timezone = (string) config('financial.business_timezone');

        try {
            $date = $businessDate instanceof CarbonInterface
                ? CarbonImmutable::parse($businessDate->format('Y-m-d'), $timezone)
                : CarbonImmutable::createFromFormat('!Y-m-d', $businessDate, $timezone);
        } catch (Throwable) {
            $date = false;
        }

        if (!$date || (is_string($businessDate) && $date->format('Y-m-d') !== $businessDate)) {
            throw new InvalidArgumentException(
                'La fecha de negocio debe tener el formato AAAA-MM-DD.'
            );
        }

        $today = CarbonImmutable::now($timezone)->startOfDay();

        if ($date->greaterThan($today)) {
            throw new InvalidArgumentException(
                'No se puede conciliar una fecha futura.'
            );
        }

        if ($walletIds !== null) {
            $walletIds = array_values(array_unique(array_map(
                fn ($id) => strtolower(trim((string) $id)),
                $walletIds
            )));
            sort($walletIds);

            if ($walletIds === []) {
                throw new InvalidArgumentException(
                    'La lista de wallets no puede estar vacía.'
                );
            }
        }

        $idempotencyKey = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? trim($idempotencyKey)
            : null;

        if ($idempotencyKey !== null) {
            $existing = $this->findByIdempotencyKey($idempotencyKey, $date, $walletIds);

            if ($existing) {
                $this->replayed = true;

                return $existing;
            }
        }

        $idempotencyLockKey = $idempotencyKey !== null
            ? 'reconciliation-idempotency:' . hash('sha256', $idempotencyKey)
            : null;
        $idempotencyLockToken = $idempotencyLockKey !== null
            ? $this->locks->acquire($idempotencyLockKey,
                (int) config('financial.reconciliation.lock_ttl_seconds', 900))
            : null;
        if ($idempotencyLockKey !== null && $idempotencyLockToken === null) {
            throw new ReconciliationInProgressException($date->format('Y-m-d'));
        }

        try {
            $lockKey = 'reconciliation:' . $date->format('Y-m-d');
            $lockToken = $this->locks->acquire(
                $lockKey,
                (int) config('financial.reconciliation.lock_ttl_seconds', 900)
            );

            if ($lockToken === null) {
                throw new ReconciliationInProgressException($date->format('Y-m-d'));
            }

            try {
                // Dentro del lock: otra solicitud con la misma llave pudo
                // terminar mientras esperábamos.
                if ($idempotencyKey !== null) {
                    $existing = $this->findByIdempotencyKey($idempotencyKey, $date, $walletIds);

                    if ($existing) {
                        $this->replayed = true;

                        return $existing;
                    }
                }

                $this->activeLocks = [$lockKey => $lockToken];
                if ($idempotencyLockKey !== null) {
                    $this->activeLocks[$idempotencyLockKey] = $idempotencyLockToken;
                }

                return $this->execute(
                    $date,
                    $timezone,
                    $executedBy,
                    $walletIds,
                    $trigger,
                    $idempotencyKey,
                    $attempt
                );
            } finally {
                $this->locks->release($lockKey, $lockToken);
            }
        } finally {
            if ($idempotencyLockKey !== null && $idempotencyLockToken !== null) {
                $this->locks->release($idempotencyLockKey, $idempotencyLockToken);
            }
        }
    }

    public function isRunning(CarbonInterface|string $businessDate): bool
    {
        $date = $businessDate instanceof CarbonInterface
            ? $businessDate->format('Y-m-d')
            : $businessDate;

        return $this->locks->isHeld('reconciliation:' . $date);
    }

    private function findByIdempotencyKey(
        string $key,
        CarbonImmutable $date,
        ?array $walletIds
    ): ?Reconciliation {
        $existing = Reconciliation::where('idempotency_key', $key)->first();

        if (!$existing) {
            return null;
        }

        $sameScope = $existing->business_date?->format('Y-m-d') === $date->format('Y-m-d')
            && $this->normalizeIds($existing->scope_wallet_ids) === $walletIds;

        if (!$sameScope) {
            throw new InvalidArgumentException(
                'La llave de idempotencia ya se usó para otra fecha o alcance.'
            );
        }

        if ($existing->status === ReconciliationStatus::EN_PROCESO) {
            throw new ReconciliationInProgressException($date->format('Y-m-d'));
        }

        return $existing;
    }

    private function normalizeIds(?array $ids): ?array
    {
        if ($ids === null) {
            return null;
        }

        $ids = array_values(array_unique(array_map(
            fn ($id) => strtolower(trim((string) $id)),
            $ids
        )));
        sort($ids);

        return $ids;
    }

    private function execute(
        CarbonImmutable $date,
        string $timezone,
        string $executedBy,
        ?array $walletIds,
        ?ReconciliationTrigger $trigger,
        ?string $idempotencyKey,
        int $attempt
    ): Reconciliation {
        $this->differences = [];
        $this->summary = [];
        $this->scopeWalletIds = $walletIds;

        $appTz = config('app.timezone', 'UTC');
        $this->windowStart = $date->startOfDay()->setTimezone($appTz);
        $this->windowEnd = $date->startOfDay()->addDay()->setTimezone($appTz);

        $reconciliation = Reconciliation::create([
            'public_id' => (string) Str::uuid(),
            'business_date' => $date->format('Y-m-d'),
            'timezone' => $timezone,
            'scope' => $walletIds === null
                ? ReconciliationScope::GLOBAL
                : ReconciliationScope::WALLETS,
            'scope_wallet_ids' => $walletIds,
            'status' => ReconciliationStatus::EN_PROCESO,
            'started_at' => now(),
            'executed_by' => $executedBy,
            'trigger_source' => $trigger,
            'attempt' => max(1, $attempt),
            'idempotency_key' => $idempotencyKey,
        ]);

        try {
            $this->checkWallets();
            $this->checkTransactions();
            $this->checkTransfers();
            $this->checkTopUps();
            $this->checkWithdrawals();
            $this->checkPurchases();
            $this->checkBonuses();

            $cashIntegrated = $this->cashSource->isIntegrated();

            if ($cashIntegrated) {
                $this->checkCash();
            }

            $businessDate = $date->format('Y-m-d');

            DB::connection('sqlsrv')->transaction(function () use ($reconciliation, $cashIntegrated, $businessDate) {
                foreach ($this->activeLocks as $key => $token) {
                    $this->locks->assertOwned($key, $token,
                        (int) config('financial.reconciliation.lock_ttl_seconds', 900));
                }

                $new = 0;
                $recurring = 0;

                foreach ($this->differences as $difference) {
                    $isNew = $this->persistDifference($reconciliation, $businessDate, $difference);

                    $isNew ? $new++ : $recurring++;
                }

                $reconciliation->status = $this->differences === []
                    ? ReconciliationStatus::CUADRADA
                    : ReconciliationStatus::CON_DIFERENCIAS;
                $reconciliation->finished_at = now();
                $reconciliation->checks_executed = $cashIntegrated
                    ? count(ReconciliationCheck::cases())
                    : count(ReconciliationCheck::internal());
                $reconciliation->cash_status = $cashIntegrated
                    ? CashReconciliationStatus::COMPARADA
                    : CashReconciliationStatus::NO_INTEGRADA;
                $reconciliation->differences_count = count($this->differences);
                $reconciliation->new_differences_count = $new;
                $reconciliation->recurring_differences_count = $recurring;
                $reconciliation->summary = $this->summary;
                $reconciliation->save();
            });
        } catch (Throwable $exception) {
            report($exception);

            $reconciliation->status = ReconciliationStatus::FALLIDA;
            $reconciliation->finished_at = now();
            $reconciliation->error_message = Str::limit($exception->getMessage(), 1900);
            $reconciliation->save();
        }

        return $reconciliation->fresh();
    }

    /**
     * @return bool  true si la diferencia es nueva
     */
    private function persistDifference(
        Reconciliation $reconciliation,
        string $businessDate,
        array $difference
    ): bool {
        $fingerprint = hash('sha256', implode('|', [
            $businessDate,
            $difference['check_code']->value,
            strtoupper($difference['entity_type']),
            strtolower((string) $difference['entity_id']),
            strtolower((string) ($difference['wallet_id'] ?? '')),
            $difference['expected_cents'] ?? 'null',
            $difference['actual_cents'] ?? 'null',
        ]));

        // Las corridas de una misma fecha están serializadas por el lock,
        // así que no hay carrera al buscar la huella.
        $existing = ReconciliationDifference::where('fingerprint', $fingerprint)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            $existing->last_seen_reconciliation_id = $reconciliation->public_id;
            $existing->last_seen_at = now();
            $existing->occurrences = (int) $existing->occurrences + 1;
            $existing->save();

            ReconciliationDifferenceObservation::create([
                'reconciliation_id' => $reconciliation->public_id,
                'difference_id' => $existing->public_id,
                'is_new' => false,
            ]);

            return false;
        }

        $created = ReconciliationDifference::create(array_merge($difference, [
            'public_id' => (string) Str::uuid(),
            'reconciliation_id' => $reconciliation->public_id,
            'status' => DifferenceStatus::ABIERTA,
            'fingerprint' => $fingerprint,
            'business_date' => $businessDate,
            'last_seen_reconciliation_id' => $reconciliation->public_id,
            'last_seen_at' => now(),
            'occurrences' => 1,
        ]));

        ReconciliationDifferenceObservation::create([
            'reconciliation_id' => $reconciliation->public_id,
            'difference_id' => $created->public_id,
            'is_new' => true,
        ]);

        return true;
    }

    /**
     * Diferencias de caja (2.8). Solo se informan con sus referencias
     * autorizadas; nunca generan ajustes.
     */
    private function checkCash(): void
    {
        foreach ($this->cashSource->differences($this->windowStart, $this->windowEnd, $this->scopeWalletIds) as $cash) {
            if (!$cash instanceof CashDifference) {
                throw new InvalidArgumentException(
                    'La fuente de caja devolvió un elemento no válido.'
                );
            }

            $this->addDifference(
                ReconciliationCheck::CAJA_DIFERENCIA,
                $cash->entityType,
                $cash->entityId,
                $cash->walletId,
                $cash->expectedCents,
                $cash->actualCents,
                ['references' => $cash->references]
            );
        }
    }

    public function resolveDifference(
        ReconciliationDifference $difference,
        string $actorId,
        string $note
    ): ReconciliationDifference {
        if (trim($actorId) === '' || trim($note) === '') {
            throw new InvalidArgumentException(
                'Para resolver una diferencia se requieren actor y nota.'
            );
        }

        return DB::connection('sqlsrv')->transaction(
            function () use ($difference, $actorId, $note) {
                $locked = ReconciliationDifference::where('public_id', $difference->public_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->status !== DifferenceStatus::ABIERTA) {
                    throw new InvalidArgumentException(
                        'La diferencia ya fue resuelta.'
                    );
                }

                $locked->status = DifferenceStatus::RESUELTA;
                $locked->resolved_by = $actorId;
                $locked->resolved_at = now();
                $locked->resolution_note = $note;
                $locked->save();

                return $locked->fresh();
            }
        );
    }

    public function list(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return Reconciliation::query()
            ->when($filters['business_date'] ?? null, fn ($q, $v) => $q->whereDate('business_date', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['trigger_source'] ?? null, fn ($q, $v) => $q->where('trigger_source', $v))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    // ------------------------------------------------------------------
    // Comprobaciones
    // ------------------------------------------------------------------

    private function checkWallets(): void
    {
        Wallet::query()
            ->when($this->scopeWalletIds !== null, fn ($q) => $q->whereIn('public_id', $this->scopeWalletIds))
            ->orderBy('id')
            ->chunk(self::CHUNK, function ($wallets) {
                $ids = $wallets->pluck('public_id')->all();

                $sums = LedgerEntry::query()
                    ->whereIn('wallet_id', $ids)
                    ->groupBy('wallet_id')
                    ->selectRaw('wallet_id, SUM(COALESCE(available_delta_cents, amount_cents)) AS total_cents, SUM(held_delta_cents) AS held_cents')
                    ->get()
                    ->keyBy(fn ($row) => strtolower((string) $row->wallet_id));

                foreach ($wallets as $wallet) {
                    $key = strtolower((string) $wallet->public_id);
                    $ledgerTotal = (int) ($sums->get($key)?->total_cents ?? 0);
                    $stored = (int) $wallet->available_balance_cents;

                    if ($ledgerTotal !== $stored) {
                        $this->addDifference(
                            ReconciliationCheck::SALDO_WALLET_VS_LEDGER,
                            'WALLET',
                            $wallet->public_id,
                            $wallet->public_id,
                            $ledgerTotal,
                            $stored,
                            ['note' => 'expected = suma del ledger; actual = saldo disponible almacenado']
                        );
                    }

                    $last = LedgerEntry::where('wallet_id', $wallet->public_id)
                        ->orderByDesc('created_at')
                        ->orderByDesc('id')
                        ->first();

                    if (
                        $last
                        && $last->available_balance_after_cents !== null
                        && (int) $last->available_balance_after_cents !== $stored
                    ) {
                        $this->addDifference(
                            ReconciliationCheck::SALDO_WALLET_VS_ULTIMO_MOVIMIENTO,
                            'WALLET',
                            $wallet->public_id,
                            $wallet->public_id,
                            (int) $last->available_balance_after_cents,
                            $stored,
                            ['ledger_entry_id' => $last->public_id]
                        );
                    }

                    $ledgerHeld = (int) ($sums->get($key)?->held_cents ?? 0);
                    if ($ledgerHeld !== (int) $wallet->held_balance_cents) {
                        $this->addDifference(ReconciliationCheck::RETENIDO_WALLET_VS_LEDGER,
                            'WALLET', $wallet->public_id, $wallet->public_id, $ledgerHeld,
                            (int) $wallet->held_balance_cents, ['note' => 'expected = suma de held_delta_cents']);
                    }

                    $expectedHeld = $last && $last->held_balance_after_cents !== null
                        ? (int) $last->held_balance_after_cents
                        : ($last ? null : 0);

                    if (
                        $expectedHeld !== null
                        && $expectedHeld !== (int) $wallet->held_balance_cents
                    ) {
                        $this->addDifference(
                            ReconciliationCheck::RETENIDO_WALLET_VS_ULTIMO_MOVIMIENTO,
                            'WALLET',
                            $wallet->public_id,
                            $wallet->public_id,
                            $expectedHeld,
                            (int) $wallet->held_balance_cents,
                            ['ledger_entry_id' => $last?->public_id]
                        );
                    }
                }
            });
    }

    private function checkTransactions(): void
    {
        $base = fn () => $this->scopeTransactions(
            FinancialTransaction::query()
                ->where('created_at', '>=', $this->windowStart)
                ->where('created_at', '<', $this->windowEnd)
        );

        $base()
            ->where('status', TransactionStatus::PENDIENTE->value)
            ->whereNotIn('reference_type', ['REFUND_REQUEST', 'PURCHASE_REFUND_REQUEST'])
            ->orderBy('id')
            ->chunk(self::CHUNK, function ($transactions) {
                foreach ($transactions as $transaction) {
                    $this->addDifference(
                        ReconciliationCheck::TRANSACCION_PENDIENTE,
                        'FINANCIAL_TRANSACTION',
                        $transaction->public_id,
                        null,
                        null,
                        null,
                        [
                            'reference_type' => $transaction->reference_type,
                            'reference_id' => $transaction->reference_id,
                        ]
                    );
                }
            });

        $base()
            ->where('status', TransactionStatus::COMPLETADA->value)
            ->whereNotIn('reference_type', ['REFUND_REQUEST', 'PURCHASE_REFUND_REQUEST'])
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('bonus_ledger_entries')
                    ->join('purchase_refund_requests', 'purchase_refund_requests.financial_transaction_id', '=', 'financial_transactions.public_id')
                    ->where('purchase_refund_requests.wallet_amount_cents', 0)
                    ->whereColumn('bonus_ledger_entries.reference_id', 'financial_transactions.reference_id')
                    ->whereColumn('bonus_ledger_entries.reference_type', 'financial_transactions.reference_type')
                    ->where('financial_transactions.reference_type', 'PURCHASE_REFUND');
            })
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('ledger_entries')
                    ->whereColumn(
                        'ledger_entries.transaction_id',
                        'financial_transactions.public_id'
                    );
            })
            ->orderBy('id')
            ->chunk(self::CHUNK, function ($transactions) {
                foreach ($transactions as $transaction) {
                    $this->addDifference(
                        ReconciliationCheck::TRANSACCION_SIN_MOVIMIENTOS,
                        'FINANCIAL_TRANSACTION',
                        $transaction->public_id,
                        null,
                        null,
                        null,
                        [
                            'reference_type' => $transaction->reference_type,
                            'reference_id' => $transaction->reference_id,
                        ]
                    );
                }
            });
    }

    private function checkTransfers(): void
    {
        LedgerEntry::query()
            ->whereIn('movement_type', [
                MovementType::TRANSFERENCIA_SALIDA->value,
                MovementType::TRANSFERENCIA_ENTRADA->value,
            ])
            ->where('created_at', '>=', $this->windowStart)
            ->where('created_at', '<', $this->windowEnd)
            ->when($this->scopeWalletIds !== null, function ($q) {
                $q->whereIn(
                    'transaction_id',
                    LedgerEntry::query()
                        ->select('transaction_id')
                        ->whereIn('wallet_id', $this->scopeWalletIds)
                );
            })
            ->groupBy('transaction_id')
            ->havingRaw('SUM(amount_cents) <> 0')
            ->selectRaw('transaction_id, SUM(amount_cents) AS total_cents, COUNT(*) AS entries')
            ->get()
            ->each(function ($row) {
                $this->addDifference(
                    ReconciliationCheck::TRANSFERENCIA_DESCUADRADA,
                    'FINANCIAL_TRANSACTION',
                    (string) $row->transaction_id,
                    null,
                    0,
                    (int) $row->total_cents,
                    ['entries' => (int) $row->entries]
                );
            });
    }

    private function checkTopUps(): void
    {
        TopUp::query()
            ->where('status', TopUpStatus::COMPLETADA->value)
            ->where('updated_at', '>=', $this->windowStart)
            ->where('updated_at', '<', $this->windowEnd)
            ->when($this->scopeWalletIds !== null, fn ($q) => $q->whereIn('wallet_id', $this->scopeWalletIds))
            ->orderBy('id')
            ->chunk(self::CHUNK, function ($topUps) {
                $this->checkOperationsAgainstLedger(
                    $topUps,
                    'TOPUP',
                    1,
                    ReconciliationCheck::RECARGA_SIN_MOVIMIENTO,
                    ReconciliationCheck::RECARGA_MONTO_DIFERENTE
                );
            });
    }

    private function checkWithdrawals(): void
    {
        Withdrawal::query()
            ->where('status', WithdrawalStatus::COMPLETADA->value)
            ->where('updated_at', '>=', $this->windowStart)
            ->where('updated_at', '<', $this->windowEnd)
            ->when($this->scopeWalletIds !== null, fn ($q) => $q->whereIn('wallet_id', $this->scopeWalletIds))
            ->orderBy('id')
            ->chunk(self::CHUNK, function ($withdrawals) {
                $this->checkOperationsAgainstLedger(
                    $withdrawals,
                    'WITHDRAWAL',
                    -1,
                    ReconciliationCheck::RETIRO_SIN_MOVIMIENTO,
                    ReconciliationCheck::RETIRO_MONTO_DIFERENTE
                );
            });
    }

    /**
     * Recargas y retiros: cada operación COMPLETADA debe tener su
     * FinancialTransaction (reference_type + reference_id) y movimientos
     * cuya suma sea +monto (recarga) o -monto (retiro).
     */
    private function checkOperationsAgainstLedger(
        $operations,
        string $referenceType,
        int $sign,
        ReconciliationCheck $missingCheck,
        ReconciliationCheck $amountCheck
    ): void {
        $ids = $operations->pluck('public_id')->all();

        $transactions = FinancialTransaction::query()
            ->where('reference_type', $referenceType)
            ->whereIn('reference_id', $this->caseVariants($ids))
            ->get(['public_id', 'reference_id']);

        $ledgerSums = $this->ledgerSumsByTransaction(
            $transactions->pluck('public_id')->all()
        );

        $transactionsByOperation = $transactions->groupBy(
            fn ($t) => strtolower((string) $t->reference_id)
        );

        foreach ($operations as $operation) {
            $key = strtolower((string) $operation->public_id);
            $related = $transactionsByOperation->get($key);

            if (!$related || $related->isEmpty()) {
                $this->addDifference(
                    $missingCheck,
                    $referenceType,
                    $operation->public_id,
                    $operation->wallet_id,
                    $sign * (int) $operation->amount_cents,
                    0,
                    []
                );

                continue;
            }

            $actual = $related->sum(
                fn ($t) => $ledgerSums[strtolower((string) $t->public_id)] ?? 0
            );

            $expected = $sign * (int) $operation->amount_cents;

            if ($actual !== $expected) {
                $this->addDifference(
                    $amountCheck,
                    $referenceType,
                    $operation->public_id,
                    $operation->wallet_id,
                    $expected,
                    $actual,
                    ['transaction_ids' => $related->pluck('public_id')->all()]
                );
            }
        }
    }

    private function checkPurchases(): void
    {
        PurchasePayment::query()
            ->where('created_at', '>=', $this->windowStart)
            ->where('created_at', '<', $this->windowEnd)
            ->when($this->scopeWalletIds !== null, fn ($q) => $q->whereIn('wallet_id', $this->scopeWalletIds))
            ->orderBy('id')
            ->chunk(self::CHUNK, function ($payments) {
                $keys = $payments->pluck('idempotency_key')->filter()->all();

                $transactions = FinancialTransaction::query()
                    ->whereIn('idempotency_key', $keys)
                    ->get(['public_id', 'idempotency_key'])
                    ->keyBy('idempotency_key');

                $ledgerSums = $this->ledgerSumsByTransaction(
                    $transactions->pluck('public_id')->all()
                );

                $bonusSums = PurchasePaymentBonus::query()
                    ->whereIn('purchase_payment_id', $payments->pluck('public_id')->all())
                    ->groupBy('purchase_payment_id')
                    ->selectRaw('purchase_payment_id, SUM(amount_cents) AS total_cents')
                    ->get()
                    ->keyBy(fn ($row) => strtolower((string) $row->purchase_payment_id));

                foreach ($payments as $payment) {
                    $total = (int) $payment->total_amount_cents;
                    $bonus = (int) $payment->bonus_amount_cents;
                    $wallet = (int) $payment->wallet_amount_cents;

                    if ($bonus + $wallet !== $total) {
                        $this->addDifference(
                            ReconciliationCheck::COMPRA_TOTAL_DESCUADRADO,
                            'PURCHASE_PAYMENT',
                            $payment->public_id,
                            $payment->wallet_id,
                            $total,
                            $bonus + $wallet,
                            ['bonus_amount_cents' => $bonus, 'wallet_amount_cents' => $wallet]
                        );
                    }

                    if ($wallet > 0) {
                        $transaction = $transactions->get($payment->idempotency_key);

                        if (!$transaction) {
                            $this->addDifference(
                                ReconciliationCheck::COMPRA_SIN_MOVIMIENTO_WALLET,
                                'PURCHASE_PAYMENT',
                                $payment->public_id,
                                $payment->wallet_id,
                                -$wallet,
                                0,
                                ['idempotency_key' => $payment->idempotency_key]
                            );
                        } else {
                            $actual = $ledgerSums[strtolower((string) $transaction->public_id)] ?? 0;

                            if ($actual !== -$wallet) {
                                $this->addDifference(
                                    ReconciliationCheck::COMPRA_MONTO_WALLET_DIFERENTE,
                                    'PURCHASE_PAYMENT',
                                    $payment->public_id,
                                    $payment->wallet_id,
                                    -$wallet,
                                    $actual,
                                    ['transaction_id' => $transaction->public_id]
                                );
                            }
                        }
                    }

                    // Solo los pagos combinados guardan el desglose por bono.
                    if ($payment->requested_bonus_ids !== null) {
                        $bonusTotal = (int) ($bonusSums->get(strtolower((string) $payment->public_id))?->total_cents ?? 0);

                        if ($bonusTotal !== $bonus) {
                            $this->addDifference(
                                ReconciliationCheck::COMPRA_BONOS_DESCUADRADOS,
                                'PURCHASE_PAYMENT',
                                $payment->public_id,
                                $payment->wallet_id,
                                $bonus,
                                $bonusTotal,
                                []
                            );
                        }
                    }
                }
            });
    }

    private function checkBonuses(): void
    {
        $query = Bonus::query()->orderBy('id');

        if ($this->scopeWalletIds !== null) {
            $owners = Wallet::query()
                ->whereIn('public_id', $this->scopeWalletIds)
                ->pluck('owner_id')
                ->map(fn ($id) => (string) $id)
                ->all();

            $query->whereIn('beneficiary_id', $this->caseVariants($owners));
        }

        $query->chunk(self::CHUNK, function ($bonuses) {
            $sums = BonusLedgerEntry::query()
                ->whereIn('bonus_id', $bonuses->pluck('public_id')->all())
                ->groupBy('bonus_id')
                ->selectRaw('bonus_id, SUM(amount_cents) AS total_cents')
                ->get()
                ->keyBy(fn ($row) => strtolower((string) $row->bonus_id));

            foreach ($bonuses as $bonus) {
                $expected = (int) ($sums->get(strtolower((string) $bonus->public_id))?->total_cents ?? 0);
                $stored = (int) $bonus->remaining_amount_cents;

                if ($expected !== $stored) {
                    $this->addDifference(
                        ReconciliationCheck::BONO_SALDO_VS_LEDGER,
                        'BONUS',
                        $bonus->public_id,
                        null,
                        $expected,
                        $stored,
                        ['beneficiary_id' => $bonus->beneficiary_id]
                    );
                }
            }
        });
    }

    // ------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------

    /**
     * Restringe transacciones al alcance de wallets: las que tienen
     * movimientos en esas wallets o que referencian recargas, retiros o
     * compras de esas wallets.
     */
    private function scopeTransactions(Builder $query): Builder
    {
        if ($this->scopeWalletIds === null) {
            return $query;
        }

        $walletIds = $this->scopeWalletIds;

        $topUpIds = TopUp::whereIn('wallet_id', $walletIds)->pluck('public_id')->map(fn ($v) => (string) $v)->all();
        $withdrawalIds = Withdrawal::whereIn('wallet_id', $walletIds)->pluck('public_id')->map(fn ($v) => (string) $v)->all();
        $purchaseKeys = PurchasePayment::whereIn('wallet_id', $walletIds)->pluck('idempotency_key')->all();
        $refundTransactionIds = FinancialRefundRequest::whereIn('wallet_id', $walletIds)
            ->get(['request_transaction_id', 'financial_transaction_id'])
            ->flatMap(fn ($refund) => [$refund->request_transaction_id, $refund->financial_transaction_id])
            ->merge(PurchaseRefundRequest::whereIn('wallet_id', $walletIds)
                ->get(['request_transaction_id', 'financial_transaction_id'])
                ->flatMap(fn ($refund) => [$refund->request_transaction_id, $refund->financial_transaction_id]))
            ->filter()->unique()->all();

        return $query->where(function ($q) use ($walletIds, $topUpIds, $withdrawalIds, $purchaseKeys, $refundTransactionIds) {
            $q->whereIn(
                'public_id',
                LedgerEntry::query()->select('transaction_id')->whereIn('wallet_id', $walletIds)
            );

            if ($topUpIds !== []) {
                $q->orWhere(function ($sub) use ($topUpIds) {
                    $sub->where('reference_type', 'TOPUP')
                        ->whereIn('reference_id', $this->caseVariants($topUpIds));
                });
            }

            if ($withdrawalIds !== []) {
                $q->orWhere(function ($sub) use ($withdrawalIds) {
                    $sub->where('reference_type', 'WITHDRAWAL')
                        ->whereIn('reference_id', $this->caseVariants($withdrawalIds));
                });
            }

            if ($refundTransactionIds !== []) {
                $q->orWhereIn('public_id', $refundTransactionIds);
            }

            if ($purchaseKeys !== []) {
                $q->orWhereIn('idempotency_key', $purchaseKeys);
            }
        });
    }

    /**
     * @param  array<int, string>  $transactionIds
     * @return array<string, int>  transaction_id (minúsculas) => suma
     */
    private function ledgerSumsByTransaction(array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        return LedgerEntry::query()
            ->whereIn('transaction_id', $transactionIds)
            ->groupBy('transaction_id')
            ->selectRaw('transaction_id, SUM(amount_cents) AS total_cents')
            ->get()
            ->mapWithKeys(fn ($row) => [
                strtolower((string) $row->transaction_id) => (int) $row->total_cents,
            ])
            ->all();
    }

    /**
     * SQL Server devuelve los uniqueidentifier en mayúsculas, mientras
     * que el código los genera en minúsculas. Las columnas de texto que
     * guardan un UUID (reference_id, beneficiary_id) pueden tener
     * cualquiera de las dos formas.
     *
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    private function caseVariants(array $values): array
    {
        $variants = [];

        foreach ($values as $value) {
            $variants[] = strtolower($value);
            $variants[] = strtoupper($value);
            $variants[] = $value;
        }

        return array_values(array_unique($variants));
    }

    private function addDifference(
        ReconciliationCheck $check,
        string $entityType,
        string $entityId,
        ?string $walletId,
        ?int $expected,
        ?int $actual,
        array $details
    ): void {
        $this->differences[] = [
            'check_code' => $check,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'wallet_id' => $walletId,
            'expected_cents' => $expected,
            'actual_cents' => $actual,
            'difference_cents' => $expected !== null && $actual !== null
                ? $actual - $expected
                : null,
            'details' => $details === [] ? null : $details,
        ];

        $this->summary[$check->value] = ($this->summary[$check->value] ?? 0) + 1;
    }
}
