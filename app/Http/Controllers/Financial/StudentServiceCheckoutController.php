<?php

namespace App\Http\Controllers\Financial;

use App\Domains\Financial\Services\StudentServiceCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * API de Finanzas para Servicios al Estudiante (cliente OAuth de E5) y
 * pantalla donde el alumno autoriza el pago de su locker o sus impresiones.
 */
class StudentServiceCheckoutController
{
    private const TEXTS = [
        'locker_rental' => ['title' => 'Pago de locker', 'consent' => 'Autorizo pagar :amount a Servicios al Estudiante con el saldo de mi wallet.', 'submit' => 'Confirmar y pagar', 'back' => 'module5.lockers.requests.index', 'back_label' => 'Volver a mis lockers'],
        'print_order' => ['title' => 'Pago de impresiones', 'consent' => 'Autorizo pagar :amount a Servicios al Estudiante con el saldo de mi wallet.', 'submit' => 'Confirmar y pagar', 'back' => 'module5.services.index', 'back_label' => 'Volver a mis solicitudes'],
    ];

    public function prepare(Request $request, string $concept, string $source, StudentServiceCheckoutService $service): JsonResponse
    {
        $client = $service->authorizeClient($request->attributes->get('oauth_client_id'), 'prepare');
        $service->concept($concept);
        abort_if($request->query() !== [] || array_diff(array_keys($request->all()), ['student_id', 'amount_cents', 'currency']), 422);
        $data = $request->validate([
            'student_id' => 'required|string|regex:/\A[a-f0-9]{24}\z/',
            'amount_cents' => ['required', $this->positiveCents()],
            'currency' => 'required|in:MXN',
        ]);
        $row = $service->prepare($client, $concept, $source, $data['student_id'], $data['amount_cents']);

        return response()->json(['data' => $service->result($row)], $row->wasRecentlyCreated ? 201 : 200);
    }

    public function lookup(Request $request, string $concept, string $source, StudentServiceCheckoutService $service): JsonResponse
    {
        $client = $service->authorizeClient($request->attributes->get('oauth_client_id'), 'read');
        abort_if($request->all() !== [], 422);

        return response()->json(['data' => $service->result($service->lookup($client, $concept, $source))]);
    }

    public function cancel(Request $request, string $concept, string $source, StudentServiceCheckoutService $service): JsonResponse
    {
        $client = $service->authorizeClient($request->attributes->get('oauth_client_id'), 'cancel');
        abort_if($request->all() !== [], 422);

        return response()->json(['data' => $service->result($service->cancel($client, $concept, $source))]);
    }

    public function show(Request $request, string $paymentRequest, StudentServiceCheckoutService $service): Response
    {
        $review = $service->review($request, $paymentRequest);
        if (! $review['passwordRecent'] && $review['paymentRequest']['status'] === 'PENDING') {
            $request->session()->put('url.intended', route('financial.student-services.show', $paymentRequest));
        }
        $texts = self::TEXTS[$review['paymentRequest']['concept']];

        return Inertia::render('Financial/InstitutionalWalletCheckout', [
            ...$review,
            'checkout' => [
                'title' => $texts['title'],
                'consent' => $texts['consent'],
                'submit' => $texts['submit'],
                'back_href' => route($texts['back']),
                'back_label' => $texts['back_label'],
                'confirmed' => 'El pago está confirmado.',
            ],
        ]);
    }

    public function confirm(Request $request, string $paymentRequest, StudentServiceCheckoutService $service): RedirectResponse|JsonResponse
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

            return redirect()->route('financial.student-services.show', $paymentRequest)->withErrors(['payment' => $e->getMessage()]);
        }

        return redirect()->route('financial.receipts.show', ['source' => 'transaction', 'operationId' => strtolower($row->transaction_id)]);
    }

    private function positiveCents(): callable
    {
        return function ($attribute, $value, $fail) {
            if (! is_int($value) || $value < 1 || $value > 9007199254740991) {
                $fail('El importe debe ser un entero positivo en centavos.');
            }
        };
    }
}
