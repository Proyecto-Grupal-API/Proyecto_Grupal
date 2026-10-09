<?php

namespace App\Http\Controllers\StudentServices\Benefits;

use App\Http\Controllers\Controller;
use App\Models\StudentServices\Services\ServiceOrder;
use App\Services\StudentServices\Benefits\ServiceBenefitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Módulo 5.8 + beca de impresiones: el estudiante paga una orden de
 * impresión con el saldo becado que asignó Comunidad (Equipo 6).
 */
class PrintScholarshipPaymentController extends Controller
{
    public function __construct(
        private ServiceBenefitService $benefits
    ) {}

    public function pay(Request $request, string $orderId): RedirectResponse
    {
        $order = ServiceOrder::findOrFail($orderId);
        $studentId = (string) $request->user()->getAuthIdentifier();

        if ((string) $order->student_id !== $studentId) {
            abort(403);
        }

        try {
            $this->benefits->payPrintOrder($order, $studentId);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['order' => $exception->getMessage()]);
        }

        return back()->with('success', 'Orden pagada con tu beca de impresiones.');
    }
}
