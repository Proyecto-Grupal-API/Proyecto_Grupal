<?php

namespace Tests\Unit\Financial;

use App\Domains\Financial\Enums\ReconciliationCheck;
use App\Domains\Financial\Services\ReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Runs without the Laravel application, Tests\TestCase or external databases. */
final class ReconciliationIntegrityTest extends TestCase
{
    private Manager $database;

    private ReconciliationService $service;

    private mixed $previousResolver;

    public static function setUpBeforeClass(): void
    {
        // Also permits a read-only shared vendor bootstrap while testing this worktree.
        spl_autoload_register(static function (string $class): void {
            if (str_starts_with($class, 'App\\')) {
                $path = dirname(__DIR__, 3).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
                if (is_file($path)) {
                    require_once $path;
                }
            }
        }, prepend: true);
    }

    protected function setUp(): void
    {
        $this->previousResolver = Model::getConnectionResolver();
        $this->database = new Manager();
        // The model's connection name is sqlsrv; its only configured driver is SQLite.
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'sqlsrv');
        Model::setConnectionResolver($this->database->getDatabaseManager());
        $connection = $this->database->getConnection('sqlsrv');
        self::assertSame('sqlite', $connection->getDriverName());
        $connection->statement('CREATE TABLE financial_transactions (id INTEGER PRIMARY KEY, public_id TEXT, status TEXT, reference_type TEXT, reference_id TEXT, created_at TEXT)');
        $connection->statement('CREATE TABLE ledger_entries (id INTEGER PRIMARY KEY, transaction_id TEXT, wallet_id TEXT, movement_type TEXT, amount_cents INTEGER, created_at TEXT)');
        $connection->statement('CREATE TABLE bonus_ledger_entries (reference_id TEXT, reference_type TEXT)');
        $connection->statement('CREATE TABLE purchase_refund_requests (financial_transaction_id TEXT, wallet_amount_cents INTEGER)');
        $this->service = (new ReflectionClass(ReconciliationService::class))->newInstanceWithoutConstructor();
        $this->setState('windowStart', CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC'));
        $this->setState('windowEnd', CarbonImmutable::parse('2026-10-02 00:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        $this->database->getDatabaseManager()->disconnect('sqlsrv');
        if ($this->previousResolver !== null) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
    }

    public function testTransactionsIncludeNullReferencesAndPreserveExceptions(): void
    {
        $rows = [
            ['pending-null', 'PENDIENTE', null],
            ['empty-null', 'COMPLETADA', null],
            ['valid-null', 'COMPLETADA', null],
            ['pending-topup', 'PENDIENTE', 'TOPUP'],
            ['empty-topup', 'COMPLETADA', 'TOPUP'],
            ['refund-request', 'PENDIENTE', 'REFUND_REQUEST'],
            ['purchase-request', 'COMPLETADA', 'PURCHASE_REFUND_REQUEST'],
            ['bonus-refund', 'COMPLETADA', 'PURCHASE_REFUND'],
            ['outside', 'PENDIENTE', null],
        ];
        foreach ($rows as [$id, $status, $reference]) {
            $this->database->getConnection('sqlsrv')->table('financial_transactions')->insert([
                'public_id' => $id, 'status' => $status, 'reference_type' => $reference,
                'reference_id' => $id, 'created_at' => $id === 'outside' ? '2026-10-02 00:00:00' : '2026-10-01 12:00:00',
            ]);
        }
        $this->entry('valid-null', 'a', 'RECARGA', 100);
        $connection = $this->database->getConnection('sqlsrv');
        $connection->table('purchase_refund_requests')->insert(['financial_transaction_id' => 'bonus-refund', 'wallet_amount_cents' => 0]);
        $connection->table('bonus_ledger_entries')->insert(['reference_id' => 'bonus-refund', 'reference_type' => 'PURCHASE_REFUND']);
        $differences = $this->check('checkTransactions');
        self::assertSame(['pending-null', 'pending-topup', 'empty-null', 'empty-topup'], array_column($differences, 'entity_id'));
        self::assertSame(ReconciliationCheck::TRANSACCION_PENDIENTE, $differences[0]['check_code']);
        self::assertSame(ReconciliationCheck::TRANSACCION_SIN_MOVIMIENTOS, $differences[2]['check_code']);
    }

    #[DataProvider('transferCases')]
    public function testTransferIntegrity(array $entries, bool $invalid): void
    {
        foreach ($entries as [$wallet, $type, $amount]) {
            $this->entry('transfer', $wallet, $type, $amount);
        }
        $differences = $this->check('checkTransfers');
        self::assertCount($invalid ? 1 : 0, $differences);
        if ($invalid) {
            self::assertSame(ReconciliationCheck::TRANSFERENCIA_DESCUADRADA, $differences[0]['check_code']);
            self::assertSame(array_sum(array_column($entries, 2)), $differences[0]['actual_cents']);
        }
    }

    public static function transferCases(): iterable
    {
        $out = 'TRANSFERENCIA_SALIDA';
        $in = 'TRANSFERENCIA_ENTRADA';
        yield 'valid pair' => [[['a', $out, -100], ['b', $in, 100]], false];
        yield 'missing leg' => [[['a', $out, -100]], true];
        yield 'unequal amounts' => [[['a', $out, -100], ['b', $in, 90]], true];
        yield 'reversed signs' => [[['a', $out, 100], ['b', $in, -100]], true];
        yield 'zero amounts' => [[['a', $out, 0], ['b', $in, 0]], true];
        yield 'same wallet' => [[['a', $out, -100], ['a', $in, 100]], true];
        yield 'two outgoing' => [[['a', $out, -100], ['b', $out, 100]], true];
        yield 'two incoming' => [[['a', $in, -100], ['b', $in, 100]], true];
        yield 'duplicated pair' => [[['a', $out, -100], ['b', $in, 100], ['a', $out, -100], ['b', $in, 100]], true];
        yield 'extra payment' => [[['a', $out, -100], ['b', $in, 100], ['a', 'PAGO', -25]], true];
        yield 'extra zero adjustment' => [[['a', $out, -100], ['b', $in, 100], ['a', 'AJUSTE_CREDITO', 0]], true];
        yield 'extra balanced adjustments' => [[['a', $out, -100], ['b', $in, 100], ['a', 'AJUSTE_DEBITO', -25], ['b', 'AJUSTE_CREDITO', 25]], true];
    }

    public function testNonTransferTransactionsAreNotCheckedAsTransfers(): void
    {
        $this->entry('payment', 'a', 'PAGO', -100);
        $this->entry('topup', 'a', 'RECARGA', 100);
        self::assertSame([], $this->check('checkTransfers'));
    }

    public function testMidnightWindowIncludesStartAndExcludesEnd(): void
    {
        $this->entry('before-start', 'a', 'TRANSFERENCIA_SALIDA', -100, '2026-09-30 23:59:59');
        $this->entry('at-start', 'a', 'TRANSFERENCIA_SALIDA', -100, '2026-10-01 00:00:00');
        $this->entry('before-end', 'a', 'TRANSFERENCIA_SALIDA', -100, '2026-10-01 23:59:59');
        $this->entry('at-end', 'a', 'TRANSFERENCIA_SALIDA', -100, '2026-10-02 00:00:00');
        $ids = array_column($this->check('checkTransfers'), 'entity_id');
        sort($ids);
        self::assertSame(['at-start', 'before-end'], $ids);
    }

    public function testValidTransferAcrossMidnightIsNotReportedOnEitherDay(): void
    {
        $this->entry('midnight', 'a', 'TRANSFERENCIA_SALIDA', -100, '2026-10-01 23:59:59');
        $this->entry('midnight', 'b', 'TRANSFERENCIA_ENTRADA', 100, '2026-10-02 00:00:00');
        // The scoped wallet need not own the leg inside the current window.
        $this->setState('scopeWalletIds', ['b']);
        self::assertSame([], $this->check('checkTransfers'));
        $this->setState('windowStart', CarbonImmutable::parse('2026-10-02 00:00:00', 'UTC'));
        $this->setState('windowEnd', CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC'));
        self::assertSame([], $this->check('checkTransfers'));
    }

    public function testExtraMovementOutsideWindowStillInvalidatesSelectedTransfer(): void
    {
        $this->entry('extra-next-day', 'a', 'TRANSFERENCIA_SALIDA', -100, '2026-10-01 23:59:59');
        $this->entry('extra-next-day', 'b', 'TRANSFERENCIA_ENTRADA', 100, '2026-10-01 23:59:59');
        $this->entry('extra-next-day', 'a', 'PAGO', -25, '2026-10-02 00:00:00');
        $differences = $this->check('checkTransfers');
        self::assertCount(1, $differences);
        self::assertSame(ReconciliationCheck::TRANSFERENCIA_DESCUADRADA, $differences[0]['check_code']);
        self::assertSame(-25, $differences[0]['actual_cents']);
        self::assertSame(['entries' => 3], $differences[0]['details']);
    }

    public function testWalletScopeIncludesCounterpartAndExcludesOtherTransactions(): void
    {
        $this->entry('valid', 'a', 'TRANSFERENCIA_SALIDA', -100);
        $this->entry('valid', 'b', 'TRANSFERENCIA_ENTRADA', 100);
        $this->entry('outside-scope', 'c', 'TRANSFERENCIA_SALIDA', -100);
        $this->entry('outside-window', 'a', 'TRANSFERENCIA_SALIDA', -100, '2026-10-02 00:00:00');
        $this->setState('scopeWalletIds', ['a']);
        self::assertSame([], $this->check('checkTransfers'));
        $this->entry('broken', 'b', 'TRANSFERENCIA_SALIDA', 100);
        $this->entry('broken', 'a', 'TRANSFERENCIA_ENTRADA', -100);
        self::assertSame(['broken'], array_column($this->check('checkTransfers'), 'entity_id'));
    }

    private function entry(string $transaction, string $wallet, string $type, int $amount, string $date = '2026-10-01 12:00:00'): void
    {
        $this->database->getConnection('sqlsrv')->table('ledger_entries')->insert([
            'transaction_id' => $transaction, 'wallet_id' => $wallet, 'movement_type' => $type,
            'amount_cents' => $amount, 'created_at' => $date,
        ]);
    }

    private function setState(string $property, mixed $value): void
    {
        (new ReflectionClass($this->service))->getProperty($property)->setValue($this->service, $value);
    }

    private function check(string $method): array
    {
        (new ReflectionClass($this->service))->getMethod($method)->invoke($this->service);

        return (new ReflectionClass($this->service))->getProperty('differences')->getValue($this->service);
    }
}
