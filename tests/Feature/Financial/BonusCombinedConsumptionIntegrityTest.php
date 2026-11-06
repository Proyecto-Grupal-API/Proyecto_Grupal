<?php

use App\Domains\Financial\Contracts\BonusAuthorizationProvider;
use App\Domains\Financial\Enums\{BonusType, BonusStatus};
use App\Domains\Financial\Models\{Bonus, BonusLedgerEntry, Wallet};
use App\Domains\Financial\Services\BonusService;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;

beforeEach(function () {
    if (!app()->environment('testing') || config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing'
        || config('database.connections.mongodb.database') !== 'campus_virtual_testing') {
        throw new RuntimeException('Solo bases de pruebas.');
    }
    DB::connection('sqlsrv')->beginTransaction();
    Http::preventStrayRequests();
    $provider = new class implements BonusAuthorizationProvider {
        public function canIssueBonus(string $issuerType, string $issuerId, BonusType $bonusType): bool { return true; }
    };
    $this->combinedIntegrityService = new BonusService($provider);
});
afterEach(function () {
    while (DB::connection('sqlsrv')->transactionLevel() > 0) DB::connection('sqlsrv')->rollBack();
});
function combinedIntegrityBonus(BonusService $service): Bonus
{
    return $service->issue('USER', 'fixture-beneficiary', 'SYSTEM', 'fixture-issuer', BonusType::BECA,
        100, now()->subMinute(), now()->addDay(), true, true, 'test-combined-integrity-'.Str::uuid());
}

test('combined consumption accepts UUID casing from SQL Server and preserves integer accounting', function () {
    $service = $this->combinedIntegrityService;
    $a = combinedIntegrityBonus($service); $b = combinedIntegrityBonus($service);
    $wallets = Wallet::orderBy('id')->get(['public_id', 'available_balance_cents', 'held_balance_cents'])->toArray();
    $result = $service->consumeMultiple([
        ['bonus_id' => strtolower($a->public_id), 'amount_cents' => 30],
        ['bonus_id' => strtoupper($b->public_id), 'amount_cents' => 100],
    ]);
    expect($result)->toHaveCount(2)
        ->and($a->fresh()->remaining_amount_cents)->toBe(70)
        ->and($b->fresh()->remaining_amount_cents)->toBe(0)
        ->and($b->fresh()->status)->toBe(BonusStatus::AGOTADO);
    $entries = BonusLedgerEntry::whereIn('bonus_id', [$a->public_id, $b->public_id])
        ->where('movement_type', 'CONSUMO')->get();
    expect($entries)->toHaveCount(2)->and($entries->sum('amount_cents'))->toBe(-130)
        ->and(Wallet::orderBy('id')->get(['public_id', 'available_balance_cents', 'held_balance_cents'])->toArray())->toBe($wallets);
    Http::assertNothingSent();
});

test('combined consumption rejects malformed later amounts before expiring an earlier bonus', function () {
    $service = $this->combinedIntegrityService;
    $a = combinedIntegrityBonus($service); $b = combinedIntegrityBonus($service);
    $a->update(['valid_from' => now()->subDays(2), 'expires_at' => now()->subDay()]);
    $before = BonusLedgerEntry::whereIn('bonus_id', [$a->public_id, $b->public_id])->count();
    foreach ([1.5, '10', true, null] as $amount) {
        expect(fn () => $service->consumeMultiple([
            ['bonus_id' => $a->public_id, 'amount_cents' => 10],
            ['bonus_id' => $b->public_id, 'amount_cents' => $amount],
        ]))->toThrow(InvalidArgumentException::class);
    }
    expect($a->fresh()->status)->toBe(BonusStatus::ACTIVO)
        ->and($a->fresh()->remaining_amount_cents)->toBe(100)
        ->and($b->fresh()->remaining_amount_cents)->toBe(100)
        ->and(BonusLedgerEntry::whereIn('bonus_id', [$a->public_id, $b->public_id])->count())->toBe($before);
});

test('combined consumption detects the same UUID with different casing before touching its balance', function () {
    $service = $this->combinedIntegrityService; $a = combinedIntegrityBonus($service);
    $before = $a->ledgerEntries()->count();
    expect(fn () => $service->consumeMultiple([
        ['bonus_id' => strtolower($a->public_id), 'amount_cents' => 10],
        ['bonus_id' => strtoupper($a->public_id), 'amount_cents' => 10],
    ]))->toThrow(InvalidArgumentException::class);
    expect($a->fresh()->remaining_amount_cents)->toBe(100)->and($a->ledgerEntries()->count())->toBe($before);
});
