<?php

use App\Domains\Financial\Contracts\{InstitutionalBudgetAuthorization, FinancialWebAuthorizer, BonusAuthorizationProvider};
use App\Domains\Financial\Enums\{BonusType, BonusRestrictionType};
use App\Domains\Financial\Models\{InstitutionalBonusBudget, InstitutionalBudgetOperation, BonusBudgetCommitment, Bonus,
    BonusLedgerEntry, BonusAdministrativeOperation, Wallet};
use App\Domains\Financial\Services\{InstitutionalBudgetService, InstitutionalBecaIssuanceService, BonusService, BonusAdministrationService};
use App\Domains\Financial\Exceptions\IdentityFinancialAuthorizationUnavailable;
use App\Models\User;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

beforeEach(function () {
    if (!app()->environment('testing') || config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing'
        || config('database.connections.mongodb.database') !== 'campus_virtual_testing') throw new RuntimeException('Solo bases de pruebas.');
    DB::connection('sqlsrv')->beginTransaction(); Http::preventStrayRequests(); $this->freezeTime();
    $this->bcOperator = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->bcRecipient = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->bcActor = (string) $this->bcOperator->getKey();
    $this->bcAuthority = new class implements InstitutionalBudgetAuthorization {
        public bool $commit = true;
        public function allows(string $actorId, string $action, ?string $budgetId = null): bool { return $action !== 'budget.commit' || $this->commit; }
    };
    $this->bcAccess = new class implements FinancialWebAuthorizer {
        public bool $issue = true;
        public function allows(string $userId, string $action, ?string $walletId = null): bool { return $action === 'bonus.issue' && $this->issue; }
    };
    $this->bcIssuer = new class implements BonusAuthorizationProvider {
        public bool $allowed = true; public bool $unavailable = false;
        public function canIssueBonus(string $issuerType, string $issuerId, BonusType $type): bool {
            if ($this->unavailable) throw new IdentityFinancialAuthorizationUnavailable('FIXTURE_UNAVAILABLE');
            return $this->allowed && $issuerType === 'USER' && $type === BonusType::BECA;
        }
    };
    app()->instance(InstitutionalBudgetAuthorization::class, $this->bcAuthority);
    app()->instance(FinancialWebAuthorizer::class, $this->bcAccess);
    app()->instance(BonusAuthorizationProvider::class, $this->bcIssuer);
    $this->bcService = app(InstitutionalBecaIssuanceService::class);
    $data = ['institution_reference' => 'fixture-institution', 'approval_reference' => 'approval-'.Str::uuid(),
        'payer_reference' => 'fixture-approved-payer', 'purpose' => 'Programa BECA', 'approved_amount_cents' => 10000,
        'currency' => 'MXN', 'reason' => 'Aprobacion de prueba', 'valid_from' => now()->subDay()->toISOString(), 'expires_at' => now()->addMonth()->toISOString()];
    $r = app(InstitutionalBudgetService::class)->approve($this->bcActor, $data, 'approve-'.Str::uuid());
    $this->bcBudget = InstitutionalBonusBudget::where('public_id', $r['operation']->budget_id)->firstOrFail();
});
afterEach(function () {
    InstitutionalBudgetOperation::flushEventListeners(); BonusBudgetCommitment::flushEventListeners();
    while (DB::connection('sqlsrv')->transactionLevel() > 0) DB::connection('sqlsrv')->rollBack();
});
function bcIssuePayload($test): array
{
    return ['award_reference' => 'fixture-award-'.Str::uuid(), 'beneficiary_id' => (string) $test->bcRecipient->getKey(), 'type' => 'BECA', 'amount_cents' => 3000,
        'valid_from' => now()->subMinute()->toISOString(), 'expires_at' => now()->addDay()->toISOString(),
        'combinable' => true, 'allows_partial_use' => true, 'reason' => 'Beca de prueba con presupuesto',
        'external_reference' => 'test-budget-issue-'.Str::uuid(), 'restrictions' => []];
}
function bcIssue($test, ?array $data = null, ?string $key = null, int $version = 1): array
{
    return $test->bcService->issue($test->bcActor, $test->bcBudget->public_id, $version,
        $data ?? bcIssuePayload($test), $key ?? 'issue-'.Str::uuid());
}
function bcState($test): array
{
    return [$test->bcBudget->fresh()->toArray(), Bonus::count(), BonusLedgerEntry::count(), BonusAdministrativeOperation::count(),
        BonusBudgetCommitment::count(), InstitutionalBudgetOperation::count(),
        Wallet::orderBy('id')->get(['public_id','available_balance_cents','held_balance_cents'])->toArray()];
}

test('budget BECA issuance atomically creates the new bonus commitment and audits without cash', function () {
    $wallets = Wallet::orderBy('id')->get(['public_id','available_balance_cents','held_balance_cents'])->toArray();
    $r = bcIssue($this); $bonus = Bonus::where('public_id', $r['bonus_id'])->firstOrFail();
    $link = BonusBudgetCommitment::where('bonus_id', $bonus->public_id)->firstOrFail();
    expect(strtolower($bonus->institutional_budget_id))->toBe(strtolower($this->bcBudget->public_id))
        ->and($this->bcBudget->fresh()->committed_amount_cents)->toBe(3000)
        ->and($this->bcBudget->fresh()->consumed_amount_cents)->toBe(0)->and($this->bcBudget->fresh()->version)->toBe(2)
        ->and($link->amount_cents)->toBe(3000)->and($link->status)->toBe('COMMITTED')
        ->and($r['operation']->after_data['available_amount_cents'])->toBe(7000)
        ->and($bonus->ledgerEntries()->count())->toBe(1)
        ->and(Wallet::orderBy('id')->get(['public_id','available_balance_cents','held_balance_cents'])->toArray())->toBe($wallets);
    Http::assertNothingSent();
});

test('budget BECA replay does not duplicate a commitment and rejects changed amount budget actor or version', function () {
    $data = bcIssuePayload($this); $key = 'issue-'.Str::uuid(); $a = bcIssue($this, $data, $key); $before = bcState($this);
    $b = bcIssue($this, $data, strtoupper($key));
    expect($b['replayed'])->toBeTrue()->and($b['bonus_id'])->toBe($a['bonus_id'])->and(bcState($this))->toBe($before);
    expect(fn () => bcIssue($this, [...$data, 'amount_cents' => 4000], $key))->toThrow(ConflictHttpException::class);
    expect(fn () => bcIssue($this, $data, $key, 2))->toThrow(ConflictHttpException::class);
    expect(fn () => $this->bcService->issue((string) $this->bcRecipient->getKey(), $this->bcBudget->public_id, 1,
        [...$data, 'beneficiary_id' => $this->bcActor], $key))->toThrow(ConflictHttpException::class);
});

test('budget BECA insufficient capacity and stale review never issue an additional bonus', function () {
    bcIssue($this, [...bcIssuePayload($this), 'amount_cents' => 8000]); $before = bcState($this);
    expect(fn () => bcIssue($this, null, null, 1))->toThrow(ConflictHttpException::class);
    expect(fn () => bcIssue($this, [...bcIssuePayload($this), 'amount_cents' => 2001], null, 2))->toThrow(ConflictHttpException::class);
    expect(bcState($this))->toBe($before);
    bcIssue($this, [...bcIssuePayload($this), 'amount_cents' => 2000], null, 2);
    expect($this->bcBudget->fresh()->committed_amount_cents)->toBe(10000);
});

test('budget BECA suspended future expired wrong currency and out of range validity reject issuance', function () {
    foreach ([['status' => 'SUSPENDED'], ['valid_from' => now()->addHour()], ['expires_at' => now()->subSecond()], ['currency' => 'USD']] as $changes) {
        $this->bcBudget->forceFill($changes)->save(); $before = bcState($this);
        expect(fn () => bcIssue($this))->toThrow(ConflictHttpException::class);
        expect(bcState($this))->toBe($before);
        $this->bcBudget->forceFill(['status' => 'ACTIVE', 'valid_from' => now()->subDay(), 'expires_at' => now()->addMonth(), 'currency' => 'MXN'])->save();
    }
    $before = bcState($this);
    expect(fn () => bcIssue($this, [...bcIssuePayload($this), 'expires_at' => now()->addMonths(2)->toISOString()]))->toThrow(ConflictHttpException::class);
    expect(bcState($this))->toBe($before);
});

test('budget BECA requires budget web and issuer authority and distinguishes identity unavailability', function () {
    $before = bcState($this);
    $this->bcAuthority->commit = false;
    expect(fn () => bcIssue($this))->toThrow(AuthorizationException::class);
    $this->bcAuthority->commit = true; $this->bcAccess->issue = false;
    expect(fn () => bcIssue($this))->toThrow(AuthorizationException::class);
    $this->bcAccess->issue = true; $this->bcIssuer->allowed = false;
    expect(fn () => bcIssue($this))->toThrow(AuthorizationException::class);
    $this->bcIssuer->allowed = true; $this->bcIssuer->unavailable = true;
    expect(fn () => bcIssue($this))->toThrow(IdentityFinancialAuthorizationUnavailable::class);
    expect(bcState($this))->toBe($before);
});

test('budget BECA rechecks authority before replaying the original operation', function () {
    $data = bcIssuePayload($this); $key = 'issue-'.Str::uuid(); bcIssue($this, $data, $key); $before = bcState($this);
    $this->bcIssuer->allowed = false;
    expect(fn () => bcIssue($this, $data, $key))->toThrow(AuthorizationException::class);
    expect(bcState($this))->toBe($before);
});

test('budget BECA cannot finance an earlier administrative emission or another bonus type', function () {
    $data = bcIssuePayload($this); $key = 'issue-'.Str::uuid();
    app(BonusAdministrationService::class)->issue($this->bcActor, $data, $key); $before = bcState($this);
    expect(fn () => bcIssue($this, $data, $key))->toThrow(ConflictHttpException::class);
    expect(fn () => bcIssue($this, [...$data, 'type' => 'BENEFICIO']))->toThrow(InvalidArgumentException::class);
    expect(bcState($this))->toBe($before);
});

test('budget BECA audit failure rolls back bonus ledger administrative evidence and capacity', function () {
    $before = bcState($this); $data = bcIssuePayload($this); $key = 'issue-'.Str::uuid();
    InstitutionalBudgetOperation::creating(function ($op) { if ($op->action === 'ISSUE_BECA') throw new RuntimeException('fixture-budget-issue-audit'); });
    try { expect(fn () => bcIssue($this, $data, $key))->toThrow(RuntimeException::class); }
    finally { InstitutionalBudgetOperation::flushEventListeners(); }
    expect(bcState($this))->toBe($before);
    expect(bcIssue($this, $data, $key)['replayed'])->toBeFalse();
});

test('budget BECA commitment persistence failure rolls back the entire issuance', function () {
    $before = bcState($this);
    BonusBudgetCommitment::creating(function () { throw new RuntimeException('fixture-commitment-failure'); });
    try { expect(fn () => bcIssue($this))->toThrow(RuntimeException::class); }
    finally { BonusBudgetCommitment::flushEventListeners(); }
    expect(bcState($this))->toBe($before);
});

test('budget BECA replay with broken binding fails closed instead of attaching or repairing a bonus', function () {
    $data = bcIssuePayload($this); $key = 'issue-'.Str::uuid(); $r = bcIssue($this, $data, $key);
    DB::connection('sqlsrv')->table('bonuses')->where('public_id', $r['bonus_id'])->update(['institutional_budget_id' => null]);
    $before = bcState($this);
    expect(fn () => bcIssue($this, $data, $key))->toThrow(ConflictHttpException::class);
    expect(bcState($this))->toBe($before);
});

test('budget BECA legacy lifecycle cannot consume cancel expire or change restrictions of linked bonuses', function () {
    $r = bcIssue($this); $service = app(BonusService::class); $before = bcState($this);
    expect(fn () => $service->consume($r['bonus_id'], 100))->toThrow(ConflictHttpException::class);
    expect(fn () => $service->cancel($r['bonus_id'], 'user:'.$this->bcActor, 'No liberar sin presupuesto'))->toThrow(ConflictHttpException::class);
    expect(fn () => $service->expireIfNeeded($r['bonus_id']))->toThrow(ConflictHttpException::class);
    expect(fn () => $service->addRestriction($r['bonus_id'], BonusRestrictionType::NEGOCIO, 'fixture-business'))->toThrow(ConflictHttpException::class);
    expect(bcState($this))->toBe($before);
});

test('budget BECA combined consumption rejects linked bonuses before expiring unrelated historical balances', function () {
    $r = bcIssue($this);
    $legacy = app(BonusService::class)->issue('USER', (string) $this->bcRecipient->getKey(), 'USER', $this->bcActor,
        BonusType::BECA, 100, now()->subDays(2), now()->addDay(), true, true);
    $legacy->update(['expires_at' => now()->subDay()]); $entries = $legacy->ledgerEntries()->count();
    expect(fn () => app(BonusService::class)->consumeMultiple([
        ['bonus_id' => $legacy->public_id, 'amount_cents' => 10], ['bonus_id' => $r['bonus_id'], 'amount_cents' => 10],
    ]))->toThrow(ConflictHttpException::class);
    expect($legacy->fresh()->remaining_amount_cents)->toBe(100)->and($legacy->ledgerEntries()->count())->toBe($entries);
});

test('budget BECA same award cannot be issued twice using a new key', function () {
    $data = bcIssuePayload($this); bcIssue($this, $data); $before = bcState($this);
    expect(fn () => bcIssue($this, $data, null, 2))->toThrow(ConflictHttpException::class);
    expect(bcState($this))->toBe($before);
});

test('budget BECA self issuance and missing award reference reject without financial evidence', function () {
    $before = bcState($this); $data = bcIssuePayload($this);
    expect(fn () => bcIssue($this, [...$data, 'beneficiary_id' => $this->bcActor]))->toThrow(InvalidArgumentException::class);
    unset($data['award_reference']);
    expect(fn () => bcIssue($this, $data))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(bcState($this))->toBe($before);
});

test('budget BECA real commitments protect later administrative reductions', function () {
    bcIssue($this);
    expect(fn () => app(InstitutionalBudgetService::class)->setApprovedAmount($this->bcActor,
        $this->bcBudget->public_id, 2, 2999, 'Reduccion sin capacidad', 'adjust-'.Str::uuid()))->toThrow(ConflictHttpException::class);
    expect($this->bcBudget->fresh()->approved_amount_cents)->toBe(10000)
        ->and($this->bcBudget->fresh()->committed_amount_cents)->toBe(3000);
});
