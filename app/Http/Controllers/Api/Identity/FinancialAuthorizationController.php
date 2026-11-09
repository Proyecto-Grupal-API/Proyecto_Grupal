<?php

namespace App\Http\Controllers\Api\Identity;

use App\Contracts\FinancialAuthorizationDecision;
use App\Exceptions\FinancialAuthorizationUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialAuthorizationRequest;
use Illuminate\Http\JsonResponse;

class FinancialAuthorizationController extends Controller
{
    public function check(FinancialAuthorizationRequest $request, FinancialAuthorizationDecision $authorization): JsonResponse
    {
        $data = $request->validated();
        try {
            $allowed = $authorization->allows($data['subject_id'], $data['bonus_type'], $data['operation'], $data['scope_type'], $data['scope_id']);
        } catch (FinancialAuthorizationUnavailable) {
            // No query, identity or connection details leak through the response.
            return response()->json(['message' => 'Authorization infrastructure unavailable.'], 503);
        }

        return response()->json(['authorized' => $allowed]);
    }
}
