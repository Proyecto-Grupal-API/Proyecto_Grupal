<?php

namespace App\Http\Controllers\StudentServices\Lockers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentServices\Lockers\AssignLockerToRequestRequest;
use App\Http\Requests\StudentServices\Lockers\ConfirmLockerPaymentRequest;
use App\Http\Requests\StudentServices\Lockers\StorePaidLockerRequest;
use App\Models\StudentServices\Lockers\Locker;
use App\Models\StudentServices\Lockers\LockerPeriod;
use App\Models\StudentServices\Lockers\LockerRequest;
use App\Services\StudentServices\Lockers\LockerAssignmentService;
use App\Services\StudentServices\Lockers\LockerRequestService;
use App\Services\StudentServices\Payments\Money;
use App\Services\StudentServices\Payments\ServiceFinancePayments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use MongoDB\BSON\ObjectId;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class LockerRequestController extends Controller
{
    public function __construct(
        private readonly LockerRequestService $requests,
        private readonly LockerAssignmentService $assignments,
        private readonly ServiceFinancePayments $financePayments
    ) {}

    /**
     * El alumno paga su solicitud con la wallet de Finanzas: se prepara el
     * cobro y se le lleva a la pantalla de Finanzas para autorizarlo.
     */
    public function startPayment(Request $request, string $requestId): HttpResponse
    {
        $lockerRequest = $this->ownedRequest($request, $requestId);

        try {
            $path = $this->financePayments->start($lockerRequest);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['locker_request' => $exception->getMessage()]);
        }

        if ($path === null) {
            return to_route('module5.lockers.requests.index')->with('success', 'Pago confirmado por Finanzas.');
        }

        return Inertia::location(url($path));
    }

    /**
     * Al volver de Finanzas: consulta el cobro y, si está confirmado, asigna el locker.
     */
    public function reconcilePayment(Request $request, string $requestId): RedirectResponse
    {
        $lockerRequest = $this->ownedRequest($request, $requestId);

        try {
            $paid = $this->financePayments->reconcile($lockerRequest);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['locker_request' => $exception->getMessage()]);
        }

        return $paid
            ? to_route('module5.lockers.requests.index')->with('success', 'Pago confirmado por Finanzas.')
            : to_route('module5.lockers.requests.index')->withErrors(['locker_request' => 'Finanzas aún no reporta un pago confirmado para esta solicitud.']);
    }

    private function ownedRequest(Request $request, string $requestId): LockerRequest
    {
        abort_unless($this->financePayments->enabled(), 404);
        abort_if($request->all() !== [], 422);

        return LockerRequest::query()
            ->where('_id', new ObjectId($requestId))
            ->where('student_id', (string) $request->user()->getAuthIdentifier())
            ->firstOrFail();
    }

    public function index(
        Request $request
    ): Response {
        $periods =
            LockerPeriod::query()
                ->get()
                ->keyBy(
                    fn (
                        LockerPeriod $period
                    ) => (string)
                    $period->id
                );

        $lockers =
            Locker::query()
                ->get()
                ->keyBy(
                    fn (
                        Locker $locker
                    ) => (string)
                    $locker->id
                );

        /*
         * Por ahora se muestran todas las solicitudes.
         * Cuando Equipo 1 entregue Roles/Policies,
         * se podrá limitar por tipo de usuario.
         */
        $userId = $request->user() !== null ? (string) $request->user()->getAuthIdentifier() : null;

        $requests =
            app(\App\Services\StudentServices\ServiceAuthority::class)->propias(LockerRequest::query())
                ->orderBy(
                    'created_at',
                    'desc'
                )
                ->get()
                ->map(
                    function (
                        LockerRequest $lockerRequest
                    ) use (
                        $periods,
                        $lockers,
                        $userId
                    ) {
                        $period =
                            $periods->get(
                                (string)
                                $lockerRequest
                                    ->period_id
                            );

                        $locker =
                            $lockerRequest
                                ->locker_id
                            !== null
                                ? $lockers
                                    ->get(
                                        (string)
                                        $lockerRequest
                                            ->locker_id
                                    )
                                : null;

                        return [
                            'id' => (string)
                                $lockerRequest->id,

                            'folio' => $lockerRequest
                                ->folio,

                            'student_id' => $lockerRequest
                                ->student_id,

                            'period_id' => (string)
                                $lockerRequest
                                    ->period_id,

                            'period_name' => $period->name
                                ??
                                'Periodo no encontrado',

                            'locker_id' => $lockerRequest
                                ->locker_id
                                !== null
                                    ? (string)
                                $lockerRequest
                                    ->locker_id
                                    : null,

                            'locker_code' => $locker?->code,

                            'preferred_size' => $lockerRequest
                                ->preferred_size,

                            'preferred_building' => $lockerRequest
                                ->preferred_building,

                            'request_type' => $lockerRequest
                                ->request_type,

                            'status' => $lockerRequest
                                ->status,

                            'amount' => Money::toPesos(
                                $lockerRequest
                                    ->amountInCents()
                            ),

                            'amount_cents' => $lockerRequest
                                ->amountInCents(),

                            'payment_reference' => $lockerRequest
                                ->payment_reference,

                            'can_start_payment' => $this->financePayments->canStart($lockerRequest, $userId),

                            'can_reconcile_payment' => $this->financePayments->canReconcile($lockerRequest, $userId),

                            'paid_at' => $lockerRequest
                                ->paid_at
                                ?->format(
                                    'Y-m-d H:i'
                                ),

                            'created_at' => $lockerRequest
                                ->created_at
                                ?->format(
                                    'Y-m-d H:i'
                                ),

                            'notes' => $lockerRequest
                                ->notes,
                        ];
                    }
                )
                ->values();

        $activePeriods =
            $periods
                ->filter(
                    fn (
                        LockerPeriod $period
                    ) => $period->status ===
                        'active'
                )
                ->map(
                    fn (
                        LockerPeriod $period
                    ) => $period->toPayload()
                )
                ->values();

        $availability =
            $lockers
                ->filter(
                    fn (
                        Locker $locker
                    ) => $locker->status ===
                        'available'
                )
                ->groupBy(
                    fn (
                        Locker $locker
                    ) => $locker->building
                        .'|'
                        .$locker->size
                )
                ->map(
                    fn ($group) => [
                        'building' => $group
                            ->first()
                            ->building,

                        'size' => $group
                            ->first()
                            ->size,

                        'count' => $group->count(),
                    ]
                )
                ->values();

        return Inertia::render(
            'student-services/lockers/Requests',
            [
                'requests' => $requests,

                'periods' => $activePeriods,

                'availability' => $availability,

                'available_lockers' => $lockers
                    ->filter(
                        fn (
                            Locker $locker
                        ) => $locker
                            ->status
                            ===
                            'available'
                    )
                    ->sortBy(
                        fn (
                            Locker $locker
                        ) => $locker
                            ->building
                        .$locker
                            ->zone
                        .$locker
                            ->code
                    )
                    ->map(
                        fn (
                            Locker $locker
                        ) => $locker
                            ->toPayload()
                    )
                    ->values(),

                'current_student_id' => (string)
                    $request
                        ->user()
                        ->id,
            ]
        );
    }

    public function store(
        StorePaidLockerRequest $request
    ): RedirectResponse {
        $data =
            $request->validated();

        try {
            $this->requests
                ->createPaidRequest(
                    (string)
                    $request->user()->id,

                    $data['period_id'],

                    $data['size'],

                    isset(
                        $data['building']
                    )
                        ? trim(
                            $data['building']
                        )
                        : null
                );
        } catch (
            RuntimeException $exception
        ) {
            return back()
                ->withErrors([
                    'status' => $exception
                        ->getMessage(),
                ]);
        }

        return back()->with(
            'success',
            'Solicitud registrada. Confirma el pago para reservar tu locker.'
        );
    }

    public function pay(
        ConfirmLockerPaymentRequest $request,
        string $requestId
    ): RedirectResponse {
        $lockerRequest =
            LockerRequest::findOrFail(
                $requestId
            );

        try {
            $assignment =
                $this->requests
                    ->confirmPayment(
                        $lockerRequest,

                        trim(
                            $request
                                ->validated()[
                            'payment_reference'
                            ]
                        ),

                        (string)
                        $request
                            ->user()
                            ->id
                    );
        } catch (
            RuntimeException $exception
        ) {
            return back()
                ->withErrors([
                    'status' => $exception
                        ->getMessage(),
                ]);
        }

        return back()->with(
            'success',
            $assignment === null
                ? 'Pago registrado. No hay locker disponible por ahora; la solicitud queda en espera de asignación.'
                : 'Pago registrado y locker asignado correctamente.'
        );
    }

    public function cancel(
        string $requestId
    ): RedirectResponse {
        $lockerRequest =
            LockerRequest::findOrFail(
                $requestId
            );

        try {
            $this->requests
                ->cancel(
                    $lockerRequest
                );
        } catch (
            RuntimeException $exception
        ) {
            return back()
                ->withErrors([
                    'status' => $exception
                        ->getMessage(),
                ]);
        }

        return back()->with(
            'success',
            'Solicitud cancelada.'
        );
    }

    public function assign(
        AssignLockerToRequestRequest $request,
        string $requestId
    ): RedirectResponse {
        $lockerRequest =
            LockerRequest::findOrFail(
                $requestId
            );

        $locker =
            Locker::find(
                $request
                    ->string('locker_id')
                    ->value()
            );

        if ($locker === null) {
            return back()
                ->withErrors([
                    'locker_id' => 'El locker seleccionado no existe.',
                ]);
        }

        try {
            $this->assignments
                ->assign(
                    $lockerRequest,
                    $locker,
                    (string)
                    $request
                        ->user()
                        ->id
                );
        } catch (
            RuntimeException $exception
        ) {
            return back()
                ->withErrors([
                    'status' => $exception
                        ->getMessage(),
                ]);
        }

        return back()->with(
            'success',
            'Locker asignado correctamente.'
        );
    }
}
