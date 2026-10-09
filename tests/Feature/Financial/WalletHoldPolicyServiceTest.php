<?php

use App\Domains\Financial\Models\WalletHoldPolicy;
use App\Domains\Financial\Models\WalletHoldPolicyChange;
use App\Domains\Financial\Models\WalletHold;
use App\Domains\Financial\Models\FinancialTransaction;
use App\Domains\Financial\Services\WalletHoldPolicyService;
use App\Domains\Financial\Services\WalletHoldService;
use App\Domains\Financial\Enums\WalletHoldStatus;
use Carbon\CarbonImmutable;

require_once __DIR__ . '/Support/FinancialControlHelpers.php';
afterEach(fn () => fcCleanup());

function policyHold($wallet, string $key, CarbonImmutable $expiry, string $operation = 'TEST_CHECKOUT'): WalletHold
{
    return app(WalletHoldService::class)->create($wallet, 1000, $operation, $expiry, $key,
        FC_ACTOR, 'Reserva de prueba', 'TEST_ORDER', 'test-policy-order');
}

test('creates a unique policy and audits every effective version', function () {
    $policy = fcHoldPolicy();
    $service = app(WalletHoldPolicyService::class);
    $first = $policy->changes()->sole();
    expect($first->change_type)->toBe('CREACION')->and($first->before)->toBeNull()
        ->and($first->after['max_duration_seconds'])->toBe(900)->and($first->actor_id)->toBe(FC_ACTOR);
    $updated = $service->update($policy, 1, ['max_duration_seconds' => 600, 'active' => false], FC_ACTOR, 'Ajuste autorizado');
    $second = $updated->changes()->where('version', 2)->sole();
    expect($updated->version)->toBe(2)->and($updated->active)->toBeFalse()
        ->and($second->before['max_duration_seconds'])->toBe(900)->and($second->after['max_duration_seconds'])->toBe(600)
        ->and($second->reason)->toBe('Ajuste autorizado')->and($second->correlation_id)->not->toBeEmpty();
    expect(fn () => fcHoldPolicy())->toThrow(InvalidArgumentException::class);
    expect(WalletHoldPolicy::where('operation_type', 'TEST_CHECKOUT')->count())->toBe(1);
});

test('rejects stale policy edits and avoids history for unchanged values', function () {
    $policy = fcHoldPolicy();
    $service = app(WalletHoldPolicyService::class);
    $same = $service->update($policy, 1, ['max_duration_seconds' => 900], FC_ACTOR, 'Sin cambio');
    expect($same->version)->toBe(1)->and($same->changes()->count())->toBe(1);
    $service->update($policy, 1, ['max_duration_seconds' => 600], FC_ACTOR, 'Primer cambio');
    expect(fn () => $service->update($policy, 1, ['active' => false], FC_ACTOR, 'Edición obsoleta'))
        ->toThrow(InvalidArgumentException::class);
    expect($policy->fresh()->active)->toBeTrue()->and($policy->fresh()->max_duration_seconds)->toBe(600)
        ->and($policy->changes()->count())->toBe(2);
});

test('validates policy duration actor reason and deployment ceiling', function () {
    $service = app(WalletHoldPolicyService::class);
    config(['financial.holds.absolute_max_seconds' => 1200]);
    foreach ([0, -1, 1201] as $seconds) {
        expect(fn () => $service->create('TEST_INVALID_DURATION', $seconds, true, FC_ACTOR, 'Motivo'))
            ->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => $service->create('invalid type', 600, true, FC_ACTOR, 'Motivo'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->create('TEST_INVALID_AUDIT', 600, true, '', 'Motivo'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $service->create('TEST_INVALID_AUDIT', 600, true, FC_ACTOR, ' '))->toThrow(InvalidArgumentException::class);
    config(['financial.holds.absolute_max_seconds' => 0]);
    expect(fn () => fcHoldPolicy())->toThrow(InvalidArgumentException::class);
    expect(WalletHoldPolicy::where('created_by', FC_ACTOR)->count())->toBe(0);
});

test('applies changed policies only to future holds and preserves idempotent retries', function () {
    $policy = fcHoldPolicy();
    $wallet = fcWallet('policy-history', 10000);
    $expiry = CarbonImmutable::now()->addMinutes(10)->startOfSecond();
    $key = fcKey('policy-first');
    $hold = policyHold($wallet, $key, $expiry);
    expect($hold->policy_version)->toBe(1)->and($hold->max_duration_seconds)->toBe(900)
        ->and(strtolower($hold->policy_id))->toBe(strtolower($policy->public_id));
    $policy = app(WalletHoldPolicyService::class)->update($policy, 1, ['max_duration_seconds' => 60], FC_ACTOR, 'Reducir plazo');
    $retry = policyHold($wallet, $key, $expiry);
    expect(strtolower($retry->public_id))->toBe(strtolower($hold->public_id))
        ->and($hold->fresh()->expires_at->equalTo($expiry))->toBeTrue()
        ->and($hold->fresh()->max_duration_seconds)->toBe(900);
    expect(fn () => policyHold($wallet, fcKey('policy-too-long'), $expiry))->toThrow(InvalidArgumentException::class);
    $new = policyHold($wallet, fcKey('policy-new'), CarbonImmutable::now()->addSeconds(30)->startOfSecond());
    expect($new->policy_version)->toBe(2)->and($new->max_duration_seconds)->toBe(60)
        ->and($wallet->fresh()->held_balance_cents)->toBe(2000);
});

test('disabling a policy blocks new holds but permits retries release capture and expiry', function () {
    $policy = fcHoldPolicy();
    $wallet = fcWallet('policy-disabled', 10000);
    $expiry = CarbonImmutable::now()->addSeconds(30)->startOfSecond();
    $key = fcKey('policy-disable-first');
    $first = policyHold($wallet, $key, $expiry);
    $second = policyHold($wallet, fcKey('policy-disable-second'), $expiry);
    $third = policyHold($wallet, fcKey('policy-disable-third'), $expiry);
    app(WalletHoldPolicyService::class)->update($policy, 1, ['active' => false], FC_ACTOR, 'Deshabilitar nuevas reservas');
    expect(fn () => policyHold($wallet, fcKey('policy-disabled-new'), $expiry))->toThrow(InvalidArgumentException::class);
    expect(strtolower(policyHold($wallet, $key, $expiry)->public_id))->toBe(strtolower($first->public_id));
    app(WalletHoldService::class)->release($first, fcKey('policy-release'), FC_ACTOR, 'Cancelar');
    app(WalletHoldService::class)->capture($third, fcKey('policy-capture'), FC_ACTOR, 'Completar compra');
    try {
        $this->travel(31)->seconds();
        expect(app(WalletHoldService::class)->expireDue())->toBe(1)
            ->and($second->fresh()->status)->toBe(WalletHoldStatus::EXPIRADA)
            ->and(app(WalletHoldService::class)->expireDue())->toBe(0);
    } finally {
        $this->travelBack();
    }
    expect($wallet->fresh()->available_balance_cents)->toBe(9000)->and($wallet->fresh()->held_balance_cents)->toBe(0);
});

test('rejects missing database policies even if obsolete environment policies exist', function () {
    config(['financial.holds.max_seconds' => ['TEST_UNREGISTERED' => 900]]);
    $wallet = fcWallet('policy-no-fallback', 10000);
    $key = fcKey('policy-no-fallback');
    expect(fn () => policyHold($wallet, $key, CarbonImmutable::now()->addMinute(), 'TEST_UNREGISTERED'))
        ->toThrow(InvalidArgumentException::class);
    expect($wallet->fresh()->available_balance_cents)->toBe(10000)
        ->and(FinancialTransaction::where('idempotency_key', $key)->exists())->toBeFalse();
});

test('rolls back policy edits if recording the audit history fails', function () {
    $policy = fcHoldPolicy();
    $dispatcher = WalletHoldPolicyChange::getEventDispatcher();
    WalletHoldPolicyChange::setEventDispatcher(clone $dispatcher);
    WalletHoldPolicyChange::creating(fn () => throw new RuntimeException('Injected policy audit failure'));
    try {
        expect(fn () => fcHoldPolicy('TEST_AUDIT_CREATE_FAIL'))->toThrow(RuntimeException::class);
        expect(WalletHoldPolicy::where('operation_type', 'TEST_AUDIT_CREATE_FAIL')->exists())->toBeFalse();
        expect(fn () => app(WalletHoldPolicyService::class)->update($policy, 1, ['max_duration_seconds' => 600], FC_ACTOR, 'Cambiar'))
            ->toThrow(RuntimeException::class);
    } finally {
        WalletHoldPolicyChange::setEventDispatcher($dispatcher);
    }
    expect($policy->fresh()->version)->toBe(1)->and($policy->fresh()->max_duration_seconds)->toBe(900)
        ->and($policy->changes()->count())->toBe(1);
    $updated = app(WalletHoldPolicyService::class)->update($policy, 1, ['max_duration_seconds' => 600], FC_ACTOR, 'Cambiar');
    expect($updated->version)->toBe(2)->and($updated->changes()->count())->toBe(2);
});
