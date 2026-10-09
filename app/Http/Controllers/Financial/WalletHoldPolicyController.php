<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Models\WalletHoldPolicy;
use App\Domains\Financial\Models\WalletHoldPolicyChange;
use App\Domains\Financial\Services\WalletHoldPolicyService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\Concerns\FinancialControlResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class WalletHoldPolicyController extends Controller
{
    use FinancialControlResponses;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operation_type' => ['nullable', 'string', 'max:100'], 'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $page = WalletHoldPolicy::query()
            ->when($data['operation_type'] ?? null, fn ($q, $v) => $q->where('operation_type', $v))
            ->when(isset($data['active']), fn ($q) => $q->where('active', $request->boolean('active')))
            ->orderBy('id')->paginate($data['per_page'] ?? 50);
        return $this->paginated($request, $page, fn ($policy) => $this->data($policy));
    }

    public function show(Request $request, string $policyId): JsonResponse
    {
        return $this->ok($request, $this->data($this->find($policyId)));
    }

    public function history(Request $request, string $policyId): JsonResponse
    {
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:200']]);
        $page = $this->find($policyId)->changes()->orderBy('id')->paginate($data['per_page'] ?? 50);
        return $this->paginated($request, $page, fn ($change) => $this->changeData($change));
    }

    public function store(Request $request, WalletHoldPolicyService $service): JsonResponse
    {
        $data = $request->validate([
            'operation_type' => ['required', 'string', 'max:100', 'regex:/^[A-Z][A-Z0-9_]*$/'],
            'max_duration_seconds' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'active' => ['sometimes', 'boolean'], 'change_reason' => ['required', 'string', 'max:1000', 'regex:/\S/u'],
        ]);
        try {
            $policy = $service->create($data['operation_type'], (int) $data['max_duration_seconds'],
                array_key_exists('active', $data) ? $request->boolean('active') : true,
                $this->authenticatedActor($request), $data['change_reason']);
        } catch (InvalidArgumentException $exception) {
            return $this->conflict($request, $exception->getMessage());
        }
        return $this->ok($request, $this->data($policy), 201);
    }

    public function update(Request $request, string $policyId, WalletHoldPolicyService $service): JsonResponse
    {
        $data = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'max_duration_seconds' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'active' => ['sometimes', 'boolean'], 'change_reason' => ['required', 'string', 'max:1000', 'regex:/\S/u'],
            'operation_type' => ['prohibited'], 'version' => ['prohibited'],
        ]);
        $policy = $this->find($policyId);
        $changes = [];
        if (array_key_exists('max_duration_seconds', $data)) {
            $changes['max_duration_seconds'] = (int) $data['max_duration_seconds'];
        }
        if (array_key_exists('active', $data)) {
            $changes['active'] = $request->boolean('active');
        }
        try {
            $policy = $service->update($policy, (int) $data['expected_version'], $changes,
                $this->authenticatedActor($request), $data['change_reason']);
        } catch (InvalidArgumentException $exception) {
            return $this->conflict($request, $exception->getMessage());
        }
        return $this->ok($request, $this->data($policy));
    }

    private function find(string $id): WalletHoldPolicy
    {
        return WalletHoldPolicy::where('public_id', $id)->firstOrFail();
    }

    private function data(WalletHoldPolicy $policy): array
    {
        return ['id' => strtolower($policy->public_id), 'operation_type' => $policy->operation_type,
            'max_duration_seconds' => $policy->max_duration_seconds, 'active' => $policy->active,
            'version' => $policy->version, 'created_by' => $policy->created_by, 'updated_by' => $policy->updated_by,
            'created_at' => $policy->created_at?->toISOString(), 'updated_at' => $policy->updated_at?->toISOString()];
    }

    private function changeData(WalletHoldPolicyChange $change): array
    {
        return ['id' => strtolower($change->public_id), 'policy_id' => strtolower($change->policy_id),
            'version' => $change->version, 'change_type' => $change->change_type, 'actor_id' => $change->actor_id,
            'reason' => $change->reason, 'correlation_id' => $change->correlation_id,
            'before' => $change->before, 'after' => $change->after, 'created_at' => $change->created_at?->toISOString()];
    }
}
