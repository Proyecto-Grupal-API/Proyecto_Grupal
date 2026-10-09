<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Services\TransactionAlertService;
use App\Http\Controllers\Financial\Concerns\FinancialWebActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionAlertWebController extends TransactionAlertController
{
    use FinancialWebActor;

    public function updateStatus(Request $request, string $alertId, TransactionAlertService $service): JsonResponse
    {
        $request->validate(['note' => ['required', 'string', 'max:1000', 'regex:/\S/']]);
        return parent::updateStatus($request, $alertId, $service);
    }
}
