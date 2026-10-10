<?php
use App\Domains\Financial\Adapters\PendingTransferAuthorizationProvider;
use App\Domains\Financial\Contracts\TransferAuthorizationProvider;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\StudentTransferKind;
use App\Domains\Financial\Enums\WalletStatus;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Models\FinancialLimit;
use App\Domains\Financial\Models\FinancialLimitChange;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\StudentTransfer;
use App\Domains\Financial\Models\StudentTransferPolicy;
use App\Domains\Financial\Models\StudentTransferPolicyChange;
use App\Domains\Financial\Models\TransactionAlert;
use App\Domains\Financial\Models\TransactionAlertStatusChange;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\FinancialLimitService;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\StudentTransferPolicyService;
use App\Domains\Financial\Services\StudentTransferService;
use App\Domains\Financial\Services\WalletService;
use App\Domains\Financial\Support\FinancialJobLock;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;

beforeEach(function () {
    if (config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing') { throw new RuntimeException('Solo se permite la base financiera de pruebas.'); }
    $this->stPolicy = StudentTransferPolicy::where('policy_key', 'STUDENT_MXN')->firstOrFail()->getAttributes();
    $this->stUsers = []; $this->stWallets = []; $this->stLimits = [];
    stGrant();
});
afterEach(function () {
    CarbonImmutable::setTestNow(); \Illuminate\Support\Carbon::setTestNow();
    $walletIds = $this->stWallets;
    $alerts = TransactionAlert::whereIn('wallet_id', $walletIds)->pluck('public_id');
    TransactionAlertStatusChange::whereIn('alert_id', $alerts)->delete();
    TransactionAlert::whereIn('public_id', $alerts)->delete();
    $transactionIds = LedgerEntry::whereIn('wallet_id', $walletIds)->pluck('transaction_id');
    StudentTransfer::whereIn('source_wallet_id', $walletIds)->orWhereIn('destination_wallet_id', $walletIds)->delete();
    LedgerEntry::whereIn('transaction_id', $transactionIds)->delete();
    FinancialTransaction::whereIn('public_id', $transactionIds)->delete();
    Wallet::whereIn('public_id', $walletIds)->delete();
    FinancialLimitChange::whereIn('limit_id', $this->stLimits)->delete();
    FinancialLimit::whereIn('public_id', $this->stLimits)->delete();
    StudentTransferPolicyChange::whereIn('actor_id', array_map(fn ($id) => 'user:' . $id, $this->stUsers))->delete();
    StudentTransferPolicy::where('policy_key', 'STUDENT_MXN')->update(collect($this->stPolicy)->except(['id'])->all());
    User::whereIn('_id', $this->stUsers)->delete();
});
function stGrant(bool $send = true, bool $receive = true, bool $manage = true): void
{
    app()->instance(TransferAuthorizationProvider::class, new class($send, $receive, $manage) implements TransferAuthorizationProvider {
        public function __construct(private bool $send, private bool $receive, private bool $manage) {}
        public function canSend(string $userId, Wallet $wallet): bool { return $this->send; }
        public function canReceive(string $userId, Wallet $wallet): bool { return $this->receive; }
        public function canManagePolicy(string $userId): bool { return $this->manage; }
    });
}
function stWallet($test, int $balance = 0): Wallet
{
    $u = User::factory()->create(['email' => 'test-transfer-' . Str::uuid() . '@example.test']); $u->forceFill(['email_verified_at' => now(), 'account_activation_pending' => false])->save();
    $test->stUsers[] = (string) $u->getKey();
    $w = app(WalletService::class)->create('USER', (string) $u->getKey(), WalletType::USUARIO); $test->stWallets[] = $w->public_id;
    if ($balance) { app(LedgerService::class)->credit($w, $balance, MovementType::RECARGA, 'test-st-seed-' . Str::uuid(), 'TEST_SEED', $w->public_id); }
    return $w->fresh();
}
function stKey(): string { return 'test-st-' . Str::uuid(); }
function stSend(Wallet $from, Wallet $to, int $amount = 10000, ?string $key = null, StudentTransferKind $kind = StudentTransferKind::TRANSFERENCIA): StudentTransfer
{
    return app(StudentTransferService::class)->execute($from->owner_id, $from, $to, $amount, $kind, 'Material escolar', $key ?? stKey());
}
function stPolicy($test, array $changes): StudentTransferPolicy
{
    $admin = $test->stUsers[0] ?? stWallet($test)->owner_id;
    $service = app(StudentTransferPolicyService::class); return $service->update($admin, $service->current()->version, $changes, 'Política de prueba');
}
function stReject(callable $run, string $message): void
{
    try { $run(); throw new RuntimeException('La operación debía rechazarse.'); }
    catch (InvalidArgumentException $e) { expect($e->getMessage())->toContain($message); }
}

test('student policy begins with populated document limits and keeps initial audit', function () {
    $p = app(StudentTransferPolicyService::class)->current();
    expect($p->minimum_cents)->toBe(100)->and($p->maximum_cents)->toBe(200000)
        ->and($p->daily_cents)->toBe(500000)->and($p->monthly_cents)->toBe(2000000)
        ->and($p->business_timezone)->toBe('America/Mexico_City');
    expect(StudentTransferPolicyChange::where('policy_key', 'STUDENT_MXN')->where('version', 1)->exists())->toBeTrue();
});
test('policy editing requires a trusted permission and records version actor reason before and after', function () {
    $from = stWallet($this); $s = app(StudentTransferPolicyService::class); $version = $s->current()->version;
    stGrant(true, true, false);
    expect(fn () => app(StudentTransferPolicyService::class)->update($from->owner_id, $version, ['minimum_cents' => 200], 'Cambio'))->toThrow(AuthorizationException::class);
    stGrant(); $p = stPolicy($this, ['minimum_cents' => 200]);
    $audit = StudentTransferPolicyChange::where('version', $p->version)->firstOrFail();
    expect($p->version)->toBe($version + 1)->and($audit->actor_id)->toBe('user:' . $from->owner_id)
        ->and($audit->before_data['minimum_cents'])->toBe(100)->and($audit->after_data['minimum_cents'])->toBe(200);
    stReject(fn () => app(StudentTransferPolicyService::class)->update($from->owner_id, $version, ['minimum_cents' => 300], 'Obsoleto'), 'versión');
});
test('invalid policy changes do not mutate limits or history', function () {
    stWallet($this); $s = app(StudentTransferPolicyService::class); $before = $s->snapshot($s->current()); $count = StudentTransferPolicyChange::count();
    foreach ([['minimum_cents' => 0], ['maximum_cents' => 600000], ['monthly_cents' => 1], ['enabled' => 1], ['minimum_cents' => '100'], ['unexpected' => 1], ['business_timezone' => 'UTC']] as $changes) {
        expect(fn () => stPolicy($this, $changes))->toThrow(InvalidArgumentException::class);
    }
    expect($s->snapshot($s->current()))->toBe($before)->and(StudentTransferPolicyChange::count())->toBe($count);
});
test('transfer posts two balanced entries once with a trusted actor and policy snapshot', function () {
    $from = stWallet($this, 50000); $to = stWallet($this, 1000); $key = stKey();
    $first = stSend($from, $to, 10000, $key); $again = stSend($from, $to, 10000, $key);
    expect(strtolower($again->public_id))->toBe(strtolower($first->public_id))->and($from->fresh()->available_balance_cents)->toBe(40000)
        ->and($to->fresh()->available_balance_cents)->toBe(11000)->and($first->actor_id)->toBe('user:' . $from->owner_id)
        ->and($first->policy_snapshot['daily_cents'])->toBe(500000);
    $entries = LedgerEntry::where('transaction_id', $first->financial_transaction_id)->get();
    expect($entries)->toHaveCount(2)->and((int) $entries->sum('amount_cents'))->toBe(0);
    stPolicy($this, ['minimum_cents' => 200]);
    expect(stSend($from, $to, 10000, $key)->policy_snapshot['minimum_cents'])->toBe(100);
});
test('gift and transfer have separate business types and jointly consume daily quota', function () {
    $from = stWallet($this, 600000); $to = stWallet($this);
    stSend($from, $to, 200000); $gift = stSend($from, $to, 200000, null, StudentTransferKind::REGALO);
    expect($gift->kind)->toBe(StudentTransferKind::REGALO)
        ->and(FinancialTransaction::where('public_id', $gift->financial_transaction_id)->firstOrFail()->reference_type)->toBe('STUDENT_GIFT');
    stSend($from, $to, 100000);
    stReject(fn () => stSend($from, $to, 100), 'diario');
    expect($from->fresh()->available_balance_cents)->toBe(100000);
});
test('idempotency rejects altered amount destination kind concept or sender and never posts another movement', function () {
    $from = stWallet($this, 50000); $to = stWallet($this); $other = stWallet($this, 50000); $key = stKey(); stSend($from, $to, 10000, $key);
    foreach ([fn () => stSend($from, $to, 20000, $key), fn () => stSend($from, $other, 10000, $key),
        fn () => stSend($from, $to, 10000, $key, StudentTransferKind::REGALO), fn () => stSend($other, $to, 10000, $key),
        fn () => app(StudentTransferService::class)->execute($from->owner_id, $from, $to, 10000, StudentTransferKind::TRANSFERENCIA, 'Otro', $key)] as $run) { stReject($run, 'clave'); }
    expect(StudentTransfer::where('idempotency_key', $key)->count())->toBe(1)->and($from->fresh()->available_balance_cents)->toBe(40000);
});
test('send receive and ownership grants are independent and checked again on replay', function () {
    $from = stWallet($this, 50000); $to = stWallet($this); $key = stKey();
    stGrant(false); expect(fn () => stSend($from, $to, 10000, $key))->toThrow(AuthorizationException::class);
    stGrant(true, false); expect(fn () => stSend($from, $to, 10000, $key))->toThrow(AuthorizationException::class);
    stGrant(); expect(fn () => app(StudentTransferService::class)->execute($to->owner_id, $from, $to, 10000, StudentTransferKind::TRANSFERENCIA, null, $key))->toThrow(AuthorizationException::class);
    stSend($from, $to, 10000, $key); stGrant(false);
    expect(fn () => stSend($from, $to, 10000, $key))->toThrow(AuthorizationException::class);
    expect((new PendingTransferAuthorizationProvider())->canManagePolicy($from->owner_id))->toBeFalse();
});
test('reserved money inactive wallets and inactive accounts cannot fund a transfer', function () {
    $from = stWallet($this, 10000); $to = stWallet($this);
    $from->update(['available_balance_cents' => 1000, 'held_balance_cents' => 9000]);
    stReject(fn () => stSend($from, $to, 10000), 'Saldo');
    $from->update(['available_balance_cents' => 10000, 'held_balance_cents' => 0]);
    $to->update(['status' => WalletStatus::BLOQUEADA]); stReject(fn () => stSend($from, $to), 'activas');
    $to->update(['status' => WalletStatus::ACTIVA]); User::find($to->owner_id)->update(['account_activation_pending' => true]);
    stReject(fn () => stSend($from, $to), 'activas');
    expect($from->fresh()->available_balance_cents)->toBe(10000)->and(StudentTransfer::where('source_wallet_id', $from->public_id)->count())->toBe(0);
});
test('self transfer different currency wrong wallet type and missing key are rejected', function () {
    $from = stWallet($this, 10000); $to = stWallet($this);
    stReject(fn () => stSend($from, $from), 'distintas');
    $to->update(['currency' => 'USD']); stReject(fn () => stSend($from, $to), 'MXN');
    $to->update(['currency' => 'MXN', 'type' => WalletType::NEGOCIO]); stReject(fn () => stSend($from, $to), 'personales');
    expect(fn () => stSend($from, $to, 10000, ''))->toThrow(InvalidArgumentException::class);
});
test('minimum maximum and disabled policy reject new operations while a successful replay stays unchanged', function () {
    $from = stWallet($this, 400000); $to = stWallet($this); $key = stKey();
    stReject(fn () => stSend($from, $to, 99), 'mínimo'); stReject(fn () => stSend($from, $to, 200001), 'máximo');
    $original = stSend($from, $to, 100, $key); stPolicy($this, ['enabled' => false]);
    stReject(fn () => stSend($from, $to, 100), 'deshabilitadas');
    expect(strtolower(stSend($from, $to, 100, $key)->public_id))->toBe(strtolower($original->public_id));
});
test('monthly quota includes both kinds and resets only at the business month boundary', function () {
    $from = stWallet($this, 10000); $to = stWallet($this); stPolicy($this, ['maximum_cents' => 1000, 'daily_cents' => 1000, 'monthly_cents' => 2000]);
    $start = CarbonImmutable::parse('2026-10-30 23:00:00', 'America/Mexico_City');
    CarbonImmutable::setTestNow($start); \Illuminate\Support\Carbon::setTestNow($start); stSend($from, $to, 1000);
    CarbonImmutable::setTestNow($start->addDay()); \Illuminate\Support\Carbon::setTestNow($start->addDay()); stSend($from, $to, 1000, null, StudentTransferKind::REGALO);
    CarbonImmutable::setTestNow($start->addDay()->addMinutes(30)); \Illuminate\Support\Carbon::setTestNow($start->addDay()->addMinutes(30));
    stReject(fn () => stSend($from, $to, 100), 'diario');
    // Raise daily budget, keeping a coherent monthly limit, to isolate the monthly rejection.
    stPolicy($this, ['daily_cents' => 2000]); stReject(fn () => stSend($from, $to, 100), 'mensual');
    CarbonImmutable::setTestNow($start->addDays(2)); \Illuminate\Support\Carbon::setTestNow($start->addDays(2)); stSend($from, $to, 1000);
    expect($from->fresh()->available_balance_cents)->toBe(7000);
});
test('failure while recording transfer history rolls back both wallets ledger and transaction and allows retry', function () {
    $from = stWallet($this, 20000); $to = stWallet($this); $key = stKey();
    StudentTransfer::creating(fn () => throw new RuntimeException('test-st-history-failure'));
    try { expect(fn () => stSend($from, $to, 10000, $key))->toThrow(RuntimeException::class, 'test-st-history-failure'); }
    finally { StudentTransfer::flushEventListeners(); }
    expect($from->fresh()->available_balance_cents)->toBe(20000)->and($to->fresh()->available_balance_cents)->toBe(0)
        ->and(FinancialTransaction::where('idempotency_key', 'student-transfer:' . hash('sha256', strtolower($key)))->exists())->toBeFalse();
    stSend($from, $to, 10000, $key); expect($to->fresh()->available_balance_cents)->toBe(10000);
});
test('a held idempotency mutex refuses a concurrent duplicate without moving money', function () {
    $from = stWallet($this, 20000); $to = stWallet($this); $key = stKey(); $lockKey = 'student-transfer:' . hash('sha256', strtolower($key));
    $locks = app(FinancialJobLock::class); $token = $locks->acquire($lockKey, 180);
    try { stReject(fn () => stSend($from, $to, 10000, $key), 'proceso'); }
    finally { $locks->release($lockKey, $token); }
    expect($to->fresh()->available_balance_cents)->toBe(0);
});
test('a stricter 2.10 limit blocks atomically and keeps its blocked evidence', function () {
    $from = stWallet($this, 20000); $to = stWallet($this);
    $limit = app(FinancialLimitService::class)->create(['name' => 'test-st-limit', 'subject_type' => 'OWNER', 'subject_id' => $from->owner_id,
        'operation' => 'TRANSFERENCIA_SALIDA', 'period' => 'OPERACION', 'metric' => 'MONTO', 'max_amount_cents' => 500,
        'action' => 'BLOQUEAR', 'currency' => 'MXN', 'reason' => 'Prueba de integración'], 'test-st-admin'); $this->stLimits[] = $limit->public_id;
    expect(fn () => stSend($from, $to, 1000))->toThrow(\App\Domains\Financial\Exceptions\FinancialLimitExceededException::class);
    expect($from->fresh()->available_balance_cents)->toBe(20000)->and($to->fresh()->available_balance_cents)->toBe(0)
        ->and(TransactionAlert::where('wallet_id', $from->public_id)->where('reference_type', 'STUDENT_TRANSFER_ATTEMPT')->count())->toBe(1);
});
test('policy audit failure leaves the version and limits unchanged', function () {
    stWallet($this); $s = app(StudentTransferPolicyService::class); $before = $s->snapshot($s->current());
    StudentTransferPolicyChange::creating(fn () => throw new RuntimeException('test-st-policy-audit-failure'));
    try { expect(fn () => stPolicy($this, ['minimum_cents' => 200]))->toThrow(RuntimeException::class); }
    finally { StudentTransferPolicyChange::flushEventListeners(); }
    expect($s->snapshot($s->current()))->toBe($before);
});

test('historical ledger transfers also consume the same sender quota', function () {
    $from = stWallet($this, 600000); $to = stWallet($this);
    app(LedgerService::class)->transfer($from, $to, 400000, stKey(), 'TEST_LEGACY_TRANSFER', (string) Str::uuid());
    stSend($from, $to, 100000); stReject(fn () => stSend($from, $to, 100), 'diario');
    expect($from->fresh()->available_balance_cents)->toBe(100000);
});
