<?php

use App\Domains\Financial\Adapters\PendingFinancialRoleProvider;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\ReconciliationCheck;
use App\Domains\Financial\Enums\TransactionStatus;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Services\FinancialLimitService;
use App\Domains\Financial\Services\ReconciliationService;
use App\Domains\Financial\Support\FinancialJobLock;
use Illuminate\Support\Str;

require_once __DIR__ . '/Support/FinancialControlHelpers.php';

afterEach(function () {
    fcCleanup();
});

test('does not replace the missing global role contract with historical identity data', function () {
    expect(fn () => (new PendingFinancialRoleProvider())->rolesOf('USER', 'external-user'))
        ->toThrow(FinancialDependencyUnavailableException::class);
});

test('validates limit edits against the locked current validity window', function () {
    $wallet = fcWallet('compat-stale-limit');
    $service = app(FinancialLimitService::class);
    $stale = fcLimit($wallet, [
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDay(),
    ]);

    $service->update($stale, ['valid_until' => now()->addDays(4)], FC_ACTOR, 'Extend validity');
    $current = $service->update($stale, ['valid_from' => now()->addDays(3)], FC_ACTOR, 'Set start');

    expect($current->valid_until->greaterThan($current->valid_from))->toBeTrue()
        ->and($current->changes()->count())->toBe(3);
});

test('recognizes administrative refund requests without money movements', function () {
    $wallet = fcWallet('compat-refund-workflow');
    foreach (['REFUND_REQUEST', 'PURCHASE_REFUND_REQUEST'] as $referenceType) {
        foreach ([TransactionStatus::PENDIENTE, TransactionStatus::COMPLETADA] as $status) {
            FinancialTransaction::create([
                'public_id' => (string) Str::uuid(),
                'idempotency_key' => fcKey('compat-request'),
                'reference_type' => $referenceType,
                'reference_id' => $wallet->public_id,
                'status' => $status,
            ]);
        }
    }
    // Global scope includes the administrative requests just created.
    $run = app(ReconciliationService::class)->run(
        now(config('financial.business_timezone'))->format('Y-m-d'), FC_ACTOR
    );

    $ids = FinancialTransaction::where('idempotency_key', 'like', FC_PREFIX . 'compat-request%')
        ->pluck('public_id');
    expect($run->observedDifferences()->whereIn('entity_id', $ids)
        ->whereIn('check_code', [ReconciliationCheck::TRANSACCION_PENDIENTE->value,
            ReconciliationCheck::TRANSACCION_SIN_MOVIMIENTOS->value])->count())->toBe(0);
});

test('serializes the same idempotency key even across business dates', function () {
    $wallet = fcWallet('compat-key-lock');
    $key = fcKey('compat-reconciliation');
    $lockKey = 'reconciliation-idempotency:' . hash('sha256', $key);
    $locks = app(FinancialJobLock::class);
    $token = $locks->acquire($lockKey, 600);
    expect($token)->not->toBeNull();

    try {
        expect(fn () => app(ReconciliationService::class)->run(
            now(config('financial.business_timezone'))->subDay()->format('Y-m-d'),
            FC_ACTOR, [$wallet->public_id], idempotencyKey: $key
        ))->toThrow(\App\Domains\Financial\Exceptions\ReconciliationInProgressException::class);
    } finally {
        $locks->release($lockKey, $token);
    }
});
