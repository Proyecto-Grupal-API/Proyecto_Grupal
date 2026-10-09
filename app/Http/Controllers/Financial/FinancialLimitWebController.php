<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Models\FinancialLimit;
use App\Domains\Financial\Services\FinancialLimitService;
use App\Http\Controllers\Financial\Concerns\FinancialWebActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancialLimitWebController extends FinancialLimitController
{
    use FinancialWebActor;

    public function store(Request $request, FinancialLimitService $service): JsonResponse
    {
        $request->validate(['change_reason' => ['required', 'string', 'max:500', 'regex:/\S/']]);
        return parent::store($request, $service);
    }

    public function update(Request $request, string $limitId, FinancialLimitService $service): JsonResponse
    {
        $request->validate([
            'change_reason' => ['required', 'string', 'max:500', 'regex:/\S/'],
            'expected_revision' => ['required', 'string', 'size:64'],
        ]);
        return DB::connection('sqlsrv')->transaction(function () use ($request, $limitId, $service) {
            $limit = FinancialLimit::where('public_id', $limitId)->lockForUpdate()->firstOrFail();
            if ($request->input('expected_revision') !== $this->limitData($limit)['revision']) {
                return $this->conflict($request, 'El límite cambió. Actualiza la lista antes de editar.');
            }
            return parent::update($request, $limitId, $service);
        });
    }

    protected function limitData(FinancialLimit $limit): array
    {
        $data = parent::limitData($limit);
        $data['revision'] = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        return $data;
    }

    public function history(Request $request, string $limitId): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $limit = FinancialLimit::where('public_id', $limitId)->firstOrFail();
        return $this->paginated($request, $limit->changes()->orderByDesc('id')->paginate(20),
            fn ($change) => $this->limitChangeData($change));
    }
}
