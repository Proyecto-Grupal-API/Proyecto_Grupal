<?php

use App\Domains\Financial\Contracts\FinancialRoleProvider;
use App\Domains\Financial\Enums\LimitAction;
use App\Domains\Financial\Enums\LimitMetric;
use App\Domains\Financial\Enums\LimitPeriod;
use App\Domains\Financial\Enums\LimitSubjectType;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Enums\WalletType;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use App\Domains\Financial\Services\FinancialLimitService;
use App\Domains\Financial\Services\LedgerService;

require_once __DIR__ . '/Support/FinancialControlHelpers.php';

afterEach(function () {
    fcCleanup();
});

test('creates a configurable limit and records the actor', function () {
    $wallet = fcWallet('limit-create');

    $limit = fcLimit($wallet, [
        'name' => 'Recarga por operación',
        'max_amount_cents' => 25000,
        'reason' => 'Prueba de configuración',
    ]);

    expect($limit->public_id)->not->toBeEmpty()
        ->and($limit->subject_type)->toBe(LimitSubjectType::OWNER)
        ->and($limit->subject_id)->toBe($wallet->owner_id)
        ->and($limit->operation)->toBe(MovementType::RECARGA)
        ->and($limit->period)->toBe(LimitPeriod::OPERACION)
        ->and($limit->metric)->toBe(LimitMetric::MONTO)
        ->and($limit->max_amount_cents)->toBe(25000)
        ->and($limit->action)->toBe(LimitAction::BLOQUEAR)
        ->and($limit->currency)->toBe('MXN')
        ->and($limit->active)->toBeTrue()
        ->and($limit->created_by)->toBe(FC_ACTOR);
});

test('rejects structurally invalid limits', function (array $overrides, string $message) {
    $wallet = fcWallet('limit-invalid');

    expect(fn () => fcLimit($wallet, $overrides))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'global with subject' => [
        ['subject_type' => 'GLOBAL', 'subject_id' => 'x'],
        'Un límite GLOBAL no debe indicar subject_id.',
    ],
    'owner without subject' => [
        ['subject_id' => null],
        'El límite requiere subject_id',
    ],
    'count per operation' => [
        ['metric' => 'CONTEO', 'max_amount_cents' => null, 'max_count' => 3],
        'Un límite por CONTEO requiere un periodo DIARIO o MENSUAL.',
    ],
    'amount without max' => [
        ['max_amount_cents' => null],
        'Un límite por MONTO requiere max_amount_cents entero.',
    ],
    'negative amount' => [
        ['max_amount_cents' => -1],
        'max_amount_cents no puede ser negativo.',
    ],
    'invalid wallet type' => [
        ['subject_type' => 'WALLET_TYPE', 'subject_id' => 'NO_EXISTE'],
        'subject_id debe ser un tipo de wallet válido.',
    ],
    'invalid validity range' => [
        ['valid_from' => '2026-10-10 00:00:00', 'valid_until' => '2026-10-09 00:00:00'],
        'valid_until debe ser posterior a valid_from.',
    ],
    'invalid operation' => [
        ['operation' => 'NO_EXISTE'],
        'El valor de operation no es válido.',
    ],
]);

test('updates values of a limit but not what it measures', function () {
    $wallet = fcWallet('limit-update');
    $limit = fcLimit($wallet, ['max_amount_cents' => 10000]);

    $service = app(FinancialLimitService::class);

    $updated = $service->update($limit, [
        'max_amount_cents' => 20000,
        'action' => 'ALERTAR',
    ], 'test-fc-editor');

    expect($updated->max_amount_cents)->toBe(20000)
        ->and($updated->action)->toBe(LimitAction::ALERTAR)
        ->and($updated->updated_by)->toBe('test-fc-editor')
        ->and($updated->created_by)->toBe(FC_ACTOR);

    expect(fn () => $service->update($updated, ['operation' => 'RETIRO'], 'test-fc-editor'))
        ->toThrow(InvalidArgumentException::class, 'El campo operation no se puede modificar');
});

test('accepts an operation when no limit applies', function () {
    $wallet = fcWallet('eval-none');

    $evaluation = app(FinancialLimitService::class)->evaluate(
        $wallet,
        MovementType::RECARGA,
        999999
    );

    expect($evaluation->decision())->toBe('ACEPTADA')
        ->and($evaluation->limitsEvaluated)->toBe(0)
        ->and($evaluation->violations)->toBe([]);
});

test('detects an operation above a per operation amount limit', function () {
    $wallet = fcWallet('eval-operation');
    fcLimit($wallet, ['max_amount_cents' => 10000]);

    $service = app(FinancialLimitService::class);

    $atLimit = $service->evaluate($wallet, MovementType::RECARGA, 10000);
    $above = $service->evaluate($wallet, MovementType::RECARGA, 10001);

    expect($atLimit->decision())->toBe('ACEPTADA')
        ->and($atLimit->limitsEvaluated)->toBe(1)
        ->and($above->decision())->toBe('BLOQUEADA')
        ->and($above->violations[0]->observedValue)->toBe(10001)
        ->and($above->violations[0]->thresholdValue)->toBe(10000);
});

test('accumulates daily amount from the ledger', function () {
    $wallet = fcWallet('eval-daily', 3000);
    fcLimit($wallet, ['period' => 'DIARIO', 'max_amount_cents' => 5000]);

    $service = app(FinancialLimitService::class);

    $fits = $service->evaluate($wallet, MovementType::RECARGA, 2000);
    $exceeds = $service->evaluate($wallet, MovementType::RECARGA, 2500);

    expect($fits->decision())->toBe('ACEPTADA')
        ->and($exceeds->decision())->toBe('BLOQUEADA')
        ->and($exceeds->violations[0]->observedValue)->toBe(5500);
});

test('counts daily operations from the ledger', function () {
    $wallet = fcWallet('eval-count', 1000);

    app(LedgerService::class)->credit(
        $wallet,
        1000,
        MovementType::RECARGA,
        fcKey('count-second'),
        'TEST',
        null
    );

    fcLimit($wallet, [
        'period' => 'DIARIO',
        'metric' => 'CONTEO',
        'max_amount_cents' => null,
        'max_count' => 2,
    ]);

    $evaluation = app(FinancialLimitService::class)
        ->evaluate($wallet, MovementType::RECARGA, 1);

    expect($evaluation->decision())->toBe('BLOQUEADA')
        ->and($evaluation->violations[0]->observedValue)->toBe(3)
        ->and($evaluation->violations[0]->thresholdValue)->toBe(2);
});

test('measures debits by magnitude', function () {
    $wallet = fcWallet('eval-debit', 10000);

    app(LedgerService::class)->debit(
        $wallet,
        4000,
        MovementType::RETIRO,
        fcKey('debit'),
        'TEST',
        null
    );

    fcLimit($wallet, [
        'operation' => 'RETIRO',
        'period' => 'DIARIO',
        'max_amount_cents' => 5000,
    ]);

    $evaluation = app(FinancialLimitService::class)
        ->evaluate($wallet, MovementType::RETIRO, 1500);

    expect($evaluation->violations[0]->observedValue)->toBe(5500);
});

test('ignores inactive, expired, future and unrelated limits', function () {
    $wallet = fcWallet('eval-ignored');
    $other = fcWallet('eval-ignored-other');

    fcLimit($wallet, ['max_amount_cents' => 1, 'active' => false]);
    fcLimit($wallet, ['max_amount_cents' => 1, 'valid_from' => now()->subDays(3), 'valid_until' => now()->subDay()]);
    fcLimit($wallet, ['max_amount_cents' => 1, 'valid_from' => now()->addDay()]);
    fcLimit($other, ['max_amount_cents' => 1]);
    fcLimit($wallet, ['max_amount_cents' => 1, 'operation' => 'RETIRO']);

    $evaluation = app(FinancialLimitService::class)
        ->evaluate($wallet, MovementType::RECARGA, 5000);

    expect($evaluation->limitsEvaluated)->toBe(0)
        ->and($evaluation->decision())->toBe('ACEPTADA');
});

test('reports an alert decision for alert only limits', function () {
    $wallet = fcWallet('eval-alert');
    fcLimit($wallet, ['max_amount_cents' => 100, 'action' => 'ALERTAR']);

    $evaluation = app(FinancialLimitService::class)
        ->evaluate($wallet, MovementType::RECARGA, 500);

    expect($evaluation->decision())->toBe('ALERTADA')
        ->and($evaluation->isBlocked())->toBeFalse();
});

test('applies wallet type limits only to that wallet type', function () {
    $user = fcWallet('eval-type-user');
    $business = fcWallet('eval-type-business', 0, WalletType::NEGOCIO);

    fcLimit($business, [
        'subject_type' => 'WALLET_TYPE',
        'subject_id' => WalletType::NEGOCIO->value,
        'max_amount_cents' => 100,
    ]);

    $service = app(FinancialLimitService::class);

    expect($service->evaluate($business, MovementType::RECARGA, 500)->decision())
        ->toBe('BLOQUEADA')
        ->and($service->evaluate($user, MovementType::RECARGA, 500)->limitsEvaluated)
        ->toBe(0);
});

test('applies role limits through the role contract', function () {
    $wallet = fcWallet('eval-role');

    $provider = new class implements FinancialRoleProvider {
        public int $calls = 0;

        public function rolesOf(string $ownerType, string $ownerId): array
        {
            $this->calls++;

            return ['test-fc-role'];
        }
    };

    $this->app->instance(FinancialRoleProvider::class, $provider);

    $service = app(FinancialLimitService::class);

    $withoutRoleLimits = $service->evaluate($wallet, MovementType::RECARGA, 500);

    expect($withoutRoleLimits->limitsEvaluated)->toBe(0)
        ->and($provider->calls)->toBe(0);

    fcLimit($wallet, [
        'subject_type' => 'ROLE',
        'subject_id' => 'test-fc-role',
        'max_amount_cents' => 100,
    ]);

    $evaluation = $service->evaluate($wallet, MovementType::RECARGA, 500);

    expect($evaluation->decision())->toBe('BLOQUEADA')
        ->and($provider->calls)->toBe(1);
});

test('fails in a controlled way when the role provider is unavailable', function () {
    $wallet = fcWallet('eval-role-down');

    $this->app->instance(FinancialRoleProvider::class, new class implements FinancialRoleProvider {
        public function rolesOf(string $ownerType, string $ownerId): array
        {
            throw new FinancialDependencyUnavailableException('Identidad no disponible.');
        }
    });

    fcLimit($wallet, [
        'subject_type' => 'ROLE',
        'subject_id' => 'test-fc-role',
        'max_amount_cents' => 100,
    ]);

    expect(fn () => app(FinancialLimitService::class)->evaluate($wallet, MovementType::RECARGA, 500))
        ->toThrow(FinancialDependencyUnavailableException::class);
});
