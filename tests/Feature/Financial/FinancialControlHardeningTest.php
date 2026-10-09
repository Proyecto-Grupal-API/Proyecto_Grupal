<?php

/*
| REQ-E2-2.10-01 — pruebas de los controles agregados sobre el corte 2.10:
| historial de límites, carreras, evidencia reproducible, rol inaccesible,
| idempotencia/locks de conciliación, caja (2.8) y job programado.
|
| Se ejecutan contra la conexión financiera configurada (sqlsrv), igual
| que el resto de tests/Feature/Financial. Los montos son datos de prueba.
*/

use App\Domains\Financial\Contracts\CashReconciliationSource;
use App\Domains\Financial\Contracts\FinancialRoleProvider;
use App\Domains\Financial\Data\CashDifference;
use App\Domains\Financial\Enums\AlertOutcome;
use App\Domains\Financial\Enums\AlertType;
use App\Domains\Financial\Enums\CashReconciliationStatus;
use App\Domains\Financial\Enums\LimitChangeType;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\ReconciliationCheck;
use App\Domains\Financial\Enums\ReconciliationStatus;
use App\Domains\Financial\Enums\ReconciliationTrigger;
use App\Domains\Financial\Enums\TopUpMethod;
use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use App\Domains\Financial\Exceptions\FinancialLimitExceededException;
use App\Domains\Financial\Exceptions\ReconciliationInProgressException;
use App\Domains\Financial\Jobs\RunFinancialReconciliation;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\Reconciliation;
use App\Domains\Financial\Models\ReconciliationDifference;
use App\Domains\Financial\Models\ReconciliationDifferenceObservation;
use App\Domains\Financial\Models\TopUp;
use App\Domains\Financial\Models\TransactionAlert;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\FinancialLimitService;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\ReconciliationService;
use App\Domains\Financial\Services\TopUpService;
use App\Domains\Financial\Services\UnusualActivityDetector;
use App\Domains\Financial\Support\FinancialCorrelation;
use App\Domains\Financial\Support\FinancialJobLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__ . '/Support/FinancialControlHelpers.php';

afterEach(function () {
    fcCleanup();
});

function fcHardToday(): string
{
    return now(config('financial.business_timezone'))->format('Y-m-d');
}

function fcRoleProviderDown(): void
{
    app()->instance(FinancialRoleProvider::class, new class implements FinancialRoleProvider {
        public function rolesOf(string $ownerType, string $ownerId): array
        {
            throw new FinancialDependencyUnavailableException('Identidad no disponible.');
        }
    });
}

function fcRoleProviderWith(array $roles): void
{
    app()->instance(FinancialRoleProvider::class, new class($roles) implements FinancialRoleProvider {
        public function __construct(private array $roles) {}

        public function rolesOf(string $ownerType, string $ownerId): array
        {
            return $this->roles;
        }
    });
}

// ------------------------------------------------------------------
// Historial de límites
// ------------------------------------------------------------------

test('records actor, reason and before/after for every limit change', function () {
    $wallet = fcWallet('hist-limit');
    $service = app(FinancialLimitService::class);

    $limit = $service->create([
        'name' => 'Recargas por operación',
        'subject_type' => 'OWNER',
        'subject_id' => $wallet->owner_id,
        'operation' => 'RECARGA',
        'period' => 'OPERACION',
        'metric' => 'MONTO',
        'max_amount_cents' => 10000,
        'action' => 'BLOQUEAR',
    ], FC_ACTOR, 'Alta para la prueba');

    $service->update($limit, ['max_amount_cents' => 20000], 'test-fc-auditor', 'Ajuste acordado');

    $changes = $limit->changes()->orderBy('id')->get();

    expect($changes)->toHaveCount(2)
        ->and($changes[0]->change_type)->toBe(LimitChangeType::CREACION)
        ->and($changes[0]->actor_id)->toBe(FC_ACTOR)
        ->and($changes[0]->reason)->toBe('Alta para la prueba')
        ->and($changes[0]->before)->toBeNull()
        ->and($changes[1]->change_type)->toBe(LimitChangeType::MODIFICACION)
        ->and($changes[1]->actor_id)->toBe('test-fc-auditor')
        ->and($changes[1]->reason)->toBe('Ajuste acordado')
        ->and($changes[1]->before['max_amount_cents'])->toBe(10000)
        ->and($changes[1]->after['max_amount_cents'])->toBe(20000)
        ->and($changes[1]->created_at)->not->toBeNull();
});

test('a rejected limit change leaves no history entry', function () {
    $wallet = fcWallet('hist-limit-rejected');
    $limit = fcLimit($wallet);

    expect(fn () => app(FinancialLimitService::class)->update(
        $limit,
        ['valid_from' => '2026-12-01T00:00:00Z', 'valid_until' => '2026-11-01T00:00:00Z'],
        FC_ACTOR,
        'Vigencia inválida'
    ))->toThrow(InvalidArgumentException::class);

    expect($limit->changes()->count())->toBe(1);
});

// ------------------------------------------------------------------
// Carreras y evidencia
// ------------------------------------------------------------------

test('concurrent requests that pass the preventive check are recorded as an explicit excess, without changing money', function () {
    $wallet = fcWallet('race-daily');

    fcLimit($wallet, [
        'period' => 'DIARIO',
        'max_amount_cents' => 10000,
        'action' => 'BLOQUEAR',
    ]);

    $topUps = app(TopUpService::class);

    // Ambas solicitudes se evalúan antes de que exista movimiento alguno:
    // el control preventivo no puede reservar el cupo (no hay reserva
    // atómica en esta arquitectura) y ambas pasan.
    $first = $topUps->create($wallet, 7000, TopUpMethod::EFECTIVO);
    $second = $topUps->create($wallet, 7000, TopUpMethod::EFECTIVO);

    $topUps->complete($first, fcKey('race-1'));
    $topUps->complete($second, fcKey('race-2'));

    $alerts = fcAlertsForWallet($wallet);

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->alert_type)->toBe(AlertType::LIMITE_EXCEDIDO)
        ->and($alerts[0]->outcome)->toBe(AlertOutcome::EXCEDENTE_NO_PREVENIDO)
        ->and($alerts[0]->observed_value)->toBe(14000)
        ->and($alerts[0]->threshold_value)->toBe(10000)
        ->and($alerts[0]->period_start)->not->toBeNull()
        ->and($alerts[0]->period_end)->not->toBeNull()
        ->and($alerts[0]->correlation_id)->not->toBeNull();

    // El movimiento no se modifica retroactivamente.
    expect($wallet->fresh()->available_balance_cents)->toBe(14000)
        ->and(TopUp::where('wallet_id', $wallet->public_id)->count())->toBe(2);
});

test('the observed value is reproducible: later movements do not change it', function () {
    $wallet = fcWallet('repro');

    fcLimit($wallet, [
        'period' => 'DIARIO',
        'max_amount_cents' => 5000,
        'action' => 'ALERTAR',
    ]);

    $ledger = app(LedgerService::class);
    $ledger->credit($wallet, 3000, MovementType::RECARGA, fcKey('repro-a'), 'TEST', null);
    $second = $ledger->credit($wallet->fresh(), 3000, MovementType::RECARGA, fcKey('repro-b'), 'TEST', null);

    $alert = fcAlertsForWallet($wallet)->first();
    expect($alert->observed_value)->toBe(6000);

    // Movimiento posterior en el mismo día.
    $ledger->credit($wallet->fresh(), 3000, MovementType::RECARGA, fcKey('repro-c'), 'TEST', null);

    // Re-evaluar el segundo movimiento debe dar la misma evidencia.
    $entry = LedgerEntry::where('transaction_id', $second->public_id)->first();
    $evaluation = app(FinancialLimitService::class)->evaluate(
        wallet: $wallet->fresh(),
        operation: MovementType::RECARGA,
        amountCents: 3000,
        at: $entry->created_at,
        excludeLedgerEntryId: $entry->public_id,
        beforeLedgerRowId: (int) $entry->id
    );

    expect($evaluation->violations[0]->observedValue)->toBe(6000);
});

test('a blocked request carries a stable code, the evaluation and the correlation of its alert', function () {
    $wallet = fcWallet('corr-blocked');
    fcLimit($wallet, ['max_amount_cents' => 1000]);

    app(FinancialCorrelation::class)->set('test-fc-correlation-1');

    try {
        app(TopUpService::class)->create($wallet, 5000, TopUpMethod::EFECTIVO);
        $this->fail('Se esperaba FinancialLimitExceededException.');
    } catch (FinancialLimitExceededException $exception) {
        $payload = $exception->toArray();
    }

    $alert = TransactionAlert::where('public_id', $payload['alert_id'])->first();

    expect($payload['code'])->toBe('FINANCIAL_LIMIT_EXCEEDED')
        ->and($payload['correlation_id'])->toBe('test-fc-correlation-1')
        ->and($payload['evaluation']['decision'])->toBe('BLOQUEADA')
        ->and($alert->correlation_id)->toBe('test-fc-correlation-1')
        ->and($alert->outcome)->toBe(AlertOutcome::BLOQUEADA)
        ->and(TopUp::where('wallet_id', $wallet->public_id)->count())->toBe(0);
});

test('a rolled back operation produces no alert even when it exceeds a limit', function () {
    $wallet = fcWallet('rollback-limit', 20000);
    fcLimit($wallet, ['operation' => 'PAGO', 'max_amount_cents' => 1000, 'action' => 'ALERTAR']);

    try {
        DB::connection('sqlsrv')->transaction(function () use ($wallet) {
            app(LedgerService::class)->debit($wallet, 5000, MovementType::PAGO, fcKey('rollback'), 'TEST', null);

            throw new RuntimeException('Falla simulada después del cargo.');
        });
    } catch (RuntimeException) {
    }

    expect(fcAlertsForWallet($wallet))->toHaveCount(0)
        ->and($wallet->fresh()->available_balance_cents)->toBe(20000);
});

// ------------------------------------------------------------------
// Rol inaccesible
// ------------------------------------------------------------------

test('a role limit with the identity module down rejects the request completely', function () {
    $wallet = fcWallet('role-down-guard');

    fcLimit($wallet, ['subject_type' => 'ROLE', 'subject_id' => 'test-fc-role', 'max_amount_cents' => 100]);
    fcRoleProviderDown();

    expect(fn () => app(TopUpService::class)->create($wallet, 500, TopUpMethod::EFECTIVO))
        ->toThrow(FinancialDependencyUnavailableException::class);

    expect(TopUp::where('wallet_id', $wallet->public_id)->count())->toBe(0);
});

test('a completed movement that cannot be evaluated is recorded instead of silently skipped', function () {
    $wallet = fcWallet('role-down-observer', 10000);

    fcLimit($wallet, [
        'subject_type' => 'ROLE',
        'subject_id' => 'test-fc-role',
        'operation' => 'PAGO',
        'max_amount_cents' => 100,
        'action' => 'ALERTAR',
    ]);
    fcRoleProviderDown();

    $transaction = app(LedgerService::class)->debit(
        $wallet,
        500,
        MovementType::PAGO,
        fcKey('role-down-observer'),
        'TEST',
        null
    );

    $alert = fcAlertsForWallet($wallet)->first();

    expect($transaction->status)->toBe(TransactionStatus::COMPLETADA)
        ->and($wallet->fresh()->available_balance_cents)->toBe(9500)
        ->and($alert)->not->toBeNull()
        ->and($alert->alert_type)->toBe(AlertType::CONTROL_NO_EVALUADO)
        ->and($alert->outcome)->toBe(AlertOutcome::NO_EVALUADA)
        ->and($alert->observed_value)->toBeNull()
        ->and($alert->threshold_value)->toBeNull()
        ->and(fcSameId($alert->transaction_id, $transaction->public_id))->toBeTrue();

    // Idempotente: revisar otra vez no duplica.
    app(UnusualActivityDetector::class)->inspectTransaction($transaction);
    expect(fcAlertsForWallet($wallet))->toHaveCount(1);
});

// ------------------------------------------------------------------
// Conciliación: repetición, idempotencia, lock
// ------------------------------------------------------------------

test('repeating a reconciliation keeps every run but does not duplicate differences', function () {
    $wallet = fcWallet('rec-repeat', 1000);
    Wallet::where('public_id', $wallet->public_id)->update(['available_balance_cents' => 900]);

    $service = app(ReconciliationService::class);
    $first = $service->run(fcHardToday(), 'test-fc-reconciler', [$wallet->public_id]);
    $second = $service->run(fcHardToday(), 'test-fc-reconciler', [$wallet->public_id]);

    $created = ReconciliationDifference::whereIn('reconciliation_id', [$first->public_id, $second->public_id])->get();

    expect(Reconciliation::where('executed_by', 'test-fc-reconciler')->count())->toBe(2)
        ->and($first->new_differences_count)->toBe($first->differences_count)
        ->and($second->differences_count)->toBe($first->differences_count)
        ->and($second->new_differences_count)->toBe(0)
        ->and($second->recurring_differences_count)->toBe($first->differences_count)
        ->and($created)->toHaveCount($first->differences_count)
        ->and($created->every(fn ($d) => $d->occurrences === 2))->toBeTrue()
        ->and($second->observedDifferences()->count())->toBe($first->differences_count)
        ->and(ReconciliationDifferenceObservation::where('reconciliation_id', $second->public_id)->where('is_new', false)->count())
        ->toBe($first->differences_count);

    // Sigue sin corregir nada.
    expect($wallet->fresh()->available_balance_cents)->toBe(900);
});

test('a difference that changes value is recorded as a new difference', function () {
    $wallet = fcWallet('rec-changed', 1000);
    $service = app(ReconciliationService::class);

    Wallet::where('public_id', $wallet->public_id)->update(['available_balance_cents' => 900]);
    $first = $service->run(fcHardToday(), 'test-fc-reconciler', [$wallet->public_id]);

    Wallet::where('public_id', $wallet->public_id)->update(['available_balance_cents' => 800]);
    $second = $service->run(fcHardToday(), 'test-fc-reconciler', [$wallet->public_id]);

    expect($second->new_differences_count)->toBeGreaterThan(0)
        ->and($first->public_id)->not->toBe($second->public_id);
});

test('the same idempotency key returns the registered run instead of executing again', function () {
    $wallet = fcWallet('rec-idem');
    $service = app(ReconciliationService::class);

    $first = $service->run(fcHardToday(), 'test-fc-reconciler', [$wallet->public_id], ReconciliationTrigger::API, 'test-fc-idem-1');
    expect($service->wasReplay())->toBeFalse();

    $again = $service->run(fcHardToday(), 'test-fc-reconciler', [$wallet->public_id], ReconciliationTrigger::API, 'test-fc-idem-1');

    expect($service->wasReplay())->toBeTrue()
        ->and($again->public_id)->toBe($first->public_id)
        ->and(Reconciliation::where('idempotency_key', 'test-fc-idem-1')->count())->toBe(1)
        ->and($first->trigger_source)->toBe(ReconciliationTrigger::API);

    $yesterday = now(config('financial.business_timezone'))->subDay()->format('Y-m-d');

    expect(fn () => $service->run($yesterday, 'test-fc-reconciler', [$wallet->public_id], ReconciliationTrigger::API, 'test-fc-idem-1'))
        ->toThrow(InvalidArgumentException::class, 'otra fecha o alcance');
});

test('a concurrent reconciliation for the same date is rejected, and an expired lock is recovered', function () {
    $wallet = fcWallet('rec-lock');
    $locks = app(FinancialJobLock::class);
    $key = 'reconciliation:' . fcHardToday();

    $token = $locks->acquire($key, 600);
    expect($token)->not->toBeNull();

    expect(fn () => app(ReconciliationService::class)->run(fcHardToday(), 'test-fc-reconciler', [$wallet->public_id]))
        ->toThrow(ReconciliationInProgressException::class);

    expect(Reconciliation::where('executed_by', 'test-fc-reconciler')->count())->toBe(0);

    $locks->release($key, $token);

    // Lock vencido de un proceso caído: se puede tomar de nuevo.
    DB::connection('sqlsrv')->table('financial_job_locks')->insert([
        'lock_key' => $key,
        'owner_token' => 'test-fc-stale',
        'acquired_at' => now()->subHour(),
        'expires_at' => now()->subMinute(),
    ]);

    $run = app(ReconciliationService::class)->run(fcHardToday(), 'test-fc-reconciler', [$wallet->public_id]);

    expect($run->status)->not->toBe(ReconciliationStatus::FALLIDA)
        ->and($locks->isHeld($key))->toBeFalse();
});

// ------------------------------------------------------------------
// Caja (2.8)
// ------------------------------------------------------------------

test('without the cash integration the run declares it explicitly', function () {
    $wallet = fcWallet('cash-pending');

    $run = app(ReconciliationService::class)->run(fcHardToday(), 'test-fc-reconciler', [$wallet->public_id]);

    expect($run->cash_status)->toBe(CashReconciliationStatus::NO_INTEGRADA)
        ->and($run->checks_executed)->toBe(count(ReconciliationCheck::internal()));
});

test('cash differences keep their authorized references and never adjust balances', function () {
    $wallet = fcWallet('cash-integrated', 5000);

    app()->instance(CashReconciliationSource::class, new class($wallet->public_id) implements CashReconciliationSource {
        public function __construct(private string $walletId) {}

        public function isIntegrated(): bool
        {
            return true;
        }

        public function differences(CarbonImmutable $windowStart, CarbonImmutable $windowEnd, ?array $walletIds): iterable
        {
            return [new CashDifference('CASH_SHIFT', 'test-fc-shift-1', $this->walletId, 5000, 4500, ['cash_shift_id' => 'test-fc-shift-1'])];
        }
    });

    $run = app(ReconciliationService::class)->run(fcHardToday(), 'test-fc-reconciler', [$wallet->public_id]);

    $difference = $run->observedDifferences()
        ->where('check_code', ReconciliationCheck::CAJA_DIFERENCIA->value)
        ->first();

    expect($run->cash_status)->toBe(CashReconciliationStatus::COMPARADA)
        ->and($run->checks_executed)->toBe(count(ReconciliationCheck::cases()))
        ->and($difference)->not->toBeNull()
        ->and($difference->details['references']['cash_shift_id'])->toBe('test-fc-shift-1')
        ->and($difference->difference_cents)->toBe(-500)
        ->and($wallet->fresh()->available_balance_cents)->toBe(5000)
        ->and(LedgerEntry::where('wallet_id', $wallet->public_id)->count())->toBe(1);
});

// ------------------------------------------------------------------
// Job programado y comando
// ------------------------------------------------------------------

test('the scheduled job records the trigger and attempt of its run', function () {
    (new RunFinancialReconciliation(fcHardToday(), 'test-fc-scheduler'))
        ->handle(app(ReconciliationService::class));

    $run = Reconciliation::where('executed_by', 'test-fc-scheduler')->first();

    expect($run)->not->toBeNull()
        ->and($run->trigger_source)->toBe(ReconciliationTrigger::PROGRAMADA)
        ->and($run->attempt)->toBe(1)
        ->and($run->idempotency_key)->toBe('scheduled:' . fcHardToday() . ':attempt:1');
});

test('the command can dispatch the reconciliation job for the scheduler', function () {
    Queue::fake();

    $this->artisan('financial:reconcile', [
        'date' => fcHardToday(),
        '--executed-by' => 'test-fc-command',
        '--dispatch' => true,
    ])->assertExitCode(0);

    Queue::assertPushed(
        RunFinancialReconciliation::class,
        fn ($job) => $job->businessDate === fcHardToday() && $job->executedBy === 'test-fc-command'
    );
});

test('the command reports a reconciliation already in progress', function () {
    $key = 'reconciliation:' . fcHardToday();
    $token = app(FinancialJobLock::class)->acquire($key, 600);

    $this->artisan('financial:reconcile', [
        'date' => fcHardToday(),
        '--executed-by' => 'test-fc-command',
    ])->assertExitCode(1);

    app(FinancialJobLock::class)->release($key, $token);
});
