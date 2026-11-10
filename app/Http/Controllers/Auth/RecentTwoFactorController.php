<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\RecentTwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RecentTwoFactorController extends Controller
{
    public function __invoke(Request $request, RecentTwoFactor $factor): JsonResponse
    {
        $validator = Validator::make($request->only(['code', 'recovery_code']), ['code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/'],
            'recovery_code' => ['prohibited']]);
        // Always JSON: never flash a TOTP or recovery code into the session's old input.
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        try {
            $factor->confirm($request, $validator->validated()['code']);
        } catch (ValidationException $error) {
            return response()->json(['errors' => $error->errors()], 422);
        }

        return response()->json(['ok' => true, 'valid_seconds' => RecentTwoFactor::VALID_SECONDS]);
    }
}
