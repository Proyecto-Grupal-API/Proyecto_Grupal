<?php

namespace App\Http\Controllers\Api\Identity;

use App\Http\Controllers\Controller;
use App\Services\IdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QrValidationController extends Controller
{
    public function __invoke(Request $request, IdentityService $identity): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:120'],
            'context' => ['nullable', 'string', 'max:150'],
        ]);

        $result = $identity->validateQrCode(
            input: $data['code'],
            validatedBy: null,
            context: $data['context'] ?? null,
            ip: $request->ip(),
            validatorLabel: $request->attributes->get('oauth_client_id'),
        );

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
