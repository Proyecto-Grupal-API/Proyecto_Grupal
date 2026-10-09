<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Enums\DifferenceStatus;
use App\Domains\Financial\Enums\ReconciliationCheck;
use App\Domains\Financial\Enums\ReconciliationStatus;
use App\Domains\Financial\Enums\ReconciliationTrigger;
use App\Domains\Financial\Exceptions\ReconciliationInProgressException;
use App\Domains\Financial\Models\Reconciliation;
use App\Domains\Financial\Models\ReconciliationDifference;
use App\Domains\Financial\Services\ReconciliationService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\Concerns\FinancialControlResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class ReconciliationController extends Controller
{
    use FinancialControlResponses;

    public function index(Request $request, ReconciliationService $service): JsonResponse
    {
        $validated = $request->validate([
            'business_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::enum(ReconciliationStatus::class)],
            'trigger_source' => ['nullable', Rule::enum(ReconciliationTrigger::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $page = $service->list($validated, (int) ($validated['per_page'] ?? 50));

        return $this->paginated($request, $page, fn ($r) => $this->reconciliationData($r));
    }

    public function show(Request $request, string $reconciliationId): JsonResponse
    {
        $reconciliation = Reconciliation::where('public_id', $reconciliationId)->firstOrFail();

        return $this->ok($request, $this->reconciliationData($reconciliation));
    }

    public function differences(Request $request, string $reconciliationId): JsonResponse
    {
        $validated = $request->validate([
            'check_code' => ['nullable', Rule::enum(ReconciliationCheck::class)],
            'status' => ['nullable', Rule::enum(DifferenceStatus::class)],
            'wallet_id' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $reconciliation = Reconciliation::where('public_id', $reconciliationId)->firstOrFail();

        // Diferencias observadas por esta corrida (nuevas o ya conocidas).
        $page = $reconciliation->observedDifferences()
            ->when($validated['check_code'] ?? null, fn ($q, $v) => $q->where('check_code', $v))
            ->when($validated['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($validated['wallet_id'] ?? null, fn ($q, $v) => $q->where('wallet_id', $v))
            ->orderBy('id')
            ->paginate((int) ($validated['per_page'] ?? 50));

        return $this->paginated($request, $page, fn ($d) => $this->differenceData($d));
    }

    public function store(Request $request, ReconciliationService $service): JsonResponse
    {
        $validated = $request->validate([
            'business_date' => ['required', 'date_format:Y-m-d'],
            'wallet_ids' => ['nullable', 'array', 'min:1', 'max:500'],
            'wallet_ids.*' => ['string', 'uuid'],
            'actor_id' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        $idempotencyKey = $validated['idempotency_key']
            ?? $request->header('Idempotency-Key');

        try {
            $reconciliation = $service->run(
                $validated['business_date'],
                $this->authenticatedActor($request),
                $validated['wallet_ids'] ?? null,
                ReconciliationTrigger::API,
                $idempotencyKey
            );
        } catch (ReconciliationInProgressException $exception) {
            return $this->conflict($request, $exception->getMessage(), [
                'code' => ReconciliationInProgressException::CODE,
            ]);
        } catch (InvalidArgumentException $exception) {
            return $this->conflict($request, $exception->getMessage());
        }

        // Una repetición con la misma llave devuelve la corrida existente.
        $status = $service->wasReplay() ? 200 : 201;

        return $this->ok($request, $this->reconciliationData($reconciliation), $status);
    }

    public function resolveDifference(
        Request $request,
        string $reconciliationId,
        string $differenceId,
        ReconciliationService $service
    ): JsonResponse {
        $validated = $request->validate([
            'actor_id' => ['nullable', 'string', 'max:255'],
            'note' => ['required', 'string', 'max:1000'],
        ]);

        $difference = ReconciliationDifference::where('public_id', $differenceId)->first();

        // La diferencia debe pertenecer a (o haber sido observada por) la corrida indicada.
        if (! $difference || ! $difference->wasObservedIn($reconciliationId)) {
            abort(404);
        }

        try {
            $difference = $service->resolveDifference(
                $difference,
                $this->authenticatedActor($request),
                $validated['note']
            );
        } catch (InvalidArgumentException $exception) {
            return $this->conflict($request, $exception->getMessage());
        }

        return $this->ok($request, $this->differenceData($difference));
    }
}
