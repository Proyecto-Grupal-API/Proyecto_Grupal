<?php

namespace App\Http\Controllers\StudentServices\Lockers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentServices\Lockers\ReleaseLockerAssignmentRequest;
use App\Http\Requests\StudentServices\Lockers\RenewLockerAssignmentRequest;
use App\Http\Requests\StudentServices\Lockers\StoreSponsoredAssignmentRequest;
use App\Models\StudentServices\Lockers\Locker;
use App\Models\StudentServices\Lockers\LockerAssignment;
use App\Models\StudentServices\Lockers\LockerPeriod;
use App\Services\StudentServices\Lockers\LockerAssignmentService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class LockerAssignmentController extends Controller
{
    public function __construct(
        private readonly LockerAssignmentService $assignments
    ) {}

    public function index(): Response
    {
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

        $assignments =
            LockerAssignment::query()
                ->orderBy(
                    'created_at',
                    'desc'
                )
                ->get()
                ->map(
                    function (
                        LockerAssignment $assignment
                    ) use (
                        $periods,
                        $lockers
                    ) {
                        $period =
                            $periods->get(
                                (string)
                                $assignment
                                    ->period_id
                            );

                        $locker =
                            $lockers->get(
                                (string)
                                $assignment
                                    ->locker_id
                            );

                        return [
                            'id' => (string)
                                $assignment->id,

                            'folio' => $assignment
                                ->folio,

                            'student_id' => $assignment
                                ->student_id,

                            'locker_id' => (string)
                                $assignment
                                    ->locker_id,

                            'locker_code' => $locker->code
                                ??
                                'Locker no encontrado',

                            'locker_location' => $locker !== null
                                    ? $locker
                                        ->building
                                    .' - '
                                    .$locker
                                        ->zone
                                    : null,

                            'locker_size' => $locker?->size,

                            'period_id' => (string)
                                $assignment
                                    ->period_id,

                            'period_name' => $period->name
                                ??
                                'Periodo no encontrado',

                            'source' => $assignment
                                ->source,

                            'status' => $assignment
                                ->status,

                            'starts_at' => $assignment
                                ->starts_at
                                ?->format(
                                    'Y-m-d'
                                ),

                            'ends_at' => $assignment
                                ->ends_at
                                ?->format(
                                    'Y-m-d'
                                ),

                            'released_at' => $assignment
                                ->released_at
                                ?->format(
                                    'Y-m-d H:i'
                                ),

                            'renewal_count' => (int)
                                $assignment
                                    ->renewal_count,

                            'notes' => $assignment
                                ->notes,
                        ];
                    }
                )
                ->values();

        return Inertia::render(
            'student-services/lockers/Assignments',
            [
                'assignments' => $assignments,

                'periods' => $periods
                    ->filter(
                        fn (
                            LockerPeriod $period
                        ) => $period
                            ->status
                            ===
                            'active'
                    )
                    ->map(
                        fn (
                            LockerPeriod $period
                        ) => $period
                            ->toPayload()
                    )
                    ->values(),

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

                'summary' => [
                    'total' => $assignments
                        ->count(),

                    'active' => $assignments
                        ->where(
                            'status',
                            'active'
                        )
                        ->count(),

                    'released' => $assignments
                        ->where(
                            'status',
                            'released'
                        )
                        ->count(),

                    'expired' => $assignments
                        ->where(
                            'status',
                            'expired'
                        )
                        ->count(),
                ],
            ]
        );
    }

    public function storeSponsored(
        StoreSponsoredAssignmentRequest $request
    ): RedirectResponse {
        $data =
            $request->validated();

        $period =
            LockerPeriod::find(
                $request
                    ->string('period_id')
                    ->value()
            );

        if ($period === null) {
            return back()
                ->withErrors([
                    'period_id' => 'El periodo seleccionado no existe.',
                ]);
        }

        $locker = null;

        if (
            ! empty(
                $data['locker_id']
            )
        ) {
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
        }

        $reference = trim(
            (string)
            ($data['reference'] ?? '')
        );

        try {
            $this->assignments
                ->createSponsored(
                    trim(
                        $data['student_id']
                    ),

                    $period,

                    $data['request_type'],

                    $locker,

                    $data['size']
                    ?? null,

                    $reference === ''
                        ? null
                        : $reference,

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

    public function renew(
        RenewLockerAssignmentRequest $request,
        string $assignmentId
    ): RedirectResponse {
        $assignment =
            LockerAssignment::findOrFail(
                $assignmentId
            );

        $data =
            $request->validated();

        $newPeriod =
            LockerPeriod::find(
                $request
                    ->string('period_id')
                    ->value()
            );

        if ($newPeriod === null) {
            return back()
                ->withErrors([
                    'period_id' => 'El periodo seleccionado no existe.',
                ]);
        }

        try {
            $this->assignments
                ->renew(
                    $assignment,

                    $newPeriod,

                    $data[
                    'payment_reference'
                    ] ?? null,

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
            'Locker renovado correctamente.'
        );
    }

    public function release(
        ReleaseLockerAssignmentRequest $request,
        string $assignmentId
    ): RedirectResponse {
        $assignment =
            LockerAssignment::findOrFail(
                $assignmentId
            );

        try {
            $this->assignments
                ->release(
                    $assignment,

                    trim(
                        $request
                            ->validated()[
                        'reason'
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
            'Locker liberado correctamente.'
        );
    }
}
