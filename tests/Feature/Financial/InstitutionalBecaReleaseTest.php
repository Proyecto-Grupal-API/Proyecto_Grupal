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
    $this->brOperator = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->brRecipient = User::factory()->create(['email_verified_at' => now(), 'account_activation_pending' => false, 'must_change_password' => false]);
    $this->brActor = (string) $this->brOperator->getKey();
    $this->brAuthority = new class implements InstitutionalBudgetAuthorization {
        public bool $commit = true; public bool $release = true; public bool $expire = true;
        public function allows(string $actorId, string $action, ?string $budgetId = null): bool { return match ($action) { 'budget.commit' => $this->commit, 'budget.release' => $this->release, 'budget.expire' => $this->expire, default => true };  }
    };
    $this->brAccess = new class implements FinancialWebAuthorizer {
        public bool $issue = true; public bool $cancel = true;
        public function allows(string $userId, string $action, ?string $walletId = null): bool { return match ($action) { 'bonus.issue' => $this->issue, 'bonus.cancel' => $this->cancel, default => false };  }
    };
    $this->brIssuer = new class implements BonusAuthorizationProvider {
        public bool $allowed = true; public bool $unavailable = false;
        public function canIssueBonus(string $issuerType, string $issuerId, BonusType $type): bool {
            if ($this->unavailable) throw new IdentityFinancialAuthorizationUnavailable('FIXTURE_UNAVAILABLE');
            return $this->allowed && $issuerType === 'USER' && $type === BonusType::BECA;
        }
    };
    app()->instance(InstitutionalBudgetAuthorization::class, $this->brAuthority);
    app()->instance(FinancialWebAuthorizer::class, $this->brAccess);
    app()->instance(BonusAuthorizationProvider::class, $this->brIssuer);
    $this->brService = app(InstitutionalBecaIssuanceService::class);
    $data = ['institution_reference' => 'fixture-institution', 'approval_reference' => 'approval-'.Str::uuid(),
        'payer_reference' => 'fixture-approved-payer', 'purpose' => 'Programa BECA', 'approved_amount_cents' => 10000,
        'currency' => 'MXN', 'reason' => 'Aprobacion de prueba', 'valid_from' => now()->subDay()->toISOString(), 'expires_at' => now()->addMonth()->toISOString()];
    $r = app(InstitutionalBudgetService::class)->approve($this->brActor, $data, 'approve-'.Str::uuid());
    $this->brBudget = InstitutionalBonusBudget::where('public_id', $r['operation']->budget_id)->firstOrFail();
});
afterEach(function () {
    InstitutionalBudgetOperation::flushEventListeners(); BonusBudgetCommitment::flushEventListeners(); BonusLedgerEntry::flushEventListeners();
    while (DB::connection('sqlsrv')->transactionLevel() > 0) DB::connection('sqlsrv')->rollBack();
});
function brIssuePayload($test): array
{
    return ['award_reference' => 'fixture-award-'.Str::uuid(), 'beneficiary_id' => (string) $test->brRecipient->getKey(), 'type' => 'BECA', 'amount_cents' => 3000,
        'valid_from' => now()->subMinute()->toISOString(), 'expires_at' => now()->addDay()->toISOString(),
        'combinable' => true, 'allows_partial_use' => true, 'reason' => 'Beca de prueba con presupuesto',
        'external_reference' => 'test-budget-issue-'.Str::uuid(), 'restrictions' => []];
}
function brIssue($test, ?array $data = null, ?string $key = null, int $version = 1): array
{
    return $test->brService->issue($test->brActor, $test->brBudget->public_id, $version,
        $data ?? brIssuePayload($test), $key ?? 'issue-'.Str::uuid());
}
function brState($test): array
{
    return [$test->brBudget->fresh()->toArray(), Bonus::count(), BonusLedgerEntry::count(), BonusAdministrativeOperation::count(),
        BonusBudgetCommitment::count(), InstitutionalBudgetOperation::count(),
        Wallet::orderBy('id')->get(['public_id','available_balance_cents','held_balance_cents'])->toArray()];
}


function brRelease($test, string $id, ?string $key = null, bool $expiry = false, int $version = 2, string $reason = 'Liberacion de prueba'): array
{
    $service = app(\App\Domains\Financial\Services\InstitutionalBecaReleaseService::class);
    return $service->{$expiry ? 'expire' : 'cancel'}($test->brActor, $test->brBudget->public_id, $id, $version, $reason, $key ?? 'release-'.Str::uuid());
}

test('budget release cancellation atomically releases capacity and records evidence without moving cash', function () {
    $issue = brIssue($this); $wallets = Wallet::orderBy('id')->get()->toArray();
    $result = brRelease($this, $issue['bonus_id']);
    $bonus = Bonus::where('public_id', $issue['bonus_id'])->firstOrFail();
    expect($bonus->status)->toBe(\App\Domains\Financial\Enums\BonusStatus::CANCELADO)
        ->and($bonus->remaining_amount_cents)->toBe(0)->and($bonus->ledgerEntries()->count())->toBe(2)
        ->and($this->brBudget->fresh()->committed_amount_cents)->toBe(0)
        ->and($this->brBudget->fresh()->consumed_amount_cents)->toBe(0)->and($this->brBudget->fresh()->version)->toBe(3)
        ->and($result['operation']->after_data['available_amount_cents'])->toBe(10000)
        ->and(BonusBudgetCommitment::where('bonus_id', $bonus->public_id)->firstOrFail()->amount_cents)->toBe(3000)
        ->and(Wallet::orderBy('id')->get()->toArray())->toBe($wallets);
    Http::assertNothingSent();
});

test('budget release expiry rejects early execution and permits the exact expiry instant', function () {
    $issue = brIssue($this); $before = brState($this);
    expect(fn () => brRelease($this, $issue['bonus_id'], null, true))->toThrow(ConflictHttpException::class);
    expect(brState($this))->toBe($before);
    $bonus = Bonus::where('public_id', $issue['bonus_id'])->firstOrFail(); $this->travelTo($bonus->expires_at);
    expect(fn () => brRelease($this, $issue['bonus_id']))->toThrow(ConflictHttpException::class);
    $result = brRelease($this, $issue['bonus_id'], null, true);
    expect($result['operation']->action)->toBe('EXPIRE_BECA')
        ->and($bonus->fresh()->status)->toBe(\App\Domains\Financial\Enums\BonusStatus::EXPIRADO)
        ->and($this->brBudget->fresh()->committed_amount_cents)->toBe(0);
});

test('budget release exact replay preserves all amounts and rejects altered requests or new terminal actions', function () {
    $issue = brIssue($this); $key = 'release-'.Str::uuid(); $a = brRelease($this, $issue['bonus_id'], $key); $before = brState($this);
    $b = brRelease($this, strtoupper($issue['bonus_id']), strtoupper($key));
    expect($b['replayed'])->toBeTrue()->and(strtolower($b['operation']->public_id))->toBe(strtolower($a['operation']->public_id));
    expect(fn () => brRelease($this, $issue['bonus_id'], $key, false, 3))->toThrow(ConflictHttpException::class);
    expect(fn () => brRelease($this, $issue['bonus_id'], $key, false, 2, 'Otro motivo'))->toThrow(ConflictHttpException::class);
    expect(fn () => brRelease($this, $issue['bonus_id'], null))->toThrow(ConflictHttpException::class);
    expect(brState($this))->toBe($before);
});

test('budget release rechecks separate cancellation and budget permissions even before replay', function () {
    $issue = brIssue($this); $key = 'release-'.Str::uuid(); $before = brState($this);
    $this->brAuthority->release = false;
    expect(fn () => brRelease($this, $issue['bonus_id'], $key))->toThrow(AuthorizationException::class);
    $this->brAuthority->release = true; $this->brAccess->cancel = false;
    expect(fn () => brRelease($this, $issue['bonus_id'], $key))->toThrow(AuthorizationException::class);
    expect(brState($this))->toBe($before);
    $this->brAccess->cancel = true; brRelease($this, $issue['bonus_id'], $key); $before = brState($this);
    $this->brAuthority->release = false;
    expect(fn () => brRelease($this, $issue['bonus_id'], $key))->toThrow(AuthorizationException::class);
    expect(brState($this))->toBe($before);
});

test('budget release expiry requires its own authority and does not inherit cancellation permission', function () {
    $issue = brIssue($this); $this->travelTo(Bonus::where('public_id', $issue['bonus_id'])->firstOrFail()->expires_at);
    $this->brAuthority->expire = false; $before = brState($this);
    expect(fn () => brRelease($this, $issue['bonus_id'], null, true))->toThrow(AuthorizationException::class);
    expect(brState($this))->toBe($before);
});

test('budget release audit persistence failure rolls back bonus capacity ledger and allows retry', function () {
    $issue = brIssue($this); $key = 'release-'.Str::uuid(); $before = brState($this);
    InstitutionalBudgetOperation::creating(function ($op) { if ($op->action === 'CANCEL_BECA') throw new RuntimeException('fixture-release-audit'); });
    try { expect(fn () => brRelease($this, $issue['bonus_id'], $key))->toThrow(RuntimeException::class); }
    finally { InstitutionalBudgetOperation::flushEventListeners(); }
    expect(brState($this))->toBe($before);
    expect(brRelease($this, $issue['bonus_id'], $key)['replayed'])->toBeFalse();
});

test('budget release ledger persistence failure rolls back budget bonus and operation evidence', function () {
    $issue = brIssue($this); $before = brState($this);
    BonusLedgerEntry::creating(function ($entry) { if ($entry->reference_type === 'BUDGET_RELEASE') throw new RuntimeException('fixture-release-ledger'); });
    try { expect(fn () => brRelease($this, $issue['bonus_id']))->toThrow(RuntimeException::class); }
    finally { BonusLedgerEntry::flushEventListeners(); }
    expect(brState($this))->toBe($before);
});

test('budget release rejects stale versions and broken markers without repairs', function () {
    $issue = brIssue($this); $before = brState($this);
    expect(fn () => brRelease($this, $issue['bonus_id'], null, false, 1))->toThrow(ConflictHttpException::class);
    expect(brState($this))->toBe($before);
    DB::connection('sqlsrv')->table('bonuses')->where('public_id', $issue['bonus_id'])->update(['institutional_budget_id' => null]);
    $before = brState($this);
    expect(fn () => brRelease($this, $issue['bonus_id']))->toThrow(ConflictHttpException::class);
    expect(brState($this))->toBe($before);
});

test('budget release refuses altered balances or uncoordinated consumption instead of guessing the remainder', function () {
    $issue = brIssue($this); $bonus = Bonus::where('public_id', $issue['bonus_id'])->firstOrFail();
    $bonus->update(['remaining_amount_cents' => 2900]); $before = brState($this);
    expect(fn () => brRelease($this, $issue['bonus_id']))->toThrow(ConflictHttpException::class);
    expect(brState($this))->toBe($before);
});

test('budget release can free unused commitments in a suspended or expired budget', function () {
    $issue = brIssue($this);
    $this->brBudget->update(['status' => 'SUSPENDED']);
    $this->travelTo($this->brBudget->expires_at);
    brRelease($this, $issue['bonus_id'], null, true);
    expect($this->brBudget->fresh()->status)->toBe('SUSPENDED')->and($this->brBudget->fresh()->committed_amount_cents)->toBe(0);
});

test('budget release replay fails closed on missing cancellation ledger evidence', function () {
    $issue = brIssue($this); $key = 'release-'.Str::uuid(); brRelease($this, $issue['bonus_id'], $key);
    DB::connection('sqlsrv')->table('bonus_ledger_entries')->where('bonus_id', $issue['bonus_id'])->where('reference_type', 'BUDGET_RELEASE')->delete();
    $before = brState($this);
    expect(fn () => brRelease($this, $issue['bonus_id'], $key))->toThrow(ConflictHttpException::class);
    expect(brState($this))->toBe($before);
});

test('budget release preserves other awards and consumed totals and allows a later budget reduction', function () {
    $a = brIssue($this); $b = brIssue($this, null, null, 2);
    brRelease($this, $a['bonus_id'], null, false, 3);
    expect($this->brBudget->fresh()->committed_amount_cents)->toBe(3000)
        ->and(Bonus::where('public_id', $b['bonus_id'])->firstOrFail()->remaining_amount_cents)->toBe(3000);
    app(InstitutionalBudgetService::class)->setApprovedAmount($this->brActor, $this->brBudget->public_id, 4,
        3000, 'Ajuste posterior a liberacion', 'adjust-'.Str::uuid());
    expect($this->brBudget->fresh()->approved_amount_cents)->toBe(3000);
});

test('budget release default authority denies even when general cancellation permission exists', function () {
    $issue = brIssue($this); $before = brState($this);
    app()->instance(InstitutionalBudgetAuthorization::class, new \App\Domains\Financial\Adapters\PendingInstitutionalBudgetAuthorization());
    expect(fn () => brRelease($this, $issue['bonus_id']))->toThrow(AuthorizationException::class);
    expect(brState($this))->toBe($before);
});
