<?php

namespace App\Services\StudentServices\Lockers;

use App\Models\StudentServices\Lockers\Locker;
use App\Models\StudentServices\Lockers\LockerAssignment;
use App\Models\StudentServices\Lockers\LockerPeriod;
use App\Models\StudentServices\Lockers\LockerRequest;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use RuntimeException;

class LockerRequestService
{
    public function __construct(
        private readonly LockerAssignmentService $assignments
    ) {}

    public function createPaidRequest(
        string $studentId,
        string $periodId,
        string $size,
        ?string $building = null
    ): LockerRequest {
        $period =
            LockerPeriod::find(
                $periodId
            );

        if ($period === null) {
            throw new RuntimeException(
                'El periodo seleccionado no existe.'
            );
        }

        $this->assignments
            ->ensurePeriodOpen(
                $period
            );

        if (
            ! in_array(
                $size,
                Locker::SIZES,
                true
            )
        ) {
            throw new RuntimeException(
                'El tamaño de locker no es válido.'
            );
        }

        if (
            $this->assignments
                ->studentHasOpenService(
                    $studentId,
                    $periodId
                )
        ) {
            throw new RuntimeException(
                'Ya tienes un locker o una solicitud en curso para este periodo.'
            );
        }

        if (
            $this->assignments
                ->findAvailableLocker(
                    $size,
                    $building
                ) === null
        ) {
            throw new RuntimeException(
                'No hay lockers disponibles con ese tamaño y ubicación.'
            );
        }

        $price =
            $period->priceCentsFor(
                $size
            );

        if ($price === null) {
            throw new RuntimeException(
                'El periodo no tiene costo definido para ese tamaño.'
            );
        }

        return LockerRequest::create([
            'folio' => $this->newFolio(),

            'student_id' => $studentId,

            'period_id' => $period->id,

            'locker_id' => null,

            'preferred_size' => $size,

            'preferred_building' => $building !== ''
                    ? $building
                    : null,

            'request_type' => 'paid',

            'status' => 'pending',

            'amount_cents' => $price,

            'payment_reference' => null,

            'paid_at' => null,

            'reviewed_by' => null,

            'reviewed_at' => null,

            'notes' => null,
        ]);
    }

    public function confirmPayment(
        LockerRequest $request,
        string $paymentReference,
        ?string $confirmedBy = null
    ): ?LockerAssignment {
        if (
            $request->request_type !==
            'paid'
        ) {
            throw new RuntimeException(
                'Solo las solicitudes de reserva pagada requieren pago.'
            );
        }

        $referenceUsed =
            LockerRequest::query()
                ->where(
                    'payment_reference',
                    $paymentReference
                )
                ->exists();

        if ($referenceUsed) {
            throw new RuntimeException(
                'Esa referencia de pago ya fue utilizada.'
            );
        }

        $moved =
            LockerRequest::query()
                ->where(
                    '_id',
                    new ObjectId(
                        (string)
                        $request->id
                    )
                )
                ->where(
                    'status',
                    'pending'
                )
                ->update([
                    'status' => 'paid',

                    'payment_reference' => $paymentReference,

                    'paid_at' => new UTCDateTime(
                        now()
                    ),
                ]);

        if ($moved === 0) {
            throw new RuntimeException(
                'La solicitud ya fue procesada o no está pendiente de pago.'
            );
        }

        $request->refresh();

        $locker =
            $this->assignments
                ->findAvailableLocker(
                    (string)
                    $request
                        ->preferred_size,

                    $request
                        ->preferred_building
                );

        if ($locker === null) {
            return null;
        }

        try {
            return $this->assignments
                ->assign(
                    $request,
                    $locker,
                    $confirmedBy
                );
        } catch (RuntimeException) {
            return null;
        }
    }

    public function cancel(
        LockerRequest $request
    ): void {
        if (
            $request->status ===
            'paid'
        ) {
            throw new RuntimeException(
                'Una solicitud pagada requiere reembolso; consúltalo con caja.'
            );
        }

        if (
            $request->status !==
            'pending'
        ) {
            throw new RuntimeException(
                'Solo se pueden cancelar solicitudes pendientes.'
            );
        }

        $moved =
            LockerRequest::query()
                ->where(
                    '_id',
                    new ObjectId(
                        (string)
                        $request->id
                    )
                )
                ->where(
                    'status',
                    'pending'
                )
                ->update([
                    'status' => 'cancelled',
                ]);

        if ($moved === 0) {
            throw new RuntimeException(
                'La solicitud ya fue procesada.'
            );
        }
    }

    private function newFolio(): string
    {
        return 'LKR-REQ-'
            .now()->format(
                'Ymd'
            )
            .'-'
            .Str::upper(
                Str::random(5)
            );
    }
}
