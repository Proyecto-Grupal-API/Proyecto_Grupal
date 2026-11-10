<?php
// Standalone testing entry point. Never extends Tests\TestCase or resets MongoDB.
use App\Domains\Financial\Contracts\{InstitutionalBudgetAuthorization, InstitutionalPurchaseAuthorization,
    InstitutionalSettlementAuthorization, FinancialWebAuthorizer, BonusAuthorizationProvider};
use App\Domains\Financial\Enums\BonusType;
use Illuminate\Support\Facades\{DB, Http};

if (getenv('APP_ENV') !== 'testing' || getenv('BECA_SQL_CONCURRENCY') !== '1') {
    throw new RuntimeException('Ejecucion exclusiva de testing con BECA_SQL_CONCURRENCY=1.');
}
$root = dirname(__DIR__, 3);
if (is_file($root.'/bootstrap/cache/config.php')) throw new RuntimeException('Ejecuta config:clear antes de esta prueba.');
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('testing') || config('database.default') !== 'mongodb'
    || config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing'
    || config('database.connections.mongodb.database') !== 'campus_virtual_testing') {
    throw new RuntimeException('Solo bases exactas de testing; no se crearon datos.');
}
$sql = DB::connection('sqlsrv');
if ($sql->getDriverName() !== 'sqlsrv' || $sql->transactionLevel() !== 0
    || $sql->selectOne('SELECT DB_NAME() AS database_name')->database_name !== 'campus_digital_financial_testing'
    || DB::connection('mongodb')->getMongoDB()->getDatabaseName() !== 'campus_virtual_testing') {
    throw new RuntimeException('La conexion efectiva no es la base de testing esperada.');
}
config(['financial_team4.enabled' => false, 'financial_identity_authorization.enabled' => false,
    'financial_marketplace.institutional_beca_enabled' => false]);
Http::preventStrayRequests();
$sql->statement('SET LOCK_TIMEOUT 20000');

function becaTestBindings(string $operator, string $buyer): void
{
    app()->instance(InstitutionalBudgetAuthorization::class, new class($operator) implements InstitutionalBudgetAuthorization {
        public function __construct(private string $actor) {}
        public function allows(string $actorId, string $action, ?string $budgetId = null): bool {
            return $actorId === $this->actor && in_array($action, ['budget.approve','budget.adjust','budget.suspend',
                'budget.resume','budget.commit','budget.release','budget.expire','budget.refund.release'], true);
        }
    });
    app()->instance(FinancialWebAuthorizer::class, new class($operator) implements FinancialWebAuthorizer {
        public function __construct(private string $actor) {}
        public function allows(string $userId, string $action, ?string $walletId = null): bool {
            return $userId === $this->actor && in_array($action, ['bonus.issue','bonus.cancel'], true);
        }
    });
    app()->instance(BonusAuthorizationProvider::class, new class($operator) implements BonusAuthorizationProvider {
        public function __construct(private string $actor) {}
        public function canIssueBonus(string $issuerType, string $issuerId, BonusType $type): bool {
            return $issuerType === 'USER' && $issuerId === $this->actor && $type === BonusType::BECA;
        }
    });
    app()->instance(InstitutionalPurchaseAuthorization::class, new class($operator, $buyer) implements InstitutionalPurchaseAuthorization {
        public function __construct(private string $operator, private string $buyer) {}
        public function allows(string $actorId, string $action, array $context): bool {
            return $action === 'purchase.pay'
                ? $actorId === $this->buyer && ($context['authorization_reference'] ?? null) === 'concurrency-test-consent'
                : $actorId === $this->operator && in_array($action, ['purchase.refund','purchase.refund.closed'], true);
        }
    });
    app()->instance(InstitutionalSettlementAuthorization::class, new class($operator) implements InstitutionalSettlementAuthorization {
        public function __construct(private string $actor) {}
        public function allows(string $actorId, string $action, array $context): bool {
            return $actorId === $this->actor && $action === 'settlement.record'
                && ($context['recipient_reference'] ?? null) === 'concurrency-test-recipient';
        }
    });
}

function becaAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function becaWait(callable $ready, string $message, float $seconds = 30): void
{
    $end = microtime(true) + $seconds;
    do {
        if ($ready()) return;
        usleep(10000);
    } while (microtime(true) < $end);
    throw new RuntimeException($message);
}

function becaSignal(string $path, string $contents): void
{
    if (is_file($path)) return;
    $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
    if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $path)) {
        throw new RuntimeException('No se pudo publicar una senal de sincronizacion.');
    }
}

function becaOperation(array $job): array
{
    return becaWithClock($job['clock'] ?? null, fn () => becaUnclockedOperation($job));
}

function becaWithClock(?string $clock, callable $work): mixed
{
    if ($clock === null) return $work();
    becaAssert(preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/', $clock) === 1,
        'Reloj de prueba no valido.');
    $before = Carbon\Carbon::getTestNow();
    $immutableBefore = Carbon\CarbonImmutable::getTestNow();
    try {
        Carbon\Carbon::setTestNow($clock);
        Carbon\CarbonImmutable::setTestNow($clock);
        return $work();
    } finally {
        Carbon\Carbon::setTestNow($before);
        Carbon\CarbonImmutable::setTestNow($immutableBefore);
    }
}

function becaUnclockedOperation(array $job): array
{
    $p = $job['fixture'];
    return match ($job['action']) {
        'pay' => app(App\Domains\Financial\Services\InstitutionalBecaPurchaseService::class)->pay(
            App\Domains\Financial\Models\Wallet::where('public_id', $p['wallet'])->firstOrFail(), $p['bonus'],
            $job['amount'], $job['key'], $p['buyer'], 'concurrency-test-consent', $job['market']),
        'refund' => ['transaction_id' => strtolower(app(App\Domains\Financial\Services\PurchaseRefundService::class)->complete(
            App\Domains\Financial\Models\PurchaseRefundRequest::where('public_id', $job['refund'])->firstOrFail(),
            $job['key'], $p['operator'])->public_id)],
        'reserve_refund' => ['refund_id' => strtolower(app(App\Domains\Financial\Services\PurchaseRefundService::class)->request(
            App\Domains\Financial\Models\PurchasePayment::where('public_id', $job['payment'])->firstOrFail(), 0,
            [['bonus_id' => $p['bonus'], 'amount_cents' => $job['amount']]], $job['key'], $p['buyer'],
            'Reserva exclusiva de prueba concurrente')->public_id)],
        'settle' => becaSettlement($job),
        'cancel', 'expire' => becaRelease($job),
        default => throw new RuntimeException('Accion de prueba desconocida.'),
    };
}

function becaSettlement(array $job): array
{
    $r = app(App\Domains\Financial\Services\InstitutionalSettlementService::class)->record(
        $job['fixture']['operator'], $job['obligation'], $job['version'], $job['data'], $job['key']);
    return ['settlement_id' => $r['settlement_id'], 'replayed' => $r['replayed']];
}

function becaRelease(array $job): array
{
    $service = app(App\Domains\Financial\Services\InstitutionalBecaReleaseService::class);
    $p = $job['fixture'];
    $args = [$p['operator'], $p['budget'], $p['bonus'], $job['version'], $job['reason'], $job['key']];
    $r = $job['action'] === 'expire' ? $service->expire(...$args) : $service->cancel(...$args);
    return ['operation_id' => strtolower($r['operation']->public_id),
        'released_amount_cents' => $r['operation']->after_data['released_amount_cents'], 'replayed' => $r['replayed']];
}
