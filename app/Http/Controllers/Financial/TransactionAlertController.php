<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Enums\AlertOutcome;
use App\Domains\Financial\Enums\AlertStatus;
use App\Domains\Financial\Enums\AlertType;
use App\Domains\Financial\Models\TransactionAlert;
use App\Domains\Financial\Services\TransactionAlertService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\Concerns\FinancialControlResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class TransactionAlertController extends Controller
{
    use FinancialControlResponses;

    public function index(Request $request, TransactionAlertService $service): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(AlertStatus::class)],
            'alert_type' => ['nullable', Rule::enum(AlertType::class)],
            'wallet_id' => ['nullable', 'string'],
            'transaction_id' => ['nullable', 'string'],
            'limit_id' => ['nullable', 'string'],
            'outcome' => ['nullable', Rule::enum(AlertOutcome::class)],
            'correlation_id' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $page = $service->list($validated, (int) ($validated['per_page'] ?? 50));

        return $this->paginated($request, $page, fn ($alert) => $this->alertData($alert));
    }

    public function show(Request $request, string $alertId): JsonResponse
    {
        $alert = TransactionAlert::where('public_id', $alertId)->firstOrFail();

        return $this->ok($request, $this->alertData($alert, withHistory: true));
    }

    public function updateStatus(Request $request, string $alertId, TransactionAlertService $service): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(AlertStatus::class)],
            'actor_id' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $alert = TransactionAlert::where('public_id', $alertId)->firstOrFail();

        try {
            $alert = $service->changeStatus(
                $alert,
                AlertStatus::from($validated['status']),
                $this->authenticatedActor($request),
                $validated['note'] ?? null
            );
        } catch (InvalidArgumentException $exception) {
            return $this->conflict($request, $exception->getMessage());
        }

        return $this->ok($request, $this->alertData($alert, withHistory: true));
    }
}
