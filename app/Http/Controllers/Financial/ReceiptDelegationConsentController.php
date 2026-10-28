<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Services\ReceiptDelegationConsentService;
use App\Domains\Identity\ReceiptDelegation\LocalReceiptDelegationProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReceiptDelegationConsentController extends Controller
{
    private function enabled(): void
    {
        abort_unless(in_array(config('financial_receipts.delegation_provider'), ['local', LocalReceiptDelegationProvider::class], true), 503, 'La delegación local no está activada.');
    }

    public function index(Request $r)
    {
        $this->enabled();
        $consent = app(ReceiptDelegationConsentService::class);

        return Inertia::render('Financial/ReceiptApiConsent', ['clients' => $consent->clients()]);
    }

    public function listing(Request $r)
    {
        $this->enabled();
        $consent = app(ReceiptDelegationConsentService::class);

        return response()->json(['data' => $consent->listing((string) $r->user()->getKey())]);
    }

    public function issue(Request $r)
    {
        $this->enabled();
        $consent = app(ReceiptDelegationConsentService::class);
        $d = $r->validate(['client_id' => 'required|string|max:255', 'source' => 'required|in:list,transaction,purchase',
            'operation_id' => 'required_unless:source,list|nullable|uuid', 'page' => 'sometimes|integer|min:1|max:1000000', 'per_page' => 'sometimes|integer|min:1|max:50',
            'consent' => 'required|accepted', 'user_id' => 'prohibited', 'actor_id' => 'prohibited', 'wallet_id' => 'prohibited', 'path' => 'prohibited', 'action' => 'prohibited']);

        return response()->json(['data' => $consent->issue($r, $d)], 201)->header('Cache-Control', 'private, no-store');
    }

    public function revoke(Request $r, string $grantId)
    {
        $this->enabled();
        $consent = app(ReceiptDelegationConsentService::class);
        $consent->revoke((string) $r->user()->getKey(), $grantId);

        return response()->json(['data' => ['revoked' => true]]);
    }
}
