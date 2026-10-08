<?php

namespace App\Http\Controllers;

use App\Services\OAuthTokenService;
use Illuminate\Http\Request;

class OAuthTokenController extends Controller
{
    public function __invoke(Request $request, OAuthTokenService $tokens)
    {
        $validated = $request->validate([
            'grant_type' => ['required', 'in:client_credentials'],
            'client_id' => ['required', 'string'],
            'client_secret' => ['required', 'string'],
            'scope' => ['nullable', 'string'],
        ]);

        try {
            return response()->json($tokens->issue(
                $validated['client_id'],
                $validated['client_secret'],
                preg_split('/\s+/', trim($validated['scope'] ?? ''), -1, PREG_SPLIT_NO_EMPTY),
            ));
        } catch (\InvalidArgumentException) {
            return response()->json([
                'error' => 'invalid_scope',
                'message' => 'One or more requested scopes are not permitted for this client.',
            ], 400);
        }
    }
}
