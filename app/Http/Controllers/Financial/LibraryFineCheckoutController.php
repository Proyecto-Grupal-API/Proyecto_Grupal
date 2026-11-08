<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Services\LibraryFineCheckoutService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class LibraryFineCheckoutController
{
    public function prepare(Request $request, string $fine, LibraryFineCheckoutService $service)
    {
        $client = $service->authorizeClient($request->attributes->get('oauth_client_id'), 'prepare');
        abort_if($request->query() !== [] || array_diff(array_keys($request->all()), ['student_id', 'amount_cents', 'currency']), 422);
        $data = $request->validate([
            'student_id' => 'required|string|regex:/\A[a-f0-9]{24}\z/',
            'amount_cents' => ['required', function ($attribute, $value, $fail) {
                if (! is_int($value) || $value < 1 || $value > 9007199254740991) {
                    $fail('El importe debe ser un entero positivo en centavos.');
                }
            }],
            'currency' => 'required|in:MXN',
        ]);
        $row = $service->prepare($client, $fine, $data['student_id'], $data['amount_cents']);

        return response()->json(['data' => $service->result($row)], $row->wasRecentlyCreated ? 201 : 200);
    }

    public function lookup(Request $request, string $fine, LibraryFineCheckoutService $service)
    {
        $client = $service->authorizeClient($request->attributes->get('oauth_client_id'), 'read');
        abort_if($request->all() !== [], 422);
        $row = $service->lookup($client, $fine);

        return response()->json(['data' => $service->result($row)]);
    }

    public function cancel(Request $request, string $fine, LibraryFineCheckoutService $service)
    {
        $client = $service->authorizeClient($request->attributes->get('oauth_client_id'), 'cancel');
        abort_if($request->all() !== [], 422);
        $row = $service->cancel($client, $fine);

        return response()->json(['data' => $service->result($row)]);
    }

    public function show(Request $request, string $paymentRequest, LibraryFineCheckoutService $service)
    {
        $review = $service->review($request, $paymentRequest);
        if (! $review['passwordRecent'] && $review['paymentRequest']['status'] === 'PENDING') {
            $request->session()->put('url.intended', route('financial.library-fines.show', $paymentRequest));
        }

        return Inertia::render('Financial/InstitutionalWalletCheckout', $review);
    }

    public function confirm(Request $request, string $paymentRequest, LibraryFineCheckoutService $service)
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

            return redirect()->route('financial.library-fines.show', $paymentRequest)->withErrors(['payment' => $e->getMessage()]);
        }

        return redirect()->route('financial.receipts.show', ['source' => 'transaction', 'operationId' => strtolower($row->transaction_id)]);
    }
}
