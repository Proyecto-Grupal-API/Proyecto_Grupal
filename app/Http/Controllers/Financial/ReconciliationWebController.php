<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Services\ReconciliationService;
use App\Domains\Financial\Enums\ReconciliationTrigger;
use App\Domains\Financial\Exceptions\ReconciliationInProgressException;
use App\Domains\Financial\Models\Reconciliation;
use InvalidArgumentException;
use App\Http\Controllers\Financial\Concerns\FinancialWebActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReconciliationWebController extends ReconciliationController
{
    use FinancialWebActor;

    public function store(Request $request, ReconciliationService $service): JsonResponse
    {
        $validated = $request->validate([
            'business_date' => ['required', 'date_format:Y-m-d'],
            'wallet_ids' => ['nullable', 'array', 'min:1', 'max:500'],
            'wallet_ids.*' => ['string', 'uuid', 'distinct'],
            'idempotency_key' => ['prohibited'],
        ]);
        validator(['key' => $request->header('Idempotency-Key')], [
            'key' => ['required', 'string', 'max:255', 'regex:/\S/'],
        ])->validate();
        $key = trim($request->header('Idempotency-Key'));
        $actor = $this->authenticatedActor($request);
        $existing = Reconciliation::where('idempotency_key', $key)->first();
        if ($existing && ($existing->executed_by !== $actor || $existing->trigger_source !== ReconciliationTrigger::INTERFAZ)) {
            return $this->conflict($request, 'La clave ya pertenece a otra solicitud.');
        }
        try {
            $run = $service->run($validated['business_date'], $actor, $validated['wallet_ids'] ?? null,
                ReconciliationTrigger::INTERFAZ, $key);
        } catch (ReconciliationInProgressException $exception) {
            return $this->conflict($request, $exception->getMessage(), ['code' => ReconciliationInProgressException::CODE]);
        } catch (InvalidArgumentException $exception) {
            return $this->conflict($request, $exception->getMessage());
        }
        return $this->ok($request, $this->reconciliationData($run), $service->wasReplay() ? 200 : 201);
    }

    public function resolveDifference(Request $request, string $reconciliationId, string $differenceId, ReconciliationService $service): JsonResponse
    {
        $request->validate(['note' => ['required', 'string', 'max:1000', 'regex:/\S/']]);
        return parent::resolveDifference($request, $reconciliationId, $differenceId, $service);
    }
}
