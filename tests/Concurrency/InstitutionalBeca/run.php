<?php
use App\Domains\Financial\Models\{InstitutionalBonusBudget, InstitutionalBudgetOperation, Bonus, Wallet,
    PurchasePayment, PurchaseRefundRequest};
use App\Domains\Financial\Services\{InstitutionalBudgetService, InstitutionalBecaIssuanceService,
    InstitutionalBecaReleaseService, InstitutionalSettlementService, InstitutionalObligationQueryService,
    PurchaseRefundService, WalletService};
use App\Domains\Financial\Contracts\InstitutionalBudgetAuthorization;
use App\Domains\Financial\Enums\WalletType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\{DB, Http};
use Symfony\Component\Process\Process;

require __DIR__.'/bootstrap.php';
$run = bin2hex(random_bytes(16));
$dir = storage_path('framework/testing/beca-concurrency-'.$run);
if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('No se pudo crear el directorio de prueba.');
$reportPath = storage_path('logs/beca-concurrency-'.$run.'.json');
$report = ['run_id' => $run, 'database' => 'campus_digital_financial_testing', 'cases' => [],
    'real_http_sent' => false, 'production_authority_verified' => false, 'cleanup' => 'PENDING'];
$fixtures = []; $error = null; $locked = false;
try {
    $lock = $sql->selectOne("SET NOCOUNT ON; DECLARE @r int; EXEC @r = sp_getapplock @Resource = 'beca-concurrency-testing', @LockMode = 'Exclusive', @LockOwner = 'Session', @LockTimeout = 0; SELECT @r AS code");
    becaAssert((int)$lock->code >= 0, 'Ya existe otra ejecucion de esta prueba.');
    $locked = true;
    // No migrations, drop, truncate, HTTP calls or TestCase bootstrap are executed here.
    foreach (['institutional_bonus_budgets','institutional_budget_operations','bonus_budget_commitments',
        'institutional_bonus_obligations','institutional_bonus_refunds','institutional_bonus_settlements',
        'purchase_marketplace_links','financial_team4_outbox','financial_team4_streams','financial_job_locks'] as $table) {
        becaAssert(Illuminate\Support\Facades\Schema::connection('sqlsrv')->hasTable($table), 'Falta esquema de testing: '.$table);
    }

    $f = fixture($run, 'overspend', $fixtures);
    $jobs = [payJob($f, 2000, $run.'-a', 'order-a'), payJob($f, 2000, $run.'-b', 'order-b')];
    $race = race($f, $jobs, $dir.'/overspend', false);
    $ok = array_keys(array_filter($race, fn($r) => $r['status'] === 'OK'));
    becaAssert(count($ok) === 1 && count(array_filter($race, fn($r) => $r['status'] === 'CONFLICT')) === 1,
        'Dos compras de 2000 no pueden consumir un bono de 3000.');
    $replay = becaOperation($jobs[$ok[0]]);
    becaAssert($replay['replayed'] && $replay['purchase_payment_id'] === $race[$ok[0]]['value']['purchase_payment_id'], 'Replay de compra inconsistente.');
    checkState($f, 1000, 2000, 1);
    $report['cases'][] = ['name' => 'competing_purchases', 'passed' => true, 'workers' => $race];

    $f = fixture($run, 'payment-retry', $fixtures);
    $job = payJob($f, 1000, $run.'-same-payment', 'same-order');
    $race = race($f, [$job, $job], $dir.'/payment-retry', true);
    becaAssert(count(array_filter($race, fn($r) => $r['status'] === 'OK')) >= 1, 'Ningun pago termino.');
    $first = becaOperation($job); $second = becaOperation($job);
    becaAssert($first['replayed'] && $second['replayed'] && $first['purchase_payment_id'] === $second['purchase_payment_id'], 'Pago duplicado o replay inconsistente.');
    foreach ($race as $r) if ($r['status'] === 'OK') {
        becaAssert($r['value']['purchase_payment_id'] === $first['purchase_payment_id'], 'Los procesos devolvieron compras distintas.');
    }
    $changed = $job; $changed['amount'] = 1001;
    try { becaOperation($changed); throw new RuntimeException('Se acepto una llave con importe cambiado.'); }
    catch (Symfony\Component\HttpKernel\Exception\ConflictHttpException) {}
    checkState($f, 2000, 1000, 1);
    $report['cases'][] = ['name' => 'same_payment_key', 'passed' => true, 'workers' => $race];

    $f = fixture($run, 'refund-retry', $fixtures);
    $payment = becaOperation(payJob($f, 1000, $run.'-refund-pay', 'refund-order'));
    $refund = refund($f, $payment['purchase_payment_id'], 400, $run.'-refund-request');
    $job = ['fixture' => $f, 'action' => 'refund', 'refund' => strtolower($refund->public_id), 'key' => $run.'-same-refund'];
    $race = race($f, [$job, $job], $dir.'/refund-retry', true);
    becaAssert(count(array_filter($race, fn($r) => $r['status'] === 'OK')) >= 1, 'Ninguna devolucion termino.');
    $first = becaOperation($job); $second = becaOperation($job);
    becaAssert($first === $second, 'La devolucion repetida produjo otra transaccion.');
    foreach ($race as $r) if ($r['status'] === 'OK') becaAssert($r['value'] === $first, 'Los procesos devolvieron transacciones distintas.');
    checkState($f, 2400, 600, 2);
    becaAssert((int)DB::connection('sqlsrv')->table('institutional_bonus_refunds')->where('refund_request_id', $refund->public_id)->count() === 1, 'Evidencia duplicada de devolucion.');
    $report['cases'][] = ['name' => 'same_refund_key', 'passed' => true, 'workers' => $race];

    // Finish this same fixture's lifecycle: external settlement, cancellation, reconciliation.
    // This records synthetic test evidence; it does not execute or certify a bank payment.
    $data = ['amount_cents' => 600, 'currency' => 'MXN', 'payment_provider' => 'concurrency-test',
        'payment_reference' => $run.'-external-payment', 'recipient_reference' => 'concurrency-test-recipient',
        'paid_at' => now()->toISOString(), 'reason' => 'Evidencia exclusiva de prueba de concurrencia'];
    $version = InstitutionalBonusBudget::where('public_id', $f['budget'])->firstOrFail()->version;
    $settlement = app(InstitutionalSettlementService::class)->record($f['operator'], $payment['obligation_id'], $version, $data, $run.'-settlement');
    $again = app(InstitutionalSettlementService::class)->record($f['operator'], $payment['obligation_id'], $version, $data, $run.'-settlement');
    becaAssert($again['replayed'] && $settlement['settlement_id'] === $again['settlement_id'], 'Liquidacion repetida.');
    $version = InstitutionalBonusBudget::where('public_id', $f['budget'])->firstOrFail()->version;
    app(InstitutionalBecaReleaseService::class)->cancel($f['operator'], $f['budget'], $f['bonus'], $version,
        'Cierre del ciclo de prueba', $run.'-cancel');
    checkState($f, 0, 600, 2);
    $debt = DB::connection('sqlsrv')->table('institutional_bonus_obligations')->where('public_id', $payment['obligation_id'])->first();
    becaAssert((int)$debt->refunded_amount_cents === 400 && (int)$debt->settled_amount_cents === 600, 'Deuda final incorrecta.');
    becaAssert(Bonus::where('public_id', $f['bonus'])->firstOrFail()->status->value === 'CANCELADO', 'El bono no termino cancelado.');
    $report['cases'][] = ['name' => 'refund_settlement_cancel_reconcile', 'passed' => true,
        'refunded_cents' => 400, 'settled_cents' => 600, 'unpaid_cents' => 0, 'committed_cents' => 0];

    $f = fixture($run, 'reserve-settle', $fixtures);
    $payment = becaOperation(payJob($f, 1000, $run.'-rs-pay', 'reserve-settle'));
    $reserve = reserveJob($f, $payment, 1000, $run.'-rs-reserve');
    $settle = settleJob($f, $payment, 1000, $run.'-rs-settle');
    $race = race($f, [$reserve, $settle], $dir.'/reserve-settle', false, $payment['purchase_payment_id']);
    oneWinner($race);
    if ($race[0]['status'] === 'OK') {
        $r = PurchaseRefundRequest::where('public_id', $race[0]['value']['refund_id'])->firstOrFail();
        $r = app(PurchaseRefundService::class)->approve($r, $f['operator'], 'Revision de reserva ganadora');
        becaOperation(['fixture' => $f, 'action' => 'refund', 'refund' => strtolower($r->public_id), 'key' => $run.'-rs-complete']);
        obligationState($f, $payment, 1000, 0, 0);
        checkState($f, 3000, 0, 2);
    } else {
        becaAssert(!PurchaseRefundRequest::where('purchase_payment_id', $payment['purchase_payment_id'])->exists(), 'La reserva rechazada dejo una solicitud.');
        $replay = becaOperation($settle);
        becaAssert($replay['replayed'] && $replay['settlement_id'] === $race[1]['value']['settlement_id'], 'Replay de liquidacion inconsistente.');
        obligationState($f, $payment, 0, 1000, 0);
        checkState($f, 2000, 1000, 1);
    }
    $report['cases'][] = ['name' => 'refund_reservation_vs_full_settlement', 'passed' => true, 'workers' => $race];

    $f = fixture($run, 'complete-settle', $fixtures);
    $payment = becaOperation(payJob($f, 1000, $run.'-cs-pay', 'complete-settle'));
    $r = refund($f, $payment['purchase_payment_id'], 400, $run.'-cs-request');
    $complete = ['fixture' => $f, 'action' => 'refund', 'refund' => strtolower($r->public_id), 'key' => $run.'-cs-complete'];
    $settle = settleJob($f, $payment, 700, $run.'-cs-settle');
    $race = race($f, [$complete, $settle], $dir.'/complete-settle', false);
    becaAssert($race[0]['status'] === 'OK' && $race[1]['status'] === 'CONFLICT', 'La liquidacion invadio el importe reservado o devuelto.');
    obligationState($f, $payment, 400, 0, 0);
    checkState($f, 2400, 600, 2);
    becaAssert(!DB::connection('sqlsrv')->table('institutional_bonus_settlements')->where('budget_id', $f['budget'])->exists(), 'Liquidacion rechazada con evidencia residual.');
    $report['cases'][] = ['name' => 'refund_completion_vs_excess_settlement', 'passed' => true, 'workers' => $race];

    $f = fixture($run, 'two-reserves', $fixtures);
    $payment = becaOperation(payJob($f, 1000, $run.'-tr-pay', 'two-reserves'));
    $jobs = [reserveJob($f, $payment, 700, $run.'-tr-a'), reserveJob($f, $payment, 700, $run.'-tr-b')];
    $race = race($f, $jobs, $dir.'/two-reserves', false, $payment['purchase_payment_id']);
    oneWinner($race);
    obligationState($f, $payment, 0, 0, 700);
    becaAssert(PurchaseRefundRequest::where('purchase_payment_id', $payment['purchase_payment_id'])->count() === 1, 'Reservas duplicadas.');
    checkState($f, 2000, 1000, 1);
    $winner = $race[0]['status'] === 'OK' ? 0 : 1;
    $replay = becaOperation($jobs[$winner]);
    becaAssert($replay === $race[$winner]['value'], 'Replay de reserva inconsistente.');
    $report['cases'][] = ['name' => 'competing_refund_reservations', 'passed' => true, 'workers' => $race];

    $f = fixture($run, 'two-settlements', $fixtures);
    $payment = becaOperation(payJob($f, 1000, $run.'-ts-pay', 'two-settlements'));
    $jobs = [settleJob($f, $payment, 700, $run.'-ts-a'), settleJob($f, $payment, 700, $run.'-ts-b')];
    $race = race($f, $jobs, $dir.'/two-settlements', false);
    oneWinner($race);
    obligationState($f, $payment, 0, 700, 0);
    becaAssert(DB::connection('sqlsrv')->table('institutional_bonus_settlements')->where('budget_id', $f['budget'])->count() === 1, 'Liquidaciones duplicadas.');
    checkState($f, 2000, 1000, 1);
    $winner = $race[0]['status'] === 'OK' ? 0 : 1;
    $replay = becaOperation($jobs[$winner]);
    becaAssert($replay['replayed'] && $replay['settlement_id'] === $race[$winner]['value']['settlement_id'], 'Replay de liquidacion competidora inconsistente.');
    $report['cases'][] = ['name' => 'competing_settlements', 'passed' => true, 'workers' => $race];

    // Retain a genuine pre-existing obligation in every release race.
    $f = fixture($run, 'pay-cancel', $fixtures);
    becaOperation(payJob($f, 1000, $run.'-pc-initial', 'initial'));
    $pay = payJob($f, 500, $run.'-pc-pay', 'competing');
    $cancel = releaseJob($f, 'cancel', $run.'-pc-cancel');
    $race = race($f, [$pay, $cancel], $dir.'/pay-cancel', false);
    oneWinner($race);
    $consumed = $race[0]['status'] === 'OK' ? 1500 : 1000;
    if ($race[0]['status'] === 'OK') {
        // A stale review must be renewed explicitly; do not hide a version conflict with an automatic retry.
        $cancel['version'] = InstitutionalBonusBudget::where('public_id', $f['budget'])->firstOrFail()->version;
        $released = becaOperation($cancel);
    } else $released = $race[1]['value'];
    releaseState($f, $consumed, 'CANCELADO', $consumed === 1500 ? 2 : 1);
    $replay = becaOperation($cancel);
    becaAssert($replay['replayed'] && $replay['operation_id'] === $released['operation_id'], 'Cancelacion repetida o replay inconsistente.');
    assertPaymentRejected($pay);
    releaseState($f, $consumed, 'CANCELADO', $consumed === 1500 ? 2 : 1);
    $report['cases'][] = ['name' => 'partial_consumption_vs_cancellation', 'passed' => true, 'workers' => $race,
        'consumed_cents' => $consumed, 'released_cents' => 3000 - $consumed];

    $f = fixture($run, 'exhaust-cancel', $fixtures);
    becaOperation(payJob($f, 1000, $run.'-ec-initial', 'initial'));
    $pay = payJob($f, 2000, $run.'-ec-pay', 'competing');
    $cancel = releaseJob($f, 'cancel', $run.'-ec-cancel');
    $race = race($f, [$pay, $cancel], $dir.'/exhaust-cancel', false);
    oneWinner($race);
    if ($race[0]['status'] === 'OK') {
        releaseState($f, 3000, 'AGOTADO', 2);
        $cancel['version'] = InstitutionalBonusBudget::where('public_id', $f['budget'])->firstOrFail()->version;
        try { becaOperation($cancel); throw new RuntimeException('Se libero presupuesto de un bono agotado.'); }
        catch (Symfony\Component\HttpKernel\Exception\ConflictHttpException) {}
        releaseState($f, 3000, 'AGOTADO', 2);
    } else {
        releaseState($f, 1000, 'CANCELADO', 1);
        assertPaymentRejected($pay);
    }
    $report['cases'][] = ['name' => 'full_consumption_vs_cancellation', 'passed' => true, 'workers' => $race];

    $f = fixture($run, 'before-expiry', $fixtures);
    becaOperation(payJob($f, 1000, $run.'-be-initial', 'initial'));
    $expiry = Bonus::where('public_id', $f['bonus'])->firstOrFail()->expires_at->toImmutable();
    $clock = $expiry->subSecond()->toISOString();
    $pay = payJob($f, 500, $run.'-be-pay', 'competing') + ['clock' => $clock];
    $expire = releaseJob($f, 'expire', $run.'-be-expire') + ['clock' => $clock];
    $race = race($f, [$pay, $expire], $dir.'/before-expiry', false);
    becaAssert($race[0]['status'] === 'OK' && $race[1]['status'] === 'CONFLICT', 'Se permitio vencer antes de la fecha limite o se rechazo consumo vigente.');
    becaWithClock($clock, fn () => checkState($f, 1500, 1500, 2, 2));
    $expire['clock'] = $expiry->toISOString();
    $expire['version'] = InstitutionalBonusBudget::where('public_id', $f['budget'])->firstOrFail()->version;
    $released = becaOperation($expire);
    becaAssert($released['released_amount_cents'] === 1500, 'El vencimiento no preservo el consumo anterior.');
    becaWithClock($expire['clock'], fn () => releaseState($f, 1500, 'EXPIRADO', 2));
    $report['cases'][] = ['name' => 'consumption_vs_expiration_before_deadline', 'passed' => true, 'workers' => $race,
        'clock' => $clock, 'expiry' => $expiry->toISOString(), 'released_cents' => 1500];

    $f = fixture($run, 'at-expiry', $fixtures);
    becaOperation(payJob($f, 1000, $run.'-ae-initial', 'initial'));
    $clock = Bonus::where('public_id', $f['bonus'])->firstOrFail()->expires_at->toISOString();
    $pay = payJob($f, 500, $run.'-ae-pay', 'competing') + ['clock' => $clock];
    $expire = releaseJob($f, 'expire', $run.'-ae-expire') + ['clock' => $clock];
    $race = race($f, [$pay, $expire], $dir.'/at-expiry', false);
    becaAssert($race[0]['status'] === 'CONFLICT' && $race[1]['status'] === 'OK', 'En la fecha limite solo puede confirmarse el vencimiento.');
    becaWithClock($clock, fn () => releaseState($f, 1000, 'EXPIRADO', 1));
    $replay = becaOperation($expire);
    becaAssert($replay['replayed'] && $replay['operation_id'] === $race[1]['value']['operation_id']
        && $replay['released_amount_cents'] === 2000, 'Vencimiento duplicado o importe incorrecto.');
    assertPaymentRejected($pay);
    becaWithClock($clock, fn () => releaseState($f, 1000, 'EXPIRADO', 1));
    $report['cases'][] = ['name' => 'consumption_vs_expiration_at_deadline', 'passed' => true, 'workers' => $race,
        'clock' => $clock, 'released_cents' => 2000];
    $f = fixture($run, 'treasury', $fixtures);
    $treasurer = bin2hex(random_bytes(12));
    $authority = new class($treasurer, $f['budget']) implements InstitutionalBudgetAuthorization {
        public array $checks = [];
        public bool $revokeUnderLock = true;

        public function __construct(private readonly string $treasurer, private readonly string $budget) {}

        public function allows(string $actorId, string $action, ?string $budgetId = null): bool
        {
            $this->checks[] = $actorId;

            return $actorId === $this->treasurer && $action === 'budget.commit' && $budgetId === $this->budget
                && (! $this->revokeUnderLock || count($this->checks) === 1);
        }
    };
    app()->instance(InstitutionalBudgetAuthorization::class, $authority);
    $reference = 'beca:'.bin2hex(random_bytes(12));
    $payload = ['award_reference' => $reference, 'external_reference' => $reference, 'beneficiary_id' => $f['buyer'],
        'type' => 'BECA', 'amount_cents' => 1000, 'valid_from' => now()->subMinute()->toISOString(),
        'expires_at' => now()->addDay()->toISOString(), 'combinable' => false, 'allows_partial_use' => true,
        'reason' => 'Aval de Comunidad en pruebas', 'restrictions' => []];
    $issue = fn () => app(InstitutionalBecaIssuanceService::class)->issue($f['operator'], $f['budget'], 2, $payload,
        $reference, communityFingerprint: hash('sha256', $reference), treasuryActor: $treasurer);
    try {
        $issue();
        throw new RuntimeException('La revocación bajo bloqueo no detuvo la emisión.');
    } catch (AuthorizationException) {}
    becaAssert($authority->checks === [$treasurer, $treasurer]
        && ! InstitutionalBudgetOperation::where('idempotency_key', $reference)->exists(),
        'Se consultó al actor incorrecto o se registró una emisión denegada.');
    $authority->revokeUnderLock = false;
    $issued = $issue();
    becaAssert(! $issued['replayed'] && $issued['operation']->actor_id === $f['operator']
        && $issued['operation']->after_data['community_treasury_actor_id'] === $treasurer,
        'La emisión no conservó las identidades separadas.');
    $report['cases'][] = ['name' => 'community_beca_separate_issuer_and_treasurer', 'passed' => true];
    Http::assertNothingSent();
} catch (Throwable $e) {
    $error = $e;
    $report['failure'] = ['exception' => get_class($e), 'message' => $e->getMessage()];
} finally {
    while ($sql->transactionLevel() > 0) $sql->rollBack();
    try {
        foreach (array_reverse($fixtures) as $f) cleanup($f);
        $report['cleanup'] = 'OWNED_FIXTURES_REMOVED';
    } catch (Throwable $cleanupError) {
        $report['cleanup'] = 'FAILED';
        $report['cleanup_error'] = $cleanupError->getMessage();
        $error ??= $cleanupError;
    }
    if ($locked) $sql->statement("EXEC sp_releaseapplock @Resource = 'beca-concurrency-testing', @LockOwner = 'Session'");
    $report['passed'] = $error === null;
    file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}
foreach ($report['cases'] as $case) echo 'PASS '.$case['name'].PHP_EOL;
echo 'Informe: '.$reportPath.PHP_EOL;
if ($error) { fwrite(STDERR, 'FAIL '.$error->getMessage().PHP_EOL); exit(1); }
echo 'OK: 11 carreras reales, cierre del ciclo y autoridades BECA separadas; solo datos de testing.'.PHP_EOL;

function fixture(string $run, string $name, array &$fixtures): array
{
    $f = ['run' => $run, 'name' => $name, 'wallet' => null, 'budget' => null, 'bonus' => null,
        'operator' => null, 'buyer' => null];
    // Track partial setup so failures do not orphan a successfully created user or budget.
    $fixtures[] = &$f;
    $operator = App\Models\User::factory()->create(['email' => $run.'-'.$name.'-operator@concurrency.test',
        'email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $f['operator'] = (string)$operator->getKey();
    $buyer = App\Models\User::factory()->create(['email' => $run.'-'.$name.'-buyer@concurrency.test',
        'email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $f['buyer'] = (string)$buyer->getKey();
    becaTestBindings($f['operator'], $f['buyer']);
    $f['wallet'] = strtolower(app(WalletService::class)->create('USER', $f['buyer'], WalletType::USUARIO)->public_id);
    $start = now()->subDay()->startOfSecond(); $end = now()->addMonth()->startOfSecond();
    $approval = app(InstitutionalBudgetService::class)->approve($f['operator'], [
        'institution_reference' => 'concurrency-test', 'approval_reference' => $run.'-'.$name,
        'payer_reference' => 'concurrency-test-payer', 'purpose' => 'Prueba aislada BECA',
        'approved_amount_cents' => 10000, 'currency' => 'MXN', 'reason' => 'Presupuesto exclusivo de testing',
        'valid_from' => $start->toISOString(), 'expires_at' => $end->toISOString()], $run.'-'.$name.'-approve');
    $f['budget'] = strtolower($approval['operation']->budget_id);
    $issued = app(InstitutionalBecaIssuanceService::class)->issue($f['operator'], $f['budget'], 1, [
        'award_reference' => $run.'-'.$name.'-award', 'beneficiary_id' => $f['buyer'], 'type' => 'BECA',
        'amount_cents' => 3000, 'valid_from' => now()->subMinute()->startOfSecond()->toISOString(),
        'expires_at' => now()->addDay()->startOfSecond()->toISOString(), 'combinable' => true, 'allows_partial_use' => true,
        'reason' => 'Bono exclusivo de testing', 'external_reference' => $run.'-'.$name, 'restrictions' => []], $run.'-'.$name.'-issue');
    $f['bonus'] = $issued['bonus_id'];
    return $f;
}

function payJob(array $f, int $amount, string $key, string $order): array
{
    return ['fixture' => $f, 'action' => 'pay', 'amount' => $amount, 'key' => $key,
        'market' => ['business_id' => 'concurrency-'.$f['run'], 'order_id' => $f['run'].'-'.$f['name'].'-'.$order, 'items' => []]];
}

function refund(array $f, string $paymentId, int $amount, string $key): PurchaseRefundRequest
{
    $service = app(PurchaseRefundService::class);
    $r = $service->request(PurchasePayment::where('public_id', $paymentId)->firstOrFail(), 0,
        [['bonus_id' => $f['bonus'], 'amount_cents' => $amount]], $key, $f['buyer'], 'Devolucion de testing');
    return $service->approve($r, $f['operator'], 'Revision exclusiva de testing');
}

function reserveJob(array $f, array $payment, int $amount, string $key): array
{
    return ['fixture' => $f, 'action' => 'reserve_refund', 'payment' => $payment['purchase_payment_id'],
        'amount' => $amount, 'key' => $key];
}

function settleJob(array $f, array $payment, int $amount, string $key): array
{
    $createdAt = PurchasePayment::where('public_id', $payment['purchase_payment_id'])->firstOrFail()->created_at;
    // DATETIME rounding must not produce evidence dated before its purchase.
    becaWait(fn () => now()->gte($createdAt), 'El reloj local precede a la compra persistida.', 2);
    return ['fixture' => $f, 'action' => 'settle', 'obligation' => $payment['obligation_id'],
        'version' => InstitutionalBonusBudget::where('public_id', $f['budget'])->firstOrFail()->version, 'key' => $key,
        'data' => ['amount_cents' => $amount, 'currency' => 'MXN', 'payment_provider' => 'concurrency-test',
            'payment_reference' => $key.'-external', 'recipient_reference' => 'concurrency-test-recipient',
            'paid_at' => now()->toISOString(), 'reason' => 'Liquidacion exclusiva de prueba concurrente']];
}

function oneWinner(array $results): void
{
    becaAssert(count(array_filter($results, fn($r) => $r['status'] === 'OK')) === 1
        && count(array_filter($results, fn($r) => $r['status'] === 'CONFLICT')) === 1,
        'Se esperaba exactamente una operacion aprobada y un rechazo.');
}

function releaseJob(array $f, string $action, string $key): array
{
    return ['fixture' => $f, 'action' => $action, 'key' => $key,
        'version' => InstitutionalBonusBudget::where('public_id', $f['budget'])->firstOrFail()->version,
        'reason' => 'Liberar remanente en prueba de concurrencia'];
}

function assertPaymentRejected(array $job): void
{
    // An earlier successful payment may legitimately replay after closure. Test a NEW debit instead.
    $job['key'] .= '-after-close';
    $job['market']['order_id'] .= '-after-close';
    try { becaOperation($job); throw new RuntimeException('Se acepto una compra nueva despues del cierre.'); }
    catch (Symfony\Component\HttpKernel\Exception\ConflictHttpException) {}
}

function releaseState(array $f, int $consumed, string $status, int $purchases): void
{
    checkState($f, 0, $consumed, $purchases, $purchases);
    $sql = DB::connection('sqlsrv');
    $bonus = Bonus::where('public_id', $f['bonus'])->firstOrFail();
    becaAssert($bonus->status->value === $status && $bonus->remaining_amount_cents === 0, 'Estado terminal de bono incorrecto.');
    $debt = $sql->table('institutional_bonus_obligations')->where('budget_id', $f['budget']);
    becaAssert((clone $debt)->count() === $purchases && (int)(clone $debt)->sum('amount_cents') === $consumed
        && (int)(clone $debt)->sum('refunded_amount_cents') === 0 && (int)(clone $debt)->sum('settled_amount_cents') === 0,
        'El cierre altero la obligacion consumida.');
    $released = 3000 - $consumed;
    $entries = $sql->table('bonus_ledger_entries')->where('bonus_id', $f['bonus'])->where('reference_type', 'BUDGET_RELEASE');
    becaAssert((clone $entries)->count() === ($released > 0 ? 1 : 0)
        && (int)(clone $entries)->sum('amount_cents') === -$released, 'Liberacion duplicada o importe distinto del remanente.');
    $ops = InstitutionalBudgetOperation::where('budget_id', $f['budget'])->whereIn('action', ['CANCEL_BECA','EXPIRE_BECA'])->get();
    becaAssert($ops->count() === ($released > 0 ? 1 : 0), 'Operacion de cierre duplicada o inesperada.');
    if ($released > 0) becaAssert($ops->first()->after_data['released_amount_cents'] === $released, 'La auditoria libero importe consumido.');
    $budget = InstitutionalBonusBudget::where('public_id', $f['budget'])->firstOrFail();
    becaAssert(10000 - $budget->committed_amount_cents - $budget->consumed_amount_cents === 10000 - $consumed,
        'Disponibilidad presupuestaria incorrecta despues del cierre.');
}

function obligationState(array $f, array $payment, int $refunded, int $settled, int $reserved): void
{
    $sql = DB::connection('sqlsrv');
    $debt = $sql->table('institutional_bonus_obligations')->where('public_id', $payment['obligation_id'])->first();
    $pending = (int)$sql->table('purchase_refund_requests')->where('purchase_payment_id', $payment['purchase_payment_id'])
        ->whereIn('status', ['PENDIENTE','APROBADA'])->sum('total_amount_cents');
    becaAssert($debt && (int)$debt->amount_cents === 1000 && (int)$debt->refunded_amount_cents === $refunded
        && (int)$debt->settled_amount_cents === $settled && $pending === $reserved
        && $refunded + $settled + $reserved <= 1000, 'Importe devuelto, liquidado o reservado inconsistente.');
    $refundEvidence = (int)$sql->table('institutional_bonus_refunds')->where('obligation_id', $payment['obligation_id'])->sum('amount_cents');
    $settlementEvidence = (int)$sql->table('institutional_bonus_settlements')->where('obligation_id', $payment['obligation_id'])->sum('amount_cents');
    becaAssert($refundEvidence === $refunded && $settlementEvidence === $settled, 'Los contadores no coinciden con su evidencia.');
}

function race(array $f, array $jobs, string $directory, bool $sameKey, ?string $paymentGate = null): array
{
    $sql = DB::connection('sqlsrv'); $processes = []; $dirs = []; $ready = [];
    $sql->beginTransaction();
    try {
        // Pick the row both services lock. Refund reservations do not acquire a budget lock.
        $gateTable = $paymentGate === null ? 'institutional_bonus_budgets' : 'purchase_payments';
        $sql->table($gateTable)->where('public_id', $paymentGate ?? $f['budget'])->lockForUpdate()->first();
        foreach ($jobs as $i => $job) {
            $d = $directory.'-'.$i; mkdir($d, 0700, true); $token = bin2hex(random_bytes(16));
            file_put_contents($d.'/owner', $token); $dirs[] = $d;
            $p = new Process([PHP_BINARY, __DIR__.'/worker.php'], base_path(), null,
                json_encode($job + ['directory' => $d, 'token' => $token, 'gate_table' => $gateTable], JSON_THROW_ON_ERROR), 55);
            $processes[] = $p; $p->start();
        }
        becaWait(function () use ($dirs, $processes) {
            foreach ($processes as $i => $p) {
                becaAssert($p->isRunning() || is_file($dirs[$i].'/ready'), 'El proceso no inicio: '.$p->getErrorOutput());
                if (!is_file($dirs[$i].'/ready')) return false;
            }
            return true;
        }, 'Los procesos no alcanzaron la barrera.');
        foreach ($dirs as $d) $ready[] = json_decode(file_get_contents($d.'/ready'), true, 8, JSON_THROW_ON_ERROR);
        $spids = array_column($ready, 'spid'); $pids = array_column($ready, 'pid');
        $parent = (int)$sql->selectOne('SELECT @@SPID AS spid')->spid;
        becaAssert(count(array_unique($spids)) === 2 && !in_array($parent, $spids, true)
            && count(array_unique($pids)) === 2 && !in_array(getmypid(), $pids, true), 'No hay procesos y conexiones SQL independientes.');
        foreach ($dirs as $d) becaSignal($d.'/go', '1');
        becaWait(function () use ($dirs, $sameKey, $processes) {
            $attempts = 0;
            foreach ($dirs as $i => $d) {
                if (is_file($d.'/locking')) ++$attempts;
                if (!$processes[$i]->isRunning() && !is_file($d.'/result')) {
                    throw new RuntimeException('Proceso interrumpido: '.$processes[$i]->getErrorOutput());
                }
            }
            if (!$sameKey) return $attempts === 2;
            // The second identical key must already reject or reach the same budget lock.
            return $attempts >= 1 && count(array_filter($dirs, fn($d) => is_file($d.'/locking') || is_file($d.'/result'))) === 2;
        }, 'No se establecio la contencion SQL esperada.', 15);
        foreach ($dirs as $d) if (is_file($d.'/result')) {
            $r = json_decode(file_get_contents($d.'/result'), true, 16, JSON_THROW_ON_ERROR);
            becaAssert($sameKey && $r['status'] === 'CONFLICT', 'Operacion termino sin esperar el bloqueo: '.json_encode($r));
        }
        $sql->commit();
        $results = [];
        foreach ($processes as $i => $p) {
            $p->wait();
            becaAssert($p->isSuccessful() && is_file($dirs[$i].'/result'), 'Proceso fallo: '.$p->getErrorOutput()
                .(is_file($dirs[$i].'/result') ? file_get_contents($dirs[$i].'/result') : ''));
            $r = json_decode(file_get_contents($dirs[$i].'/result'), true, 32, JSON_THROW_ON_ERROR);
            becaAssert(in_array($r['status'], ['OK','CONFLICT'], true), 'Error de SQL o infraestructura: '.json_encode($r));
            $results[] = $r;
        }
        return $results;
    } finally {
        while ($sql->transactionLevel() > 0) $sql->rollBack();
        foreach ($processes as $p) if ($p->isRunning()) $p->stop(2);
    }
}

function checkState(array $f, int $committed, int $consumed, int $events, int $purchaseCount = 1): void
{
    $b = InstitutionalBonusBudget::where('public_id', $f['budget'])->firstOrFail();
    becaAssert($b->approved_amount_cents === 10000 && $b->committed_amount_cents === $committed
        && $b->consumed_amount_cents === $consumed, 'Contadores presupuestarios incorrectos.');
    becaAssert(Bonus::where('public_id', $f['bonus'])->firstOrFail()->remaining_amount_cents === $committed, 'Remanente incorrecto.');
    $wallet = Wallet::where('public_id', $f['wallet'])->firstOrFail();
    becaAssert($wallet->available_balance_cents === 0 && $wallet->held_balance_cents === 0, 'Se altero dinero de la wallet.');
    becaAssert(DB::connection('sqlsrv')->table('ledger_entries')->where('wallet_id', $f['wallet'])->count() === 0, 'Se creo un movimiento de dinero.');
    $payments = PurchasePayment::where('wallet_id', $f['wallet'])->pluck('public_id');
    becaAssert($payments->count() === $purchaseCount, 'Cantidad de compras distinta de la esperada.');
    $streams = DB::connection('sqlsrv')->table('financial_team4_streams')->whereIn('payment_intent_id', $payments)->pluck('id');
    $outbox = DB::connection('sqlsrv')->table('financial_team4_outbox')->whereIn('stream_id', $streams)->get();
    becaAssert($outbox->count() === $events, 'Eventos duplicados o faltantes.');
    foreach ($outbox as $e) becaAssert($e->state === 'PENDING' && (int)$e->attempts === 0
        && $e->delivered_at === null && hash_equals($e->body_hash, hash('sha256', $e->body)), 'Evento alterado o enviado.');
    becaAssert(app(InstitutionalObligationQueryService::class)->report($f['budget'])['consistent'] === true, 'La conciliacion encontro diferencias.');
}

function cleanup(array $f): void
{
    $sql = DB::connection('sqlsrv');
    // Delete only this run's owned rows, in foreign-key order, in a single transaction.
    $sql->transaction(function () use ($sql, $f) {
        $bonuses = $f['budget'] ? $sql->table('bonuses')->where('institutional_budget_id', $f['budget'])->pluck('public_id') : collect();
        $payments = $f['wallet'] ? $sql->table('purchase_payments')->where('wallet_id', $f['wallet'])->pluck('public_id') : collect();
        $refunds = $sql->table('purchase_refund_requests')->whereIn('purchase_payment_id', $payments)->get();
        $refundIds = $refunds->pluck('public_id');
        $tx = $refunds->pluck('request_transaction_id')->merge($refunds->pluck('financial_transaction_id'))->filter()->all();
        $streams = $sql->table('financial_team4_streams')->whereIn('payment_intent_id', $payments)->pluck('id');
        $events = $sql->table('financial_team4_outbox')->whereIn('stream_id', $streams)->pluck('event_id');
        $sql->table('financial_team4_attempts')->whereIn('event_id', $events)->delete();
        $sql->table('financial_team4_outbox')->whereIn('stream_id', $streams)->delete();
        $sql->table('financial_team4_streams')->whereIn('id', $streams)->delete();
        if ($f['budget']) {
            $sql->table('institutional_bonus_settlements')->where('budget_id', $f['budget'])->delete();
            $debts = $sql->table('institutional_bonus_obligations')->where('budget_id', $f['budget'])->pluck('public_id');
            $sql->table('institutional_bonus_refunds')->whereIn('obligation_id', $debts)->delete();
            $sql->table('institutional_bonus_obligations')->where('budget_id', $f['budget'])->delete();
        }
        $sql->table('purchase_refund_bonuses')->whereIn('refund_request_id', $refundIds)->delete();
        $sql->table('purchase_refund_requests')->whereIn('public_id', $refundIds)->delete();
        $sql->table('purchase_marketplace_links')->whereIn('purchase_payment_id', $payments)->delete();
        $sql->table('purchase_payment_bonuses')->whereIn('purchase_payment_id', $payments)->delete();
        $sql->table('purchase_payments')->whereIn('public_id', $payments)->delete();
        $sql->table('financial_transactions')->whereIn('public_id', $tx)->delete();
        $sql->table('bonus_budget_commitments')->whereIn('bonus_id', $bonuses)->delete();
        $sql->table('bonus_administrative_operations')->whereIn('bonus_id', $bonuses)->delete();
        $sql->table('bonus_ledger_entries')->whereIn('bonus_id', $bonuses)->delete();
        $sql->table('bonus_restrictions')->whereIn('bonus_id', $bonuses)->delete();
        $sql->table('bonuses')->whereIn('public_id', $bonuses)->delete();
        if ($f['budget']) {
            $sql->table('institutional_budget_operations')->where('budget_id', $f['budget'])->delete();
            $sql->table('institutional_bonus_budgets')->where('public_id', $f['budget'])->delete();
        }
        if ($f['wallet']) $sql->table('wallets')->where('public_id', $f['wallet'])->where('owner_id', $f['buyer'])->delete();
    });
    foreach (['operator','buyer'] as $key) if ($f[$key]) {
        App\Models\User::whereKey($f[$key])->where('email', $f['run'].'-'.$f['name'].'-'.$key.'@concurrency.test')->delete();
    }
}
