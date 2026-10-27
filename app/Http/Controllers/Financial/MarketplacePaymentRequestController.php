<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Models\MarketplacePaymentRequest;
use App\Domains\Financial\Services\MarketplaceCheckoutService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use InvalidArgumentException;

class MarketplacePaymentRequestController extends Controller
{
    private function client(Request $r): string
    {
        $client = $r->attributes->get('oauth_client_id');
        abort_unless(is_string($client) && $client !== '' && strlen($client) <= 100, 401);

        return $client;
    }

    public function prepare(Request $r, MarketplaceCheckoutService $s)
    {
        abort_unless($s->enabled(), 503, 'La confirmacion de compras no esta habilitada.');
        abort_if($r->query() !== [], 422, 'No se admiten parametros de consulta al preparar.');
        $allowed = ['buyer_id', 'order_id', 'business_id', 'amount_cents', 'currency', 'description', 'items'];
        abort_if(array_diff(array_keys($r->all()), $allowed) !== [], 422, 'Campos no admitidos.');
        $d = $r->validate(['buyer_id' => 'required|string|regex:/^[a-fA-F0-9]{24}$/', 'order_id' => 'required|string|max:100',
            'business_id' => 'required|string|max:100', 'amount_cents' => ['required', function ($attribute, $value, $fail) {
                if (! is_int($value) || $value < 1 || $value > 9007199254740991) {
                    $fail('Se requiere un importe entero positivo en centavos.');
                }
            }],
            'currency' => 'required|in:MXN', 'description' => 'sometimes|string|max:200', 'items' => 'sometimes|array|max:100']);
        $key = $r->header('Idempotency-Key');
        abort_unless(is_string($key) && trim($key) !== '' && strlen($key) <= 255, 422, 'Idempotency-Key obligatorio.');
        try {
            $row = $s->prepare($this->client($r), $key, $d);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $s->partnerResult($row)], $row->wasRecentlyCreated ? 201 : 200);
    }

    public function lookup(Request $r, MarketplaceCheckoutService $s)
    {
        // Laravel getJson emits [] even when the GET has no input. Empty containers contain no selectors.
        abort_unless(in_array(trim($r->getContent()), ['', '[]', '{}'], true), 422, 'No se admiten datos en el cuerpo de la consulta.');
        abort_if(array_diff(array_keys($r->query()), ['order_id', 'business_id']) !== [], 422);
        $d = $r->validate(['order_id' => 'required|string|max:100', 'business_id' => 'required|string|max:100']);
        $client = $this->client($r);
        $s->merchant($client, $d['business_id'], 'financial:purchase:read');
        $row = MarketplacePaymentRequest::where('client_id', $client)->where('business_id', $d['business_id'])->where('order_id', $d['order_id'])->firstOrFail();

        return response()->json(['data' => $s->partnerResult($row)]);
    }
}
