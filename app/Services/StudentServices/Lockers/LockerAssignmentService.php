<?php

namespace App\Services\StudentServices\Lockers;

use App\Models\StudentServices\Lockers\Locker;
use App\Models\StudentServices\Lockers\LockerAssignment;
use App\Models\StudentServices\Lockers\LockerPeriod;
use App\Models\StudentServices\Lockers\LockerRequest;
use Illuminate\Support\Str;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\ObjectId;
use RuntimeException;
use Throwable;

class LockerAssignmentService
{
    public function ensurePeriodOpen(LockerPeriod $period): void
    {
        if ($period->status !== 'active') {
            throw new RuntimeException(
                'El periodo no está activo.'
            );
        }

        if ($period->ends_at !== null && $period->ends_at->isPast()) {
            throw new RuntimeException(
                'El periodo ya terminó.'
            );
        }
    }

    public function studentHasOpenService(
        string $studentId,
        string $periodId
    ): bool {
        $periodObjectId = new ObjectId($periodId);

        $hasAssignment = LockerAssignment::query()
            ->where('student_id', $studentId)
            ->where('period_id', $periodObjectId)
            ->where('status', 'active')
            ->exists();

        if ($hasAssignment) {
            return true;
        }

        return LockerRequest::query()
            ->where('student_id', $studentId)
            ->where('period_id', $periodObjectId)
            ->whereIn('status', ['pending', 'paid'])
            ->exists();
    }

    public function findAvailableLocker(
        string $size,
        ?string $building = null
    ): ?Locker {
        $query = Locker::query()
            ->where('status', 'available')
            ->where('size', $size);

        if ($building !== null && $building !== '') {
            $query->where('building', $building);
        }

        return $query
            ->orderBy('building')
            ->orderBy('zone')
            ->orderBy('code')
            ->first();
    }

    public function assign(
        LockerRequest $request,
        Locker $locker,
        ?string $assignedBy = null
    ): LockerAssignment {
        $period = LockerPeriod::find(
            (string) $request->period_id
        );

        if ($period === null) {
            throw new RuntimeException(
                'El periodo de la solicitud no existe.'
            );
        }

        $this->ensurePeriodOpen($period);

        $expectedStatus =
            $request->request_type === 'paid'
                ? 'paid'
                : 'pending';

        if ($request->status !== $expectedStatus) {
            throw new RuntimeException(
                'La solicitud no está en un estado que permita asignar locker.'
            );
        }

        if (
            $request->request_type === 'paid'
            && $locker->size !== $request->preferred_size
        ) {
            throw new RuntimeException(
                'El locker no coincide con el tamaño pagado en la solicitud.'
            );
        }

        $lockerObjectId = new ObjectId(
            (string) $locker->id
        );

        $claimed = Locker::query()
            ->where('_id', $lockerObjectId)
            ->where('status', 'available')
            ->update([
                'status' => 'occupied',
            ]);

        if ($claimed === 0) {
            throw new RuntimeException(
                'El locker ya no está disponible.'
            );
        }

        $assignment = null;

        try {
            $assignment = LockerAssignment::create([
                'folio' => $this->newFolio(
                    'LKR-ASG'
                ),

                'locker_id' => $locker->id,

                'period_id' => $period->id,

                'student_id' =>
                    $request->student_id,

                'request_id' =>
                    $request->id,

                'source' =>
                    $request->request_type,

                'status' => 'active',

                'starts_at' =>
                    $period->starts_at->isFuture()
                        ? $period->starts_at->copy()
                        : now(),

                'ends_at' =>
                    $period->ends_at->copy(),

                'released_at' => null,

                'release_reason' => null,

                'renewal_count' => 0,

                'renewed_from_id' => null,

                'assigned_by' =>
                    $assignedBy,

                'notes' =>
                    $request->notes,
            ]);

            $request->update([
                'status' => 'assigned',

                'locker_id' =>
                    $locker->id,

                'reviewed_by' =>
                    $assignedBy,

                'reviewed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $assignment?->delete();

            Locker::query()
                ->where(
                    '_id',
                    $lockerObjectId
                )
                ->update([
                    'status' => 'available',
                ]);

            throw new RuntimeException(
                'No se pudo completar la asignación del locker. Intenta de nuevo.',
                0,
                $exception
            );
        }

        return $assignment;
    }

    public function createSponsored(
        string $studentId,
        LockerPeriod $period,
        string $type,
        ?Locker $locker,
        ?string $size,
        ?string $reference = null,
        ?string $assignedBy = null
    ): LockerAssignment {
        if (
            !in_array(
                $type,
                ['council', 'scholarship'],
                true
            )
        ) {
            throw new RuntimeException(
                'El tipo de asignación no es válido.'
            );
        }

        $this->ensurePeriodOpen($period);

        if (
            $this->studentHasOpenService(
                $studentId,
                (string) $period->id
            )
        ) {
            throw new RuntimeException(
                'El estudiante ya tiene un locker o una solicitud en curso para este periodo.'
            );
        }

        if ($locker === null) {
            if (
                $size === null
                || $size === ''
            ) {
                throw new RuntimeException(
                    'Indica un locker o un tamaño.'
                );
            }

            $locker =
                $this->findAvailableLocker(
                    $size
                )
                ?? throw new RuntimeException(
                'No hay lockers disponibles de ese tamaño.'
            );
        }

        $request = LockerRequest::create([
            'folio' =>
                $this->newFolio(
                    'LKR-REQ'
                ),

            'student_id' =>
                $studentId,

            'period_id' =>
                $period->id,

            'locker_id' => null,

            'preferred_size' =>
                $locker->size,

            'preferred_building' =>
                null,

            'request_type' =>
                $type,

            'status' => 'pending',

            'amount' =>
                new Decimal128(
                    '0.00'
                ),

            'payment_reference' =>
                null,

            'paid_at' => null,

            'reviewed_by' => null,

            'reviewed_at' => null,

            'notes' =>
                $reference,
        ]);

        try {
            return $this->assign(
                $request,
                $locker,
                $assignedBy
            );
        } catch (Throwable $exception) {
            $request->delete();

            throw $exception;
        }
    }

    public function renew(
        LockerAssignment $assignment,
        LockerPeriod $newPeriod,
        ?string $paymentReference = null,
        ?string $renewedBy = null
    ): LockerAssignment {
        if (
            $assignment->status !==
            'active'
        ) {
            throw new RuntimeException(
                'Solo una asignación activa se puede renovar.'
            );
        }

        $this->ensurePeriodOpen(
            $newPeriod
        );

        if (
            (string) $newPeriod->id ===
            (string) $assignment->period_id
        ) {
            throw new RuntimeException(
                'Selecciona un periodo distinto al actual para renovar.'
            );
        }

        if (
            $this->studentHasOpenService(
                $assignment->student_id,
                (string) $newPeriod->id
            )
        ) {
            throw new RuntimeException(
                'El estudiante ya tiene un locker o una solicitud en curso para el nuevo periodo.'
            );
        }

        $paymentReference =
            $paymentReference !== null
                ? trim(
                $paymentReference
            )
                : null;

        if (
            $assignment->source ===
            'paid'
        ) {
            if (
                $paymentReference === null
                || $paymentReference === ''
            ) {
                throw new RuntimeException(
                    'La renovación de una reserva pagada requiere una referencia de pago.'
                );
            }

            $referenceUsed =
                LockerAssignment::query()
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
        }

        $assignmentObjectId =
            new ObjectId(
                (string) $assignment->id
            );

        $closed =
            LockerAssignment::query()
                ->where(
                    '_id',
                    $assignmentObjectId
                )
                ->where(
                    'status',
                    'active'
                )
                ->update([
                    'status' =>
                        'released',

                    'released_at' =>
                        now(),

                    'release_reason' =>
                        'Renovado hacia el periodo '
                        .$newPeriod->code,
                ]);

        if ($closed === 0) {
            throw new RuntimeException(
                'La asignación ya no está activa.'
            );
        }

        try {
            return LockerAssignment::create([
                'folio' =>
                    $this->newFolio(
                        'LKR-ASG'
                    ),

                'locker_id' =>
                    $assignment->locker_id,

                'period_id' =>
                    $newPeriod->id,

                'student_id' =>
                    $assignment->student_id,

                'request_id' => null,

                'source' =>
                    $assignment->source,

                'status' => 'active',

                'starts_at' =>
                    $newPeriod
                        ->starts_at
                        ->isFuture()
                        ? $newPeriod
                        ->starts_at
                        ->copy()
                        : now(),

                'ends_at' =>
                    $newPeriod
                        ->ends_at
                        ->copy(),

                'released_at' => null,

                'release_reason' => null,

                'renewal_count' =>
                    ((int)
                    $assignment
                        ->renewal_count)
                    + 1,

                'renewed_from_id' =>
                    $assignment->id,

                'assigned_by' =>
                    $renewedBy,

                'notes' =>
                    $assignment->notes,

                'payment_reference' =>
                    $paymentReference,
            ]);
        } catch (Throwable $exception) {
            LockerAssignment::query()
                ->where(
                    '_id',
                    $assignmentObjectId
                )
                ->update([
                    'status' => 'active',

                    'released_at' =>
                        null,

                    'release_reason' =>
                        null,
                ]);

            throw new RuntimeException(
                'No se pudo completar la renovación. Intenta de nuevo.',
                0,
                $exception
            );
        }
    }

    public function release(
        LockerAssignment $assignment,
        string $reason,
        ?string $releasedBy = null
    ): void {
        if (
            $assignment->status !==
            'active'
        ) {
            throw new RuntimeException(
                'Solo una asignación activa se puede liberar.'
            );
        }

        $assignmentObjectId =
            new ObjectId(
                (string) $assignment->id
            );

        $released =
            LockerAssignment::query()
                ->where(
                    '_id',
                    $assignmentObjectId
                )
                ->where(
                    'status',
                    'active'
                )
                ->update([
                    'status' =>
                        'released',

                    'released_at' =>
                        now(),

                    'release_reason' =>
                        $reason,

                    'assigned_by' =>
                        $releasedBy
                        ?? $assignment
                            ->assigned_by,
                ]);

        if ($released === 0) {
            throw new RuntimeException(
                'La asignación ya no está activa.'
            );
        }

        Locker::query()
            ->where(
                '_id',
                new ObjectId(
                    (string)
                    $assignment->locker_id
                )
            )
            ->where(
                'status',
                'occupied'
            )
            ->update([
                'status' => 'available',
            ]);
    }

    public function validateAccess(
        string $code
    ): array {
        $normalized =
            Str::upper(
                trim($code)
            );

        $locker = Locker::query()
            ->where(
                'code',
                $normalized
            )
            ->orWhere(
                'qr_code',
                $normalized
            )
            ->first();

        if ($locker === null) {
            return [
                'granted' => false,

                'message' =>
                    'Código no reconocido.',
            ];
        }

        if (
            $locker->status !==
            'occupied'
        ) {
            return [
                'granted' => false,

                'locker_code' =>
                    $locker->code,

                'message' =>
                    'Este locker no tiene una asignación activa.',
            ];
        }

        $assignment =
            LockerAssignment::query()
                ->where(
                    'locker_id',
                    new ObjectId(
                        (string)
                        $locker->id
                    )
                )
                ->where(
                    'status',
                    'active'
                )
                ->first();

        if ($assignment === null) {
            return [
                'granted' => false,

                'locker_code' =>
                    $locker->code,

                'message' =>
                    'No se encontró una asignación activa para este locker.',
            ];
        }

        return [
            'granted' => true,

            'locker_code' =>
                $locker->code,

            'student_id' =>
                $assignment->student_id,

            'folio' =>
                $assignment->folio,

            'ends_at' =>
                $assignment
                    ->ends_at
                    ?->format(
                        'Y-m-d'
                    ),

            'message' =>
                'Acceso concedido.',
        ];
    }

    private function newFolio(
        string $prefix
    ): string {
        return $prefix
            .'-'
            .now()->format(
                'Ymd'
            )
            .'-'
            .Str::upper(
                Str::random(5)
            );
    }
}
