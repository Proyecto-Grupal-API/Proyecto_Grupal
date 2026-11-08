<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Services\EventCheckoutService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class EventCheckoutController
{
    public function prepare(Request $request, string $registroId, string $generation, EventCheckoutService $service)
    {
        $client = $service->authorize($request->attributes->get('oauth_client_id'), 'prepare');
        $source = $this->source($registroId, $generation);
        $id = 'required|string|regex:/\A[a-f0-9]{24}\z/';
        abort_if($request->query() !== [] || $request->header('Idempotency-Key') !== $source
            || array_diff(array_keys($request->all()), ['student_id', 'event_id', 'organization_id', 'amount_cents', 'currency', 'reservation_expires_at']), 422);
        $data = $request->validate([
            'student_id' => $id, 'event_id' => $id, 'organization_id' => $id, 'currency' => 'required|in:MXN',
            'amount_cents' => ['required', function ($attribute, $value, $fail) {
                if (! is_int($value) || $value < 1 || $value > 9007199254740991) {
                    $fail('Se requiere un importe entero positivo en centavos.');
                }
            }],
            'reservation_expires_at' => 'required|string|date_format:Y-m-d\TH:i:s.v\Z',
        ]);
        $row = $service->prepare($client, $source, $data);

        return response()->json(['data' => $service->result($row)], $row->wasRecentlyCreated ? 201 : 200);
    }

    public function lookup(Request $request, string $registroId, string $generation, EventCheckoutService $service)
    {
        $client = $service->authorize($request->attributes->get('oauth_client_id'), 'read');
        abort_if($request->all() !== [], 422);

        return response()->json(['data' => $service->result($service->lookup($client, $this->source($registroId, $generation)))]);
    }

    public function cancel(Request $request, string $registroId, string $generation, EventCheckoutService $service)
    {
        $client = $service->authorize($request->attributes->get('oauth_client_id'), 'cancel');
        abort_if($request->all() !== [], 422);

        return response()->json(['data' => $service->result($service->cancel($client, $this->source($registroId, $generation)))]);
    }

    public function show(Request $request, string $paymentRequest, EventCheckoutService $service)
    {
        $review = $service->review($request, $paymentRequest);
        if (! $review['passwordRecent'] && $review['paymentRequest']['status'] === 'PENDING') {
            $request->session()->put('url.intended', route('financial.event-payments.show', $paymentRequest));
        }

        return Inertia::render('Financial/InstitutionalWalletCheckout', $review + ['eventCheckout' => true]);
    }

    public function confirm(Request $request, string $paymentRequest, EventCheckoutService $service)
    {
        abort_if(array_diff(array_keys($request->all()), ['consent', 'review_token']), 422);
        $data = $request->validate(['consent' => 'required|accepted', 'review_token' => 'required|string|size:64']);
        try {
            $row = $service->confirm($request, $paymentRequest, $data['review_token']);
        } catch (InvalidArgumentException|HttpExceptionInterface $e) {
            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() !== 409) {
                throw $e;
            }
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 409);
            }

            return to_route('financial.event-payments.show', $paymentRequest)->withErrors(['payment' => $e->getMessage()]);
        }

        return to_route('financial.receipts.show', ['source' => 'transaction', 'operationId' => strtolower($row->transaction_id)]);
    }

    private function source(string $registration, string $generation): string
    {
        return 'event-reservation:'.$registration.':'.$generation;
    }
}
