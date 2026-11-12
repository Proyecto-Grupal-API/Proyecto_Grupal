<?php

namespace Tests\Unit;

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\WalletStatus;
use App\Domains\Financial\Enums\WithdrawalMethod;
use App\Domains\Financial\Enums\WithdrawalStatus;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Models\Withdrawal;
use App\Domains\Financial\Services\FinancialLimitGuard;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\WithdrawalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

// A shared vendor autoloader may map App to the main worktree.
require_once dirname(__DIR__, 2).'/app/Domains/Financial/Services/WithdrawalService.php';

/** No Laravel boot, SQL Server, MongoDB or migrations. Aliases require isolated processes. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WithdrawalServiceLockingTest extends TestCase
{
    private bool $inTransaction = false;
    private bool $walletLocked = false;
    private mixed $walletModel;
    private mixed $withdrawalModel;
    private mixed $ledger;
    private FinancialLimitGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->walletModel = Mockery::mock('alias:'.Wallet::class);
        $this->withdrawalModel = Mockery::mock('alias:'.Withdrawal::class);
        $this->ledger = Mockery::mock('overload:'.LedgerService::class);
        $this->guard = Mockery::mock(FinancialLimitGuard::class);
        $connection = Mockery::mock();
        $connection->shouldReceive('transaction')->once()->andReturnUsing(function ($callback) {
            $this->inTransaction = true;
            try {
                return $callback();
            } finally {
                $this->inTransaction = false;
            }
        });
        $db = Mockery::mock();
        $db->shouldReceive('connection')->with('sqlsrv')->once()->andReturn($connection);
        DB::swap($db);
    }

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            Facade::clearResolvedInstances();
            parent::tearDown();
        }
    }

    private function lockedWallet(WalletStatus $status): Wallet
    {
        $wallet = new Wallet();
        $wallet->public_id = 'wallet-1';
        $wallet->status = $status;
        $wallet->currency = 'MXN';
        $query = Mockery::mock();
        $this->walletModel->shouldReceive('where')->once()->with('public_id', 'wallet-1')
            ->andReturnUsing(function () use ($query) {
                self::assertTrue($this->inTransaction);
                return $query;
            });
        $query->shouldReceive('lockForUpdate')->once()->andReturnUsing(function () use ($query) {
            $this->walletLocked = true;
            return $query;
        });
        $query->shouldReceive('firstOrFail')->once()->andReturnUsing(function () use ($wallet) {
            self::assertTrue($this->walletLocked);
            return $wallet;
        });
        return $wallet;
    }

    private function pendingWithdrawal(): Withdrawal
    {
        // Reuse the registered alias mock so instance expectations retain its container.
        $withdrawal = $this->withdrawalModel;
        $withdrawal->public_id = 'withdrawal-1';
        $withdrawal->wallet_id = 'wallet-1';
        $withdrawal->amount_cents = 10000;
        $withdrawal->method = WithdrawalMethod::TRANSFERENCIA;
        $withdrawal->status = WithdrawalStatus::PENDIENTE;
        $query = Mockery::mock();
        $this->withdrawalModel->shouldReceive('where')->once()
            ->with('public_id', 'withdrawal-1')->andReturn($query);
        $query->shouldReceive('lockForUpdate')->once()->andReturnSelf();
        $query->shouldReceive('firstOrFail')->once()->andReturnUsing(function () use ($withdrawal) {
            self::assertTrue($this->inTransaction);
            return $withdrawal;
        });
        return $withdrawal;
    }

    public static function inactiveStatuses(): array
    {
        return [[WalletStatus::BLOQUEADA], [WalletStatus::SUSPENDIDA], [WalletStatus::CERRADA]];
    }

    #[DataProvider('inactiveStatuses')]
    public function test_create_rejects_persisted_inactive_state_after_preflight(WalletStatus $status): void
    {
        $this->lockedWallet($status);
        $callerWallet = new Wallet();
        $callerWallet->public_id = 'wallet-1';
        $callerWallet->status = WalletStatus::ACTIVA;
        $this->guard->shouldReceive('assertAllowed')->once()->andReturnUsing(function () {
            self::assertFalse($this->inTransaction);
            return new \App\Domains\Financial\Data\LimitEvaluation('wallet-1', MovementType::RETIRO, 10000, 0, []);
        });
        $this->withdrawalModel->shouldNotReceive('create');
        $this->ledger->shouldNotReceive('debit');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('La wallet debe estar activa para solicitar un retiro.');
        (new WithdrawalService($this->guard))->create($callerWallet, 10000, WithdrawalMethod::TRANSFERENCIA);
    }

    #[DataProvider('inactiveStatuses')]
    public function test_complete_rejects_inactive_locked_wallet_without_debit_or_save(WalletStatus $status): void
    {
        $withdrawal = $this->pendingWithdrawal();
        $this->lockedWallet($status);
        $this->withdrawalModel->shouldNotReceive('save');
        $this->ledger->shouldNotReceive('debit');
        $this->guard->shouldNotReceive('assertAllowed');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('La wallet debe estar activa para completar el retiro.');
        (new WithdrawalService($this->guard))->complete($withdrawal, 'key-1');
    }

    public function test_complete_debits_and_saves_while_wallet_lock_transaction_is_active(): void
    {
        $withdrawal = $this->pendingWithdrawal();
        $wallet = $this->lockedWallet(WalletStatus::ACTIVA);
        $debited = false;
        $this->guard->shouldNotReceive('assertAllowed');
        $this->ledger->shouldReceive('debit')->once()->with(
            $wallet, 10000, MovementType::RETIRO, 'key-1', 'WITHDRAWAL', 'withdrawal-1',
            ['withdrawal_method' => WithdrawalMethod::TRANSFERENCIA->value]
        )->andReturnUsing(function () use (&$debited) {
            self::assertTrue($this->inTransaction);
            self::assertTrue($this->walletLocked);
            $debited = true;
        });
        $withdrawal->shouldReceive('save')->once()->andReturnUsing(function () use (&$debited, $withdrawal) {
            self::assertTrue($debited);
            self::assertTrue($this->inTransaction);
            self::assertSame(WithdrawalStatus::COMPLETADA, $withdrawal->status);
            return true;
        });
        self::assertSame($withdrawal, (new WithdrawalService($this->guard))->complete($withdrawal, 'key-1'));
        self::assertFalse($this->inTransaction);
    }

    public function test_failed_debit_does_not_complete_or_save_withdrawal(): void
    {
        $withdrawal = $this->pendingWithdrawal();
        $this->lockedWallet(WalletStatus::ACTIVA);
        $this->ledger->shouldReceive('debit')->once()->andThrow(new InvalidArgumentException('Saldo insuficiente.'));
        $withdrawal->shouldNotReceive('save');
        try {
            (new WithdrawalService($this->guard))->complete($withdrawal, 'key-1');
            self::fail('Expected failed debit');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Saldo insuficiente.', $exception->getMessage());
            self::assertSame(WithdrawalStatus::PENDIENTE, $withdrawal->status);
            self::assertFalse($this->inTransaction);
        }
    }
}
