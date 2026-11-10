<?php

namespace App\Http\Controllers\StudentServices\Services;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentServices\Services\PayServiceOrderRequest;
use App\Http\Requests\StudentServices\Services\StoreServiceOrderRequest;
use App\Models\StudentServices\Services\PrintJob;
use App\Models\StudentServices\Services\ServiceOrder;
use App\Services\StudentServices\Benefits\ServiceBenefitService;
use App\Services\StudentServices\Payments\ServiceFinancePayments;
use App\Services\StudentServices\Services\ServiceOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use MongoDB\BSON\ObjectId;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

class ServiceOrderController extends Controller
{
    public function __construct(
        private readonly ServiceOrderService $serviceOrderService,
        private readonly ServiceFinancePayments $financePayments
    ) {}

    /**
     * El alumno paga su orden con la wallet de Finanzas.
     */
    public function startPayment(Request $request, string $orderId): HttpResponse
    {
        $order = $this->ownedOrder($request, $orderId);

        try {
            $path = $this->financePayments->start($order);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['service_order' => $exception->getMessage()]);
        }

        if ($path === null) {
            return to_route('module5.services.index')->with('success', 'Pago confirmado por Finanzas.');
        }

        return Inertia::location(url($path));
    }

    /**
     * Al volver de Finanzas: si el pago está confirmado, la orden pasa a proceso.
     */
    public function reconcilePayment(Request $request, string $orderId): RedirectResponse
    {
        $order = $this->ownedOrder($request, $orderId);

        try {
            $paid = $this->financePayments->reconcile($order);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['service_order' => $exception->getMessage()]);
        }

        return $paid
            ? to_route('module5.services.index')->with('success', 'Pago confirmado por Finanzas.')
            : to_route('module5.services.index')->withErrors(['service_order' => 'Finanzas aún no reporta un pago confirmado para esta orden.']);
    }

    private function ownedOrder(Request $request, string $orderId): ServiceOrder
    {
        abort_unless($this->financePayments->enabled(), 404);
        abort_if($request->all() !== [], 422);

        return ServiceOrder::query()
            ->where('_id', new ObjectId($orderId))
            ->where('student_id', (string) $request->user()->getAuthIdentifier())
            ->firstOrFail();
    }

    private function currentUserId(): ?string
    {
        $user = request()->user();

        return $user !== null ? (string) $user->getAuthIdentifier() : null;
    }

    public function index(
        Request $request
    ): Response {
        $studentId =
            $this->getStudentId(
                $request
            );

        $orders =
            ServiceOrder::query()
                ->when(! app(\App\Services\StudentServices\ServiceAuthority::class)->staff($request->user()), fn ($q) => $q->where('student_id', $studentId))
                ->orderBy(
                    'requested_at',
                    'desc'
                )
                ->get()
                ->map(
                    fn (ServiceOrder $order) => $this->orderPayload(
                        $order
                    )
                )
                ->values();

        return Inertia::render(
            'student-services/services/Index',
            [
                'orders' => $orders,

                /*
                 * Saldo de la beca de impresiones asignada por
                 * Comunidad (API de beneficios, REQ-M6-E5-001).
                 */
                'printAllowance' => app(ServiceBenefitService::class)
                    ->printBalanceFor($studentId),
            ]
        );
    }

    public function store(
        StoreServiceOrderRequest $request
    ): RedirectResponse {
        $data =
            $request->validated();

        try {
            $studentId =
                $this->getStudentId(
                    $request
                );

            $this->serviceOrderService
                ->createOrder(
                    $studentId,
                    $data['service_type'],
                    $data['quantity'],
                    $data['color_mode']
                    ?? null,
                    $data['paper_size']
                    ?? null,
                    $data['sides']
                    ?? null,
                    $data['observations']
                    ?? null,
                    $request->file(
                        'file'
                    )
                );

            return back()->with(
                'success',
                'Solicitud registrada correctamente.'
            );
        } catch (
            RuntimeException $exception
        ) {
            return back()
                ->withErrors([
                    'order' => $exception
                        ->getMessage(),
                ])
                ->withInput();
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'order' => 'No fue posible registrar la solicitud.',
                ])
                ->withInput();
        }
    }

    public function pay(
        PayServiceOrderRequest $request,
        string $orderId
    ): RedirectResponse {
        $data =
            $request->validated();

        try {
            $order =
                ServiceOrder::findOrFail(
                    $orderId
                );

            $this->validateOwner(
                $request,
                $order
            );

            $this->serviceOrderService
                ->markAsPaid(
                    $order,
                    $data[
                    'payment_reference_id'
                    ]
                );

            return back()->with(
                'success',
                'Pago registrado correctamente.'
            );
        } catch (
            RuntimeException $exception
        ) {
            return back()
                ->withErrors([
                    'order' => $exception
                        ->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'order' => 'No fue posible registrar el pago.',
                ]);
        }
    }

    public function cancel(
        Request $request,
        string $orderId
    ): RedirectResponse {
        try {
            $order =
                ServiceOrder::findOrFail(
                    $orderId
                );

            $this->validateOwner(
                $request,
                $order
            );

            $this->serviceOrderService
                ->cancelOrder(
                    $order
                );

            return back()->with(
                'success',
                'Solicitud cancelada correctamente.'
            );
        } catch (
            RuntimeException $exception
        ) {
            return back()
                ->withErrors([
                    'order' => $exception
                        ->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'order' => 'No fue posible cancelar la solicitud.',
                ]);
        }
    }

    public function ready(
        Request $request,
        string $orderId
    ): RedirectResponse {
        try {
            $order =
                ServiceOrder::findOrFail(
                    $orderId
                );

            /*
             * Esta acción posteriormente deberá
             * quedar protegida con permisos de
             * operador/administrador.
             */
            $this->serviceOrderService
                ->markReady(
                    $order
                );

            return back()->with(
                'success',
                'Solicitud marcada como lista.'
            );
        } catch (
            RuntimeException $exception
        ) {
            return back()
                ->withErrors([
                    'order' => $exception
                        ->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'order' => 'No fue posible cambiar el estado de la solicitud.',
                ]);
        }
    }

    public function deliver(
        Request $request,
        string $orderId
    ): RedirectResponse {
        try {
            $order =
                ServiceOrder::findOrFail(
                    $orderId
                );

            $this->validateOwner(
                $request,
                $order
            );

            $this->serviceOrderService
                ->markDelivered(
                    $order
                );

            return back()->with(
                'success',
                'Servicio marcado como entregado.'
            );
        } catch (
            RuntimeException $exception
        ) {
            return back()
                ->withErrors([
                    'order' => $exception
                        ->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'order' => 'No fue posible registrar la entrega.',
                ]);
        }
    }

    private function getStudentId(
        Request $request
    ): string {
        $user =
            $request->user();

        if ($user === null) {
            throw new RuntimeException(
                'No se encontró un usuario autenticado.'
            );
        }

        return (string)
        $user->getAuthIdentifier();
    }

    private function validateOwner(
        Request $request,
        ServiceOrder $order
    ): void {
        if (app(\App\Services\StudentServices\ServiceAuthority::class)->staff($request->user())) { return; }
        $studentId =
            $this->getStudentId(
                $request
            );

        if (
            (string)
            $order->student_id
            !==
            $studentId
        ) {
            throw new RuntimeException(
                'No puedes modificar una solicitud que pertenece a otro usuario.'
            );
        }
    }

    /**
     * @return array{
     *     id: string,
     *     folio: string,
     *     serviceType: string,
     *     fileName: string|null,
     *     quantity: int,
     *     colorMode: string|null,
     *     paperSize: string|null,
     *     sides: string|null,
     *     observations: string,
     *     quotedAmount: int|float|null,
     *     paymentStatus: string,
     *     paymentReferenceId: string|null,
     *     status: string,
     *     requestedAt: string|null,
     *     paidAt: string|null,
     *     processingAt: string|null,
     *     readyAt: string|null,
     *     deliveredAt: string|null,
     *     cancelledAt: string|null
     * }
     */
    private function orderPayload(
        ServiceOrder $order
    ): array {
        $printJob =
            PrintJob::query()
                ->where(
                    'service_order_id',
                    new ObjectId(
                        (string)
                        $order->id
                    )
                )
                ->first();

        return [
            'id' => (string)
                $order->id,

            'folio' => $order->folio,

            'serviceType' => $order->service_type,

            'fileName' => $printJob?->file_name,

            'quantity' => $order->quantity,

            'colorMode' => $printJob?->color_mode,

            'paperSize' => $printJob?->paper_size,

            'sides' => $printJob?->sides,

            'observations' => $order->observations
                ?? '',

            /*
             * Mongo guarda centavos.
             * Vue recibe pesos.
             */
            'quotedAmount' => $order
                ->quoted_amount_cents
                !== null
                    ? $order
                        ->quoted_amount_cents
                    / 100
                    : null,

            'paymentStatus' => $order->payment_status,

            'paymentReferenceId' => $order
                ->payment_reference_id,

            'canStartPayment' => $this->financePayments->canStart($order, $this->currentUserId()),

            'canReconcilePayment' => $this->financePayments->canReconcile($order, $this->currentUserId()),

            'status' => $order->status,

            'requestedAt' => $order->requested_at
                ?->toISOString(),

            'paidAt' => $order->paid_at
                ?->toISOString(),

            'processingAt' => $order->processing_at
                ?->toISOString(),

            'readyAt' => $order->ready_at
                ?->toISOString(),

            'deliveredAt' => $order->delivered_at
                ?->toISOString(),

            'cancelledAt' => $order->cancelled_at
                ?->toISOString(),
        ];
    }
}
