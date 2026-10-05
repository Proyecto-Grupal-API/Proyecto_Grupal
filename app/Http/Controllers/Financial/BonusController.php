<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Models\Bonus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class BonusController extends Controller
{
    public function show(string $bonusId): JsonResponse
    {
        $bonus = Bonus::where(
            'public_id',
            $bonusId
        )->firstOrFail();

        return response()->json([
            'data' => [
                'id' => $bonus->public_id,
                'beneficiary_type' => $bonus->beneficiary_type,
                'beneficiary_id' => $bonus->beneficiary_id,
                'issuer_type' => $bonus->issuer_type,
                'issuer_id' => $bonus->issuer_id,
                'type' => $bonus->type->value,
                'original_amount_cents' =>
                    $bonus->original_amount_cents,
                'remaining_amount_cents' =>
                    $bonus->remaining_amount_cents,
                'currency' => $bonus->currency,
                'status' => $bonus->status->value,
                'valid_from' =>
                    $bonus->valid_from?->toISOString(),
                'expires_at' =>
                    $bonus->expires_at?->toISOString(),
                'combinable' => $bonus->combinable,
                'allows_partial_use' =>
                    $bonus->allows_partial_use,
                'external_reference' =>
                    $bonus->external_reference,
            ],
            'meta' => [
                'request_id' => request()->header(
                    'X-Request-Id',
                    (string) str()->uuid()
                ),
                'api_version' => 'v1',
            ],
        ]);
    }
}