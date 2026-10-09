<?php

use App\Domains\Financial\Enums\AlertStatus;
use App\Domains\Financial\Enums\TopUpMethod;
use App\Domains\Financial\Exceptions\FinancialLimitExceededException;
use App\Domains\Financial\Models\TransactionAlert;
use App\Domains\Financial\Services\TopUpService;
use App\Domains\Financial\Services\TransactionAlertService;

require_once __DIR__ . '/Support/FinancialControlHelpers.php';

afterEach(function () {
    fcCleanup();
});

function fcBlockedAlert(string $suffix): TransactionAlert
{
    $wallet = fcWallet($suffix);
    fcLimit($wallet, ['max_amount_cents' => 100]);

    try {
        app(TopUpService::class)->create($wallet, 500, TopUpMethod::EFECTIVO);
    } catch (FinancialLimitExceededException $exception) {
        return TransactionAlert::where('public_id', $exception->alertId)->firstOrFail();
    }

    throw new RuntimeException('Se esperaba una operación bloqueada.');
}

test('moves an alert through review and resolution keeping its history', function () {
    $alert = fcBlockedAlert('alert-flow');
    $service = app(TransactionAlertService::class);

    $inReview = $service->changeStatus($alert, AlertStatus::EN_REVISION, 'test-fc-reviewer');
    $resolved = $service->changeStatus($inReview, AlertStatus::RESUELTA, 'test-fc-reviewer', 'Operación validada con el usuario.');

    expect($inReview->status)->toBe(AlertStatus::EN_REVISION)
        ->and($resolved->status)->toBe(AlertStatus::RESUELTA)
        ->and($resolved->status_changed_by)->toBe('test-fc-reviewer')
        ->and($resolved->resolution_note)->toBe('Operación validada con el usuario.');

    $history = $resolved->statusChanges()->orderBy('id')->get();

    expect($history)->toHaveCount(3)
        ->and($history[0]->from_status)->toBeNull()
        ->and($history[0]->to_status)->toBe(AlertStatus::ABIERTA)
        ->and($history[1]->to_status)->toBe(AlertStatus::EN_REVISION)
        ->and($history[2]->from_status)->toBe(AlertStatus::EN_REVISION)
        ->and($history[2]->to_status)->toBe(AlertStatus::RESUELTA);
});

test('requires a note to close an alert', function () {
    $alert = fcBlockedAlert('alert-note');

    expect(fn () => app(TransactionAlertService::class)
        ->changeStatus($alert, AlertStatus::DESCARTADA, 'test-fc-reviewer'))
        ->toThrow(InvalidArgumentException::class, 'nota de resolución');

    expect($alert->fresh()->status)->toBe(AlertStatus::ABIERTA);
});

test('rejects transitions from a final status', function () {
    $alert = fcBlockedAlert('alert-final');
    $service = app(TransactionAlertService::class);

    $discarded = $service->changeStatus($alert, AlertStatus::DESCARTADA, 'test-fc-reviewer', 'Falso positivo.');

    expect(fn () => $service->changeStatus($discarded, AlertStatus::EN_REVISION, 'test-fc-reviewer'))
        ->toThrow(InvalidArgumentException::class, 'No se puede cambiar la alerta');

    expect($discarded->fresh()->statusChanges()->count())->toBe(2);
});

test('requires an actor to change an alert', function () {
    $alert = fcBlockedAlert('alert-actor');

    expect(fn () => app(TransactionAlertService::class)
        ->changeStatus($alert, AlertStatus::EN_REVISION, '  '))
        ->toThrow(InvalidArgumentException::class, 'actor');
});

test('filters alerts by status and wallet', function () {
    $alert = fcBlockedAlert('alert-filter');
    $service = app(TransactionAlertService::class);

    $open = $service->list(['status' => 'ABIERTA', 'wallet_id' => $alert->wallet_id]);
    $resolved = $service->list(['status' => 'RESUELTA', 'wallet_id' => $alert->wallet_id]);

    expect($open->total())->toBe(1)
        ->and($resolved->total())->toBe(0);
});
