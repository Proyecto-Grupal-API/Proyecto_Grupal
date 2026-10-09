<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Contracts\CashAuthorizationProvider;
use App\Domains\Financial\Models\CashShift;
use App\Http\Controllers\Financial\Concerns\FinancialWebActor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

// Reuses the API's scoped queries, validation, DTOs and atomic services.
// Session authentication replaces OAuth identity; CSRF remains enabled.
class CashWebController extends CashController
{
    use FinancialWebActor;

    protected function shiftData(CashShift $shift): array
    {
        return array_merge(parent::shiftData($shift), [
            'is_operator' => $shift->agent_id === $this->authenticatedActor(request()),
        ]);
    }

    public function index(): Response
    {
        return Inertia::render('Financial/Cash');
    }

    public function context(Request $request, CashAuthorizationProvider $provider): JsonResponse
    {
        $values = $request->validate(['association_id' => ['required', 'string', 'max:255']]);
        $permissions = [];
        foreach (['read', 'operate', 'adjust', 'close', 'manage'] as $action) {
            $permissions[$action] = $provider->allows($this->authenticatedActor($request), $values['association_id'], $action);
        }
        return $this->ok($request, ['association_id' => $values['association_id'], 'permissions' => $permissions]);
    }
}
