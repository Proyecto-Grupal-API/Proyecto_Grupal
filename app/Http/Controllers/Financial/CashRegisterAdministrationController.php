<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Enums\CashRegisterStatus;
use App\Domains\Financial\Models\CashRegisterChange;
use App\Domains\Financial\Services\CashRegisterAdministrationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class CashRegisterAdministrationController extends CashController
{
    public function storeRegister(Request $request, string $associationId, CashRegisterAdministrationService $service): JsonResponse
    {
        $actor = $this->authorizeAssociation($request, $associationId, 'manage');
        $values = $request->validate(['name' => ['required', 'string', 'max:100'],
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'], 'reason' => ['required', 'string', 'max:1000'],
            'association_id' => ['prohibited'], 'status' => ['prohibited']]);
        $key = $this->key($request);
        return $this->execute($request, fn () => $service->create($associationId, $values['name'], $values['currency'], $actor, $values['reason'], $key));
    }

    public function updateRegister(Request $request, string $associationId, string $registerId, CashRegisterAdministrationService $service): JsonResponse
    {
        $actor = $this->authorizeAssociation($request, $associationId, 'manage');
        $register = $this->register($associationId, $registerId);
        $values = $request->validate(['name' => ['required', 'string', 'max:100'],
            'status' => ['required', Rule::enum(CashRegisterStatus::class)], 'expected_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'], 'association_id' => ['prohibited'], 'currency' => ['prohibited']]);
        $key = $this->key($request);
        return $this->execute($request, fn () => $service->update($associationId, $register->public_id,
            $values['name'], CashRegisterStatus::from($values['status']), $values['expected_version'], $actor, $values['reason'], $key));
    }

    public function registerHistory(Request $request, string $associationId, string $registerId): JsonResponse
    {
        $this->authorizeAssociation($request, $associationId, 'read');
        $register = $this->register($associationId, $registerId);
        return $this->paginated($request, CashRegisterChange::where('cash_register_id', $register->id)->orderByDesc('version')
            ->paginate($this->pageSize($request)), fn ($change) => ['id' => strtolower($change->public_id),
                'version' => $change->version, 'action' => $change->action, 'before' => $change->before_state,
                'after' => $change->after_state, 'actor_id' => $change->actor_id, 'reason' => $change->reason,
                'created_at' => $change->created_at?->toISOString()]);
    }
}
