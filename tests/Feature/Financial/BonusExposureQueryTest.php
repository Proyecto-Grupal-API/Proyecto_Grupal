<?php

use App\Domains\Financial\Contracts\BonusAuthorizationProvider;
use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Models\{Bonus, BonusLedgerEntry, Wallet};
use App\Domains\Financial\Services\{BonusService, BonusExposureQueryService};
use Illuminate\Support\Facades\{DB, Http, Artisan};
use Illuminate\Support\Str;

beforeEach(function () {
    if (!app()->environment('testing') || config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing'
        || config('database.connections.mongodb.database') !== 'campus_virtual_testing') {
        throw new RuntimeException('Solo bases de pruebas.');
    }
    DB::connection('sqlsrv')->beginTransaction();
    Http::preventStrayRequests();
    $this->freezeTime();
    $provider = new class implements BonusAuthorizationProvider {
        public function canIssueBonus(string $issuerType, string $issuerId, BonusType $bonusType): bool { return true; }
    };
    $this->exposureBonusService = new BonusService($provider);
});
afterEach(function () {
    while (DB::connection('sqlsrv')->transactionLevel() > 0) DB::connection('sqlsrv')->rollBack();
});
function exposureFixtureBonus($test, array $changes = []): Bonus
{
    $bonus = $test->exposureBonusService->issue('USER', 'fixture-exposure-recipient', 'SYSTEM', 'fixture-exposure-issuer',
        BonusType::BECA, 100, now()->subDay(), now()->addDay(), true, true, 'test-exposure-'.Str::uuid());
    if ($changes) $bonus->forceFill($changes)->save();
    return $bonus;
}
function exposureDataSnapshot(): array
{
    return [Bonus::orderBy('id')->get()->toArray(), BonusLedgerEntry::orderBy('id')->get()->toArray(),
        Wallet::orderBy('id')->get(['public_id', 'available_balance_cents', 'held_balance_cents'])->toArray()];
}

test('bonus exposure classifies current and scheduled balances and leaves financial evidence unchanged', function () {
    // Unique currency isolates the fixture from any preexisting test data.
    exposureFixtureBonus($this, ['currency' => 'XEA']);
    exposureFixtureBonus($this, ['currency' => 'XEA', 'valid_from' => now()->addHour(), 'expires_at' => now()->addDays(2), 'status' => 'PENDIENTE']);
    exposureFixtureBonus($this, ['currency' => 'XEA', 'valid_from' => now()->subDays(2), 'expires_at' => now()]);
    exposureFixtureBonus($this, ['currency' => 'XEA', 'status' => 'CANCELADO', 'remaining_amount_cents' => 0]);
    $before = exposureDataSnapshot();
    $report = app(BonusExposureQueryService::class)->report('BECA', 'XEA');
    $buckets = collect($report['groups'])->keyBy('bucket');
    expect($buckets->keys()->sort()->values()->all())->toBe(['AVAILABLE', 'EXPIRED_UNPROCESSED', 'SCHEDULED', 'TERMINAL'])
        ->and($buckets['AVAILABLE']['remaining_amount_cents'])->toBe('100')
        ->and($buckets['SCHEDULED']['bonus_count'])->toBe('1')
        ->and($buckets['TERMINAL']['remaining_amount_cents'])->toBe('0')
        ->and($report['funding_certified'])->toBeFalse()->and($report['money_moved'])->toBeFalse()
        ->and(exposureDataSnapshot())->toBe($before);
    Http::assertNothingSent();
});

test('bonus exposure separates currencies and reports anomalies instead of inventing backing', function () {
    exposureFixtureBonus($this, ['currency' => 'XEB', 'remaining_amount_cents' => 101]);
    exposureFixtureBonus($this, ['currency' => 'XEB', 'status' => 'EXPIRADO']);
    exposureFixtureBonus($this, ['currency' => 'XEC', 'remaining_amount_cents' => 0]);
    $report = app(BonusExposureQueryService::class)->report('BECA', 'XEB');
    expect(collect($report['groups'])->pluck('bucket')->sort()->values()->all())->toBe(['INVALID_RECORD', 'TERMINAL_WITH_BALANCE'])
        ->and(array_unique(array_column($report['groups'], 'currency')))->toBe(['XEB'])
        ->and($report['funding_evidence'])->toBe('NOT_RECORDED_IN_CURRENT_SCHEMA');
    expect(app(BonusExposureQueryService::class)->report('BECA', 'XEC')['groups'][0]['bucket'])->toBe('OPEN_WITHOUT_BALANCE');
});

test('bonus exposure preserves exact aggregates above PHP integer range as decimal strings', function () {
    foreach ([1, 2] as $i) {
        exposureFixtureBonus($this, ['currency' => 'XED', 'original_amount_cents' => 6000000000000000000,
            'remaining_amount_cents' => 6000000000000000000]);
    }
    $r = app(BonusExposureQueryService::class)->report('BECA', 'XED');
    expect($r['groups'])->toHaveCount(1)
        ->and($r['groups'][0]['remaining_amount_cents'])->toBe('12000000000000000000')
        ->and($r['groups'][0]['original_amount_cents'])->toBe('12000000000000000000');
});

test('bonus exposure validates filters before SQL and the CLI emits read only JSON without identities', function () {
    $service = app(BonusExposureQueryService::class);
    DB::connection('sqlsrv')->enableQueryLog(); DB::connection('sqlsrv')->flushQueryLog();
    foreach ([['beca', null], [null, 'mxn'], [null, 'MXNN'], ["BECA'", null]] as [$type, $currency]) {
        expect(fn () => $service->report($type, $currency))->toThrow(InvalidArgumentException::class);
    }
    expect(DB::connection('sqlsrv')->getQueryLog())->toBe([]);
    DB::connection('sqlsrv')->disableQueryLog();
    exposureFixtureBonus($this, ['currency' => 'XEE']); $before = exposureDataSnapshot();
    expect(Artisan::call('financial:bonus-exposure', ['--type' => 'BECA', '--currency' => 'XEE']))->toBe(0);
    $json = Artisan::output(); $r = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    expect($r['groups'])->toHaveCount(1)->and($r['funding_certified'])->toBeFalse()
        ->and(str_contains($json, 'fixture-exposure-recipient'))->toBeFalse()
        ->and(str_contains($json, 'issuer_id'))->toBeFalse()->and(exposureDataSnapshot())->toBe($before);
    Http::assertNothingSent();
});

test('bonus exposure returns an empty result without creating financial resources', function () {
    $before = exposureDataSnapshot();
    $r = app(BonusExposureQueryService::class)->report('BECA', 'XEF');
    expect($r['groups'])->toBe([])->and(exposureDataSnapshot())->toBe($before);
});
