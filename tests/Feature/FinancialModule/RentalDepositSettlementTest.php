<?php

use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\WalletHoldStatus;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Models\LedgerEntry;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\LedgerService;
use App\Domains\Financial\Services\ReconciliationService;
use App\Domains\Financial\Services\WalletHoldPolicyService;
use App\Domains\Financial\Services\WalletHoldService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    expect(config('database.connections.sqlsrv.database'))->toBe('campus_digital_financial_testing');
    DB::connection('sqlsrv')->beginTransaction();
});

afterEach(function () {
    while (DB::connection('sqlsrv')->transactionLevel() > 0) {
        DB::connection('sqlsrv')->rollBack();
    }
});

function rentalDepositWallet(string $type, string $ownerType): Wallet
{
    return Wallet::create([
        'public_id' => (string) Str::uuid(), 'owner_id' => (string) Str::uuid(),
        'owner_type' => $ownerType, 'type' => $type, 'currency' => 'MXN', 'status' => 'ACTIVA',
        'available_balance_cents' => 0, 'held_balance_cents' => 0,
    ]);
}

test('rental deposit pays only the assessed damage and restores the remainder atomically', function () {
    $student = rentalDepositWallet('WALLET_USUARIO', 'USER');
    $recipient = rentalDepositWallet('WALLET_ORGANIZACION', 'ORGANIZATION');
    $anotherRecipient = rentalDepositWallet('WALLET_ORGANIZACION', 'ORGANIZATION');
    $ledger = app(LedgerService::class);
    $ledger->credit($student, 10000, MovementType::RECARGA, (string) Str::uuid());
    app(WalletHoldPolicyService::class)->create('RENTAL_DEPOSIT', 3600, true, 'test-authority', 'Prueba.');
    $holds = app(WalletHoldService::class);
    $hold = $holds->create($student, 4000, 'RENTAL_DEPOSIT', now()->addMinutes(15),
        (string) Str::uuid(), 'test-actor', 'Garantía.', 'STUDENT_RENTAL', 'test-rental');

    $key = (string) Str::uuid();
    expect(fn () => $holds->settleRentalDeposit($hold, $recipient, 4001, $key, 'test-actor', 'Daño.'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $holds->settleRentalDeposit($hold, $student, 1000, $key, 'test-actor', 'Daño.'))
        ->toThrow(InvalidArgumentException::class);
    expect($hold->fresh()->status)->toBe(WalletHoldStatus::ACTIVA)
        ->and($student->fresh()->held_balance_cents)->toBe(4000)
        ->and($recipient->fresh()->available_balance_cents)->toBe(0);

    $closed = $holds->settleRentalDeposit($hold, $recipient, 1500, $key, 'test-actor', 'Daño.');
    $replayed = $holds->settleRentalDeposit($hold, $recipient, 1500, $key, 'test-actor', 'Daño.');
    expect($closed->status)->toBe(WalletHoldStatus::CAPTURADA)
        ->and(strtolower($replayed->closing_transaction_id))->toBe(strtolower($closed->closing_transaction_id))
        ->and($student->fresh()->available_balance_cents)->toBe(8500)
        ->and($student->fresh()->held_balance_cents)->toBe(0)
        ->and($recipient->fresh()->available_balance_cents)->toBe(1500);

    expect(fn () => $holds->settleRentalDeposit($hold, $recipient, 1600, $key, 'test-actor', 'Daño.'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $holds->settleRentalDeposit($hold, $anotherRecipient, 1500, $key, 'test-actor', 'Daño.'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $holds->release($hold, (string) Str::uuid(), 'test-actor', 'Duplicada.'))
        ->toThrow(InvalidArgumentException::class);

    $transaction = FinancialTransaction::where('idempotency_key', $key)->sole();
    $movements = LedgerEntry::where('transaction_id', $transaction->public_id)->get();
    expect($transaction->reference_type)->toBe('WALLET_HOLD_SETTLE')
        ->and($transaction->metadata['released_cents'])->toBe(2500)
        ->and((int) $movements->sum('amount_cents'))->toBe(0)
        ->and($movements->count())->toBe(2);
    $result = app(ReconciliationService::class)->run(
        now(config('financial.business_timezone'))->toDateString(),
        'test-authority',
        [$student->public_id, $recipient->public_id]
    );
    expect($result->observedDifferences()->count())->toBe(0);
});

test('expired rental deposits cannot be captured but can be released normally', function () {
    $student = rentalDepositWallet('WALLET_USUARIO', 'USER');
    $recipient = rentalDepositWallet('WALLET_ORGANIZACION', 'ORGANIZATION');
    app(LedgerService::class)->credit($student, 10000, MovementType::RECARGA, (string) Str::uuid());
    app(WalletHoldPolicyService::class)->create('RENTAL_DEPOSIT', 3600, true, 'test-authority', 'Prueba.');
    $holds = app(WalletHoldService::class);
    $hold = $holds->create($student, 4000, 'RENTAL_DEPOSIT', now()->addMinute(),
        (string) Str::uuid(), 'test-actor', 'Garantía.', 'STUDENT_RENTAL', 'test-rental');

    $this->travel(2)->minutes();
    expect(fn () => $holds->settleRentalDeposit($hold, $recipient, 1000, (string) Str::uuid(), 'test-actor', 'Daño.'))
        ->toThrow(InvalidArgumentException::class);
    expect($holds->expireDue())->toBe(1)
        ->and($holds->expireDue())->toBe(0)
        ->and($hold->fresh()->status)->toBe(WalletHoldStatus::EXPIRADA)
        ->and($student->fresh()->available_balance_cents)->toBe(10000)
        ->and($recipient->fresh()->available_balance_cents)->toBe(0);
});
