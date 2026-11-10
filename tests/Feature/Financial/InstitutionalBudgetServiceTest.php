<?php

use App\Domains\Financial\Contracts\InstitutionalBudgetAuthorization;
use App\Domains\Financial\Models\{InstitutionalBonusBudget, InstitutionalBudgetOperation, Bonus, BonusLedgerEntry, Wallet};
use App\Domains\Financial\Services\InstitutionalBudgetService;
use App\Domains\Financial\Support\FinancialJobLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

beforeEach(function () {
    if (!app()->environment('testing') || config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing'
        || config('database.connections.mongodb.database') !== 'campus_virtual_testing') throw new RuntimeException('Solo bases de pruebas.');
    DB::connection('sqlsrv')->beginTransaction(); Http::preventStrayRequests();
    $this->budgetActor = '507f1f77bcf86cd799439011';
    $this->budgetAuthority = new class implements InstitutionalBudgetAuthorization {
        public bool $allowed = true;
        public function allows(string $actorId, string $action, ?string $budgetId = null): bool { return $this->allowed; }
    };
    $this->budgetService = new InstitutionalBudgetService($this->budgetAuthority, app(FinancialJobLock::class));
});
afterEach(function () {
    InstitutionalBudgetOperation::flushEventListeners();
    while (DB::connection('sqlsrv')->transactionLevel() > 0) DB::connection('sqlsrv')->rollBack();
});
function ibudgetPayload(): array
{
    return ['institution_reference' => 'fixture-institution', 'approval_reference' => 'approval-'.Str::uuid(),
        'payer_reference' => 'fixture-institution-payer', 'purpose' => 'Programa BECA de prueba',
        'approved_amount_cents' => 10000, 'currency' => 'MXN', 'reason' => 'Aprobacion de prueba aislada',
        'valid_from' => now()->subMinute()->toISOString(), 'expires_at' => now()->addMonth()->toISOString()];
}
function ibudgetMoney(): array
{
    return [Bonus::count(), BonusLedgerEntry::count(),
        Wallet::orderBy('id')->get(['public_id', 'available_balance_cents', 'held_balance_cents'])->toArray()];
}
function ibudgetApprove($test): InstitutionalBonusBudget
{
    $r = $test->budgetService->approve($test->budgetActor, ibudgetPayload(), 'budget-'.Str::uuid());
    return InstitutionalBonusBudget::where('public_id', $r['operation']->budget_id)->firstOrFail();
}

test('institutional budgets deny all production administration through the default binding', function () {
    $before = InstitutionalBonusBudget::count();
    expect(app(InstitutionalBudgetAuthorization::class)->allows($this->budgetActor, 'budget.approve'))->toBeFalse();
    expect(fn () => app(InstitutionalBudgetService::class)->approve($this->budgetActor, ibudgetPayload(), 'budget-'.Str::uuid()))
        ->toThrow(AuthorizationException::class);
    expect(InstitutionalBonusBudget::count())->toBe($before);
    Http::assertNothingSent();
});

test('institutional budget approval creates a zero cash budget and audit without changing wallets or bonuses', function () {
    $money = ibudgetMoney(); $row = ibudgetApprove($this);
    $snapshot = $this->budgetService->snapshot($row);
    expect($row->bonus_type)->toBe('BECA')->and($row->version)->toBe(1)
        ->and($row->committed_amount_cents)->toBe(0)->and($row->consumed_amount_cents)->toBe(0)
        ->and($snapshot['available_amount_cents'])->toBe(10000)->and($snapshot['cash_backing_certified'])->toBeFalse()
        ->and(InstitutionalBudgetOperation::where('budget_id', $row->public_id)->count())->toBe(1)
        ->and(ibudgetMoney())->toBe($money);
    Http::assertNothingSent();
});

test('institutional approval retry is idempotent and rejects altered payload actor and duplicate approval reference', function () {
    $data = ibudgetPayload(); $key = 'budget-'.Str::uuid();
    $a = $this->budgetService->approve($this->budgetActor, $data, $key);
    $b = $this->budgetService->approve($this->budgetActor, $data, strtoupper($key));
    expect(strtolower($a['operation']->public_id))->toBe(strtolower($b['operation']->public_id))->and($b['replayed'])->toBeTrue();
    expect(fn () => $this->budgetService->approve($this->budgetActor, [...$data, 'approved_amount_cents' => 1], $key))
        ->toThrow(ConflictHttpException::class);
    expect(fn () => $this->budgetService->approve('507f1f77bcf86cd799439012', $data, $key))->toThrow(ConflictHttpException::class);
    expect(fn () => $this->budgetService->approve($this->budgetActor, $data, 'budget-'.Str::uuid()))->toThrow(ConflictHttpException::class);
});

test('institutional adjustment checks the current version and records before and after', function () {
    $row = ibudgetApprove($this); $key = 'adjust-'.Str::uuid();
    $r = $this->budgetService->setApprovedAmount($this->budgetActor, $row->public_id, 1, 15000, 'Ampliacion aprobada', $key);
    expect($r['operation']->before_data['approved_amount_cents'])->toBe(10000)
        ->and($r['operation']->after_data['approved_amount_cents'])->toBe(15000)->and($row->fresh()->version)->toBe(2);
    expect($this->budgetService->setApprovedAmount($this->budgetActor, $row->public_id, 1, 15000, 'Ampliacion aprobada', $key)['replayed'])->toBeTrue();
    expect(fn () => $this->budgetService->setApprovedAmount($this->budgetActor, $row->public_id, 1, 20000, 'Formulario antiguo', 'adjust-'.Str::uuid()))
        ->toThrow(ConflictHttpException::class);
    expect($row->fresh()->approved_amount_cents)->toBe(15000);
});

test('institutional budget fabricated exposure blocks both insufficient and apparently sufficient adjustments', function () {
    $row = ibudgetApprove($this);
    // Counter-only exposure is now deliberately inconsistent; valid lifecycle coverage lives in the integrity suite.
    DB::connection('sqlsrv')->table('institutional_bonus_budgets')->where('public_id', $row->public_id)
        ->update(['committed_amount_cents' => 4000, 'consumed_amount_cents' => 3000]);
    expect(fn () => $this->budgetService->setApprovedAmount($this->budgetActor, $row->public_id, 1, 6999, 'Reduccion insuficiente', 'adjust-'.Str::uuid()))
        ->toThrow(ConflictHttpException::class);
    expect(fn () => $this->budgetService->setApprovedAmount($this->budgetActor, $row->public_id, 1, 7000, 'Reduccion aparente', 'adjust-'.Str::uuid()))
        ->toThrow(ConflictHttpException::class);
    expect($row->fresh()->approved_amount_cents)->toBe(10000)->and($row->fresh()->version)->toBe(1);
});

test('institutional budget suspension and resumption require their own decisions and preserve amounts', function () {
    $row = ibudgetApprove($this);
    $this->budgetService->setSuspended($this->budgetActor, $row->public_id, 1, true, 'Revision de programa', 'suspend-'.Str::uuid());
    expect($row->fresh()->status)->toBe('SUSPENDED');
    $this->budgetAuthority->allowed = false;
    expect(fn () => $this->budgetService->setSuspended($this->budgetActor, $row->public_id, 2, false, 'Sin autoridad', 'resume-'.Str::uuid()))
        ->toThrow(AuthorizationException::class);
    $this->budgetAuthority->allowed = true;
    $this->budgetService->setSuspended($this->budgetActor, $row->public_id, 2, false, 'Reactivacion aprobada', 'resume-'.Str::uuid());
    expect($row->fresh()->status)->toBe('ACTIVE')->and($row->fresh()->version)->toBe(3)
        ->and($row->fresh()->approved_amount_cents)->toBe(10000);
});

test('institutional budget revocation is checked before replaying an old approval', function () {
    $data = ibudgetPayload(); $key = 'budget-'.Str::uuid();
    $this->budgetService->approve($this->budgetActor, $data, $key); $this->budgetAuthority->allowed = false;
    expect(fn () => $this->budgetService->approve($this->budgetActor, $data, $key))->toThrow(AuthorizationException::class);
});

test('institutional budget audit failure rolls back approval and adjustment and allows a clean retry', function () {
    $row = ibudgetApprove($this); $before = InstitutionalBonusBudget::count();
    InstitutionalBudgetOperation::creating(function () { throw new RuntimeException('fixture-budget-audit-failure'); });
    $data = ibudgetPayload(); $key = 'budget-'.Str::uuid(); $adjust = 'adjust-'.Str::uuid();
    try {
        expect(fn () => $this->budgetService->approve($this->budgetActor, $data, $key))->toThrow(RuntimeException::class);
        expect(fn () => $this->budgetService->setApprovedAmount($this->budgetActor, $row->public_id, 1, 20000, 'Ampliacion', $adjust))->toThrow(RuntimeException::class);
    } finally { InstitutionalBudgetOperation::flushEventListeners(); }
    expect(InstitutionalBonusBudget::count())->toBe($before)->and($row->fresh()->approved_amount_cents)->toBe(10000)
        ->and($row->fresh()->version)->toBe(1);
    expect($this->budgetService->approve($this->budgetActor, $data, $key)['replayed'])->toBeFalse();
    expect($this->budgetService->setApprovedAmount($this->budgetActor, $row->public_id, 1, 20000, 'Ampliacion', $adjust)['replayed'])->toBeFalse();
});

test('institutional budget rejects malformed amounts references dates reasons and keys without budget evidence', function () {
    $data = ibudgetPayload(); $before = InstitutionalBonusBudget::count();
    foreach ([0, -1, 1.5, '100', true] as $amount) {
        expect(fn () => $this->budgetService->approve($this->budgetActor, [...$data, 'approved_amount_cents' => $amount], 'budget-'.Str::uuid()))
            ->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => $this->budgetService->approve($this->budgetActor, [...$data, 'expires_at' => now()->subDay()->toISOString()], 'budget-'.Str::uuid()))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->budgetService->approve($this->budgetActor, [...$data, 'payer_reference' => ' '], 'budget-'.Str::uuid()))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(fn () => $this->budgetService->approve($this->budgetActor, [...$data, 'reason' => "a\nb"], 'budget-'.Str::uuid()))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->budgetService->approve($this->budgetActor, $data, 'bad key'))->toThrow(InvalidArgumentException::class);
    expect(InstitutionalBonusBudget::count())->toBe($before);
});

test('institutional budget rejects a concurrent in progress request without creating another approval', function () {
    $key = 'budget-'.Str::uuid(); $lockKey = 'institution-budget:'.hash('sha256', strtolower($key));
    $lock = app(FinancialJobLock::class); $token = $lock->acquire($lockKey, 180); $before = InstitutionalBonusBudget::count();
    try {
        expect(fn () => $this->budgetService->approve($this->budgetActor, ibudgetPayload(), $key))->toThrow(ConflictHttpException::class);
    } finally { $lock->release($lockKey, $token); }
    expect(InstitutionalBonusBudget::count())->toBe($before);
});

test('institutional suspended expired budgets cannot be resumed and unchanged adjustments do not invent history', function () {
    $row = ibudgetApprove($this);
    expect(fn () => $this->budgetService->setApprovedAmount($this->budgetActor, $row->public_id, 1, 10000, 'Sin cambio', 'adjust-'.Str::uuid()))
        ->toThrow(ConflictHttpException::class);
    $this->budgetService->setSuspended($this->budgetActor, $row->public_id, 1, true, 'Suspender', 'suspend-'.Str::uuid());
    DB::connection('sqlsrv')->table('institutional_bonus_budgets')->where('public_id', $row->public_id)
        ->update(['valid_from' => now()->subDays(2), 'expires_at' => now()->subDay()]);
    expect(fn () => $this->budgetService->setSuspended($this->budgetActor, $row->public_id, 2, false, 'Intento vencido', 'resume-'.Str::uuid()))
        ->toThrow(ConflictHttpException::class);
    expect($row->fresh()->status)->toBe('SUSPENDED')->and($row->fresh()->version)->toBe(2)
        ->and(InstitutionalBudgetOperation::where('budget_id', $row->public_id)->count())->toBe(2);
});

test('institutional approval authority does not implicitly grant adjustment or suspension', function () {
    $authority = new class implements InstitutionalBudgetAuthorization {
        public function allows(string $actorId, string $action, ?string $budgetId = null): bool { return $action === 'budget.approve'; }
    };
    $service = new InstitutionalBudgetService($authority, app(FinancialJobLock::class));
    $r = $service->approve($this->budgetActor, ibudgetPayload(), 'budget-'.Str::uuid()); $id = $r['operation']->budget_id;
    expect(fn () => $service->setApprovedAmount($this->budgetActor, $id, 1, 20000, 'Sin facultad de ajuste', 'adjust-'.Str::uuid()))
        ->toThrow(AuthorizationException::class);
    expect(fn () => $service->setSuspended($this->budgetActor, $id, 1, true, 'Sin facultad de suspension', 'suspend-'.Str::uuid()))
        ->toThrow(AuthorizationException::class);
    expect(InstitutionalBonusBudget::where('public_id', $id)->firstOrFail()->version)->toBe(1);
});
