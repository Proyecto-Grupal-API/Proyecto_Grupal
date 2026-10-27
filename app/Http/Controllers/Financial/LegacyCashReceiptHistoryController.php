<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Contracts\LegacyCashReceiptAuthorization;
use App\Domains\Financial\Exceptions\FinancialDependencyUnavailableException;
use App\Domains\Financial\Services\LegacyCashReceiptHistoryService;
use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LegacyCashReceiptHistoryController extends Controller
{
    private function actor(Request $r): string
    {
        abort_unless($r->user()->two_factor_secret && $r->user()->two_factor_confirmed_at, 403, 'Se requiere segundo factor confirmado.');

        return (string) $r->user()->getKey();
    }

    private function execute(callable $work)
    {
        try {
            return $work();
        } catch (FinancialDependencyUnavailableException|QueryException $e) {
            report($e);

            return response()->json(['message' => 'La consulta de auditoría no está disponible.'], 503);
        }
    }

    public function index(Request $r, LegacyCashReceiptAuthorization $authorization)
    {
        return $this->execute(function () use ($r, $authorization) {
            $authorization->assertGlobal($this->actor($r));

            return Inertia::render('Financial/LegacyCashReceiptHistory');
        });
    }

    public function listing(Request $r, LegacyCashReceiptHistoryService $history)
    {
        return $this->execute(function () use ($r, $history) {
            $actor = $this->actor($r);
            $filters = $r->validate(['page' => 'sometimes|integer|min:1|max:1000000', 'per_page' => 'sometimes|integer|min:1|max:50',
                'transaction_id' => 'nullable|uuid', 'wallet_id' => 'nullable|uuid', 'reviewed_by' => 'nullable|regex:/^[a-f0-9]{24}$/i',
                'actor_id' => 'prohibited', 'principal' => 'prohibited']);

            return response()->json($history->listing($actor, $filters));
        });
    }

    public function show(Request $r, string $reviewId, LegacyCashReceiptHistoryService $history)
    {
        return $this->execute(fn () => Inertia::render('Financial/LegacyCashReceiptReviewDetail', ['review' => $history->detail($this->actor($r), $reviewId)]));
    }
}
