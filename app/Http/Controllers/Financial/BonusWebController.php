<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Enums\BonusStatus;
use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Models\Bonus;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Read-only access: ownership is derived exclusively from the authenticated user. */
class BonusWebController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::enum(BonusType::class)],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
        $owned = $this->owned($request);
        $now = now();
        // A grouped SQL sum avoids loading every bonus and never adds different currencies.
        $balances = (clone $owned)->whereIn('status', [BonusStatus::ACTIVO->value, BonusStatus::PENDIENTE->value])
            ->where('valid_from', '<=', $now)->where('expires_at', '>', $now)
            ->where('remaining_amount_cents', '>', 0)->select('currency')
            ->selectRaw('SUM(remaining_amount_cents) AS balance_cents')->groupBy('currency')->get()
            ->map(fn ($row) => ['currency' => $row->currency, 'amount_cents' => (int) $row->balance_cents]);
        $rows = (clone $owned)->when($data['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->latest('id')->paginate(12)->withQueryString();
        return Inertia::render('Financial/Bonuses', [
            'bonuses' => $rows->through(fn ($bonus) => $this->fields($bonus)),
            'balances' => $balances,
            'filters' => ['type' => $data['type'] ?? ''],
            'timezone' => config('financial.business_timezone'),
            'types' => array_map(fn ($type) => $type->value, BonusType::cases()),
        ]);
    }

    public function show(Request $request, string $bonusId)
    {
        $bonus = $this->owned($request)->where('public_id', $bonusId)->with('restrictions')->firstOrFail();
        return response()->json(['data' => $this->fields($bonus) + [
            'restrictions' => $bonus->restrictions->map(fn ($restriction) => [
                'id' => strtolower($restriction->public_id),
                'type' => $restriction->restriction_type->value,
                'target_id' => $restriction->target_id,
            ])->values(),
        ]]);
    }

    public function history(Request $request, string $bonusId)
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);
        $bonus = $this->owned($request)->where('public_id', $bonusId)->firstOrFail();
        $rows = $bonus->ledgerEntries()->latest('id')->paginate(20);
        return response()->json([
            'data' => $rows->getCollection()->map(fn ($entry) => [
                'id' => strtolower($entry->public_id),
                'movement_type' => $entry->movement_type->value,
                'amount_cents' => $entry->amount_cents,
                'remaining_after_cents' => $entry->remaining_after_cents,
                'reference_type' => $entry->reference_type,
                'reference_id' => $entry->reference_id,
                'reason' => $entry->reason,
                'created_at' => $entry->created_at?->toISOString(),
            ])->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    private function owned(Request $request): Builder
    {
        // PurchasePaymentService uses the user's wallet owner identifier for the beneficiary.
        // Do not match email, enrollment number, names or client-supplied beneficiary identifiers.
        return Bonus::whereIn('beneficiary_type', ['USER', 'STUDENT'])
            ->where('beneficiary_id', (string) $request->user()->getKey());
    }

    private function fields(Bonus $bonus): array
    {
        $status = $bonus->status->value;
        if (!in_array($bonus->status, [BonusStatus::CANCELADO, BonusStatus::EXPIRADO], true)) {
            if ($bonus->expires_at && now()->gte($bonus->expires_at)) {
                $status = BonusStatus::EXPIRADO->value;
            } elseif ($bonus->remaining_amount_cents <= 0) {
                $status = BonusStatus::AGOTADO->value;
            } elseif ($bonus->valid_from && now()->lt($bonus->valid_from)) {
                $status = BonusStatus::PENDIENTE->value;
            } else {
                $status = BonusStatus::ACTIVO->value;
            }
        }
        return [
            'id' => strtolower($bonus->public_id), 'type' => $bonus->type->value,
            'status' => $status, 'recorded_status' => $bonus->status->value,
            'original_amount_cents' => $bonus->original_amount_cents,
            'remaining_amount_cents' => $bonus->remaining_amount_cents,
            'currency' => $bonus->currency, 'valid_from' => $bonus->valid_from?->toISOString(),
            'expires_at' => $bonus->expires_at?->toISOString(), 'combinable' => $bonus->combinable,
            'allows_partial_use' => $bonus->allows_partial_use, 'external_reference' => $bonus->external_reference,
        ];
    }
}
