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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class LockerRequestController extends Controller
{
    public function __construct(
        private readonly LockerRequestService $requests,
        private readonly LockerAssignmentService $assignments
    ) {
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
                    ) =>
                    (string)
                    $period->id
                );

        $lockers =
            Locker::query()
                ->get()
                ->keyBy(
                    fn (
                        Locker $locker
                    ) =>
                    (string)
                    $locker->id
                );

        /*
         * Por ahora se muestran todas las solicitudes.
         * Cuando Equipo 1 entregue Roles/Policies,
         * se podrá limitar por tipo de usuario.
         */
        $requests =
            LockerRequest::query()
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
                        $lockers
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
                            'id' =>
                                (string)
                                $lockerRequest->id,

                            'folio' =>
                                $lockerRequest
                                    ->folio,

                            'student_id' =>
                                $lockerRequest
                                    ->student_id,

                            'period_id' =>
                                (string)
                                $lockerRequest
                                    ->period_id,

                            'period_name' =>
                                $period?->name
                                ??
                                'Periodo no encontrado',

                            'locker_id' =>
                                $lockerRequest
                                    ->locker_id
                                !== null
                                    ? (string)
                                $lockerRequest
                                    ->locker_id
                                    : null,

                            'locker_code' =>
                                $locker?->code,

                            'preferred_size' =>
                                $lockerRequest
                                    ->preferred_size,

                            'preferred_building' =>
                                $lockerRequest
                                    ->preferred_building,

                            'request_type' =>
                                $lockerRequest
                                    ->request_type,

                            'status' =>
                                $lockerRequest
                                    ->status,

                            'amount' =>
                                $lockerRequest
                                    ->amount
                                !== null
                                    ? (string)
                                $lockerRequest
                                    ->amount
                                    : null,

                            'payment_reference' =>
                                $lockerRequest
                                    ->payment_reference,

                            'paid_at' =>
                                $lockerRequest
                                    ->paid_at
                                    ?->format(
                                        'Y-m-d H:i'
                                    ),

                            'created_at' =>
                                $lockerRequest
                                    ->created_at
                                    ?->format(
                                        'Y-m-d H:i'
                                    ),

                            'notes' =>
                                $lockerRequest
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
                    ) =>
                        $period->status ===
                        'active'
                )
                ->map(
                    fn (
                        LockerPeriod $period
                    ) =>
                    $period->toPayload()
                )
                ->values();

        $availability =
            $lockers
                ->filter(
                    fn (
                        Locker $locker
                    ) =>
                        $locker->status ===
                        'available'
                )
                ->groupBy(
                    fn (
                        Locker $locker
                    ) =>
                        $locker->building
                        .'|'
                        .$locker->size
                )
                ->map(
                    fn ($group) => [
                        'building' =>
                            $group
                                ->first()
                                ->building,

                        'size' =>
                            $group
                                ->first()
                                ->size,

                        'count' =>
                            $group->count(),
                    ]
                )
                ->values();

        return Inertia::render(
            'student-services/lockers/Requests',
            [
                'requests' =>
                    $requests,

                'periods' =>
                    $activePeriods,

                'availability' =>
                    $availability,

                'available_lockers' =>
                    $lockers
                        ->filter(
                            fn (
                                Locker $locker
                            ) =>
                                $locker
                                    ->status
                                ===
                                'available'
                        )
                        ->sortBy(
                            fn (
                                Locker $locker
                            ) =>
                                $locker
                                    ->building
                                .$locker
                                    ->zone
                                .$locker
                                    ->code
                        )
                        ->map(
                            fn (
                                Locker $locker
                            ) =>
                            $locker
                                ->toPayload()
                        )
                        ->values(),

                'current_student_id' =>
                    (string)
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
                    'status' =>
                        $exception
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
                    'status' =>
                        $exception
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
                    'status' =>
                        $exception
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
                    ->validated()[
                'locker_id'
                ]
            );

        if ($locker === null) {
            return back()
                ->withErrors([
                    'locker_id' =>
                        'El locker seleccionado no existe.',
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
                    'status' =>
                        $exception
                            ->getMessage(),
                ]);
        }

        return back()->with(
            'success',
            'Locker asignado correctamente.'
        );
    }
}
