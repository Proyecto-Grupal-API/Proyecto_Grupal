<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Enums\LimitAction;
use App\Domains\Financial\Enums\LimitMetric;
use App\Domains\Financial\Enums\LimitPeriod;
use App\Domains\Financial\Enums\LimitSubjectType;
use App\Domains\Financial\Enums\MovementType;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use App\Domains\Financial\Models\FinancialLimit;
use App\Domains\Financial\Models\Wallet;
use App\Domains\Financial\Services\FinancialLimitService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\Concerns\FinancialControlResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class FinancialLimitController extends Controller
{
    use FinancialControlResponses;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operation' => ['nullable', Rule::enum(MovementType::class)],
            'subject_type' => ['nullable', Rule::enum(LimitSubjectType::class)],
            'subject_id' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $page = FinancialLimit::query()
            ->when($validated['operation'] ?? null, fn ($q, $v) => $q->where('operation', $v))
            ->when($validated['subject_type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($validated['subject_id'] ?? null, fn ($q, $v) => $q->where('subject_id', $v))
            ->when(array_key_exists('active', $validated), fn ($q) => $q->where('active', $request->boolean('active')))
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 50);

        return $this->paginated($request, $page, fn ($limit) => $this->limitData($limit));
    }

    public function show(Request $request, string $limitId): JsonResponse
    {
        $limit = FinancialLimit::where('public_id', $limitId)->firstOrFail();

        return $this->ok($request, $this->limitData($limit));
    }

    public function store(Request $request, FinancialLimitService $service): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'subject_type' => ['required', Rule::enum(LimitSubjectType::class)],
            'subject_id' => ['nullable', 'string', 'max:255'],
            'operation' => ['required', Rule::enum(MovementType::class)],
            'period' => ['required', Rule::enum(LimitPeriod::class)],
            'metric' => ['required', Rule::enum(LimitMetric::class)],
            'max_amount_cents' => ['nullable', 'integer', 'min:0'],
            'max_count' => ['nullable', 'integer', 'min:0'],
            'action' => ['required', Rule::enum(LimitAction::class)],
            'currency' => ['nullable', 'string', 'size:3'],
            'active' => ['nullable', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
            'change_reason' => ['nullable', 'string', 'max:500'],
            'actor_id' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $limit = $service->create(
                $this->limitAttributes($validated),
                $this->authenticatedActor($request),
                $validated['change_reason'] ?? null
            );
        } catch (InvalidArgumentException $exception) {
            return $this->conflict($request, $exception->getMessage());
        }

        return $this->ok($request, $this->limitData($limit), 201);
    }

    public function update(Request $request, string $limitId, FinancialLimitService $service): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'max_amount_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_count' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'action' => ['sometimes', Rule::enum(LimitAction::class)],
            'active' => ['sometimes', 'boolean'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_until' => ['sometimes', 'nullable', 'date'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'change_reason' => ['nullable', 'string', 'max:500'],
            'actor_id' => ['nullable', 'string', 'max:255'],
            // Campos que no se pueden modificar: se rechazan explícitamente.
            'subject_type' => ['prohibited'],
            'subject_id' => ['prohibited'],
            'operation' => ['prohibited'],
            'period' => ['prohibited'],
            'metric' => ['prohibited'],
            'currency' => ['prohibited'],
        ]);

        $limit = FinancialLimit::where('public_id', $limitId)->firstOrFail();

        try {
            $limit = $service->update(
                $limit,
                $this->limitAttributes($validated),
                $this->authenticatedActor($request),
                $validated['change_reason'] ?? null
            );
        } catch (InvalidArgumentException $exception) {
            return $this->conflict($request, $exception->getMessage());
        }

        return $this->ok($request, $this->limitData($limit));
    }

    /**
     * Historial de cambios del límite (actor, motivo, fecha, antes/después).
     */
    public function history(Request $request, string $limitId): JsonResponse
    {
        $limit = FinancialLimit::where('public_id', $limitId)->firstOrFail();

        return $this->ok(
            $request,
            $limit->changes()->orderBy('id')->get()
                ->map(fn ($change) => $this->limitChangeData($change))
                ->values()
        );
    }

    /**
     * Simula una operación contra los límites vigentes. No registra
     * nada: sirve para que el frontend o un operador consulten si una
     * operación sería aceptada, alertada o bloqueada.
     */
    public function evaluate(Request $request, FinancialLimitService $service): JsonResponse
    {
        $validated = $request->validate([
            'wallet_id' => ['required', 'string'],
            'operation' => ['required', Rule::enum(MovementType::class)],
            'amount_cents' => ['required', 'integer', 'min:1'],
        ]);

        $wallet = Wallet::where('public_id', $validated['wallet_id'])->firstOrFail();

        try {
            $evaluation = $service->evaluate(
                $wallet,
                MovementType::from($validated['operation']),
                (int) $validated['amount_cents']
            );
        } catch (FinancialDependencyUnavailableException $exception) {
            return $this->dependencyUnavailable($request, $exception->getMessage());
        }

        return $this->ok($request, $evaluation->toArray());
    }

    private function limitAttributes(array $validated): array
    {
        $attributes = $validated;
        unset($attributes['actor_id'], $attributes['change_reason']);

        foreach (['max_amount_cents', 'max_count'] as $field) {
            if (array_key_exists($field, $attributes) && $attributes[$field] !== null) {
                $attributes[$field] = (int) $attributes[$field];
            }
        }

        if (array_key_exists('active', $attributes)) {
            $attributes['active'] = filter_var($attributes['active'], FILTER_VALIDATE_BOOLEAN);
        }

        return $attributes;
    }
}
