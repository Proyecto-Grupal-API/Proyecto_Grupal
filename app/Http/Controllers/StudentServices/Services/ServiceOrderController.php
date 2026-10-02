<?php

namespace App\Http\Controllers\StudentServices\Services;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentServices\Services\PayServiceOrderRequest;
use App\Http\Requests\StudentServices\Services\StoreServiceOrderRequest;
use App\Models\StudentServices\Services\PrintJob;
use App\Models\StudentServices\Services\ServiceOrder;
use App\Services\StudentServices\Services\ServiceOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use MongoDB\BSON\ObjectId;
use RuntimeException;
use Throwable;

class ServiceOrderController extends Controller
{
    public function __construct(
        private readonly ServiceOrderService $serviceOrderService
    ) {
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
                ->where(
                    'student_id',
                    $studentId
                )
                ->orderBy(
                    'requested_at',
                    'desc'
                )
                ->get()
                ->map(
                    fn (ServiceOrder $order) =>
                    $this->orderPayload(
                        $order
                    )
                )
                ->values();

        return Inertia::render(
            'student-services/services/Index',
            [
                'orders' =>
                    $orders,
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
                    'order' =>
                        $exception
                            ->getMessage(),
                ])
                ->withInput();
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'order' =>
                        'No fue posible registrar la solicitud.',
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
                    'order' =>
                        $exception
                            ->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'order' =>
                        'No fue posible registrar el pago.',
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
                    'order' =>
                        $exception
                            ->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'order' =>
                        'No fue posible cancelar la solicitud.',
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
                    'order' =>
                        $exception
                            ->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'order' =>
                        'No fue posible cambiar el estado de la solicitud.',
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
                    'order' =>
                        $exception
                            ->getMessage(),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withErrors([
                    'order' =>
                        'No fue posible registrar la entrega.',
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
            'id' =>
                (string)
                $order->id,

            'folio' =>
                $order->folio,

            'serviceType' =>
                $order->service_type,

            'fileName' =>
                $printJob?->file_name,

            'quantity' =>
                $order->quantity,

            'colorMode' =>
                $printJob?->color_mode,

            'paperSize' =>
                $printJob?->paper_size,

            'sides' =>
                $printJob?->sides,

            'observations' =>
                $order->observations
                ?? '',

            /*
             * Mongo guarda centavos.
             * Vue recibe pesos.
             */
            'quotedAmount' =>
                $order
                    ->quoted_amount_cents
                !== null
                    ? $order
                        ->quoted_amount_cents
                    / 100
                    : null,

            'paymentStatus' =>
                $order->payment_status,

            'paymentReferenceId' =>
                $order
                    ->payment_reference_id,

            'status' =>
                $order->status,

            'requestedAt' =>
                $order->requested_at
                    ?->toISOString(),

            'paidAt' =>
                $order->paid_at
                    ?->toISOString(),

            'processingAt' =>
                $order->processing_at
                    ?->toISOString(),

            'readyAt' =>
                $order->ready_at
                    ?->toISOString(),

            'deliveredAt' =>
                $order->delivered_at
                    ?->toISOString(),

            'cancelledAt' =>
                $order->cancelled_at
                    ?->toISOString(),
        ];
    }
}
