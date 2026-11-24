<?php

use App\Domains\Financial\Exceptions\TransferRecipientUnavailableException;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\CashHolderLookup;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// FIN-05 (auditoría E2): la caja encuentra la wallet del titular por su matrícula en lugar de pegar un UUID.
beforeEach(function () {
    config(['database.connections.sqlsrv.database' => 'campus_digital_financial_testing']);
    DB::purge('sqlsrv');
    if (! app()->environment('testing') || config('database.connections.sqlsrv.database') !== 'campus_digital_financial_testing') {
        throw new RuntimeException('Solo bases de pruebas.');
    }
    DB::connection('sqlsrv')->beginTransaction();
    Http::preventStrayRequests();
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
});
afterEach(function () {
    while (DB::connection('sqlsrv')->transactionLevel() > 0) {
        DB::connection('sqlsrv')->rollBack();
    }
});

function chlHolder(string $status = 'ACTIVA', string $currency = 'MXN', bool $wallet = true): array
{
    $user = User::factory()->create(['name' => 'Titular sintético']);
    $enrollment = 'CAJ-'.Str::upper(Str::random(6));
    StudentProfile::create(['user_id' => (string) $user->getKey(), 'enrollment_number' => $enrollment]);
    $w = $wallet ? Wallet::create(['public_id' => (string) Str::uuid(), 'owner_type' => 'USER', 'owner_id' => strtolower((string) $user->getKey()),
        'type' => 'WALLET_USUARIO', 'currency' => $currency, 'status' => $status, 'available_balance_cents' => 0, 'held_balance_cents' => 0]) : null;

    return [$user, $enrollment, $w];
}

test('FIN-05: the cashier finds the holder wallet by enrollment or user id and sees what to verify', function () {
    config(['identity_directory.enrollment_integrity_verified' => true]);
    [$user, $enrollment, $wallet] = chlHolder();
    $lookup = app(CashHolderLookup::class);

    $found = $lookup->find(strtolower($enrollment), 'user:cajero', null, 'MXN');
    expect($found)->toMatchArray(['holder_name' => 'Titular sintético', 'enrollment_number' => $enrollment,
        'wallet_id' => strtolower($wallet->public_id), 'currency' => 'MXN', 'status' => 'ACTIVA', 'operable' => true, 'notice' => null])
        ->and($lookup->find((string) $user->getKey(), 'user:cajero', null, 'MXN')['wallet_id'])->toBe(strtolower($wallet->public_id));
});

test('FIN-05: inactive wallets, other currencies, missing wallets and unknown enrollments are explained, never guessed', function () {
    config(['identity_directory.enrollment_integrity_verified' => true]);
    $lookup = app(CashHolderLookup::class);
    [, $blocked] = chlHolder('BLOQUEADA');
    expect($lookup->find($blocked, 'user:cajero', null, 'MXN'))->toMatchArray(['operable' => false, 'status' => 'BLOQUEADA']);
    [, $usd] = chlHolder('ACTIVA', 'USD');
    expect($lookup->find($usd, 'user:cajero', null, 'MXN')['notice'])->toContain('otra moneda');
    [, $none] = chlHolder(wallet: false);
    expect(fn () => $lookup->find($none, 'user:cajero', null, 'MXN'))->toThrow(InvalidArgumentException::class, 'no tiene una wallet');
    expect(fn () => $lookup->find('NOEXISTE-9', 'user:cajero', null, 'MXN'))->toThrow(InvalidArgumentException::class);
});

test('FIN-05: without the E1 integrity attestation the lookup by enrollment says so', function () {
    config(['identity_directory.enrollment_integrity_verified' => false]);
    [, $enrollment] = chlHolder();
    expect(fn () => app(CashHolderLookup::class)->find($enrollment, 'user:cajero', null, 'MXN'))
        ->toThrow(TransferRecipientUnavailableException::class, 'acreditación de integridad');
});
