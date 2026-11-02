<?php

namespace App\Http\Controllers\Api\Identity;

use App\Http\Controllers\Controller;
use App\Services\IdentityService;
use App\Support\NfcUid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NfcValidationController extends Controller
{
    public function __invoke(Request $request, IdentityService $identity): JsonResponse
    {
        if (is_string($request->input('credential_uid'))) {
            $request->merge(['credential_uid' => NfcUid::normalize($request->input('credential_uid'))]);
        }

        $data = $request->validate([
            'credential_uid' => ['required', 'string', 'max:255'],
        ]);

        $result = $identity->validateNfcUid(
            $data['credential_uid'],
            $request->attributes->get('oauth_client_id'),
            $request->ip(),
        );

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
