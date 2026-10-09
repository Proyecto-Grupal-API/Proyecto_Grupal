<?php

namespace App\Services\StudentServices\Rentals;

use App\Models\StudentServices\Rentals\Asset;
use App\Models\StudentServices\Rentals\AssetCondition;
use App\Models\StudentServices\Rentals\Rental;
use Carbon\Carbon;
use MongoDB\BSON\ObjectId;
use RuntimeException;
use Throwable;

class RentalService
{
    private const RETURN_CONDITIONS = [
        'good',
        'damaged',
        'maintenance',
        'lost',
    ];

    public function createRental(
        Asset $asset,
        string $studentId,
        string $dueDate,
        ?string $notes = null
    ): Rental {
        $studentId = trim($studentId);

        if ($studentId === '') {
            throw new RuntimeException(
                'El estudiante es obligatorio.'
            );
        }

        if ($asset->status !== 'available') {
            throw new RuntimeException(
                'El equipo no se encuentra disponible.'
            );
        }

        $dueAt = Carbon::parse(
            $dueDate
        )->endOfDay();

        if ($dueAt->isPast()) {
            throw new RuntimeException(
                'La fecha de devolución debe ser posterior a la fecha actual.'
            );
        }

        $existingRental = Rental::query()
            ->where(
                'asset_id',
                new ObjectId(
                    (string) $asset->id
                )
            )
            ->whereIn(
                'status',
                [
                    'active',
                    'overdue',
                ]
            )
            ->first();

        if ($existingRental !== null) {
            throw new RuntimeException(
                'Este equipo ya tiene una renta activa.'
            );
        }

        $rental = null;

        try {
            $rental = Rental::create([
                'asset_id' =>
                    $asset->id,

                'inventory_item_id' =>
                    $asset->inventory_item_id,

                'student_id' =>
                    $studentId,

                'requested_at' =>
                    now(),

                'due_at' =>
                    $dueAt,

                'returned_at' =>
                    null,

                'status' =>
                    'active',

                'notes' =>
                    $notes,
            ]);

            AssetCondition::create([
                'rental_id' =>
                    $rental->id,

                'asset_id' =>
                    $asset->id,

                'inventory_item_id' =>
                    $asset->inventory_item_id,

                'type' =>
                    'checkout',

                'condition' =>
                    'good',

                'notes' =>
                    'Equipo entregado al estudiante.',

                'recorded_at' =>
                    now(),
            ]);

            $asset->update([
                'status' => 'rented',
            ]);

            return $rental->fresh();
        } catch (Throwable $exception) {
            if ($rental !== null) {
                AssetCondition::query()
                    ->where(
                        'rental_id',
                        new ObjectId(
                            (string) $rental->id
                        )
                    )
                    ->delete();

                $rental->delete();
            }

            $asset->update([
                'status' => 'available',
            ]);

            throw $exception;
        }
    }

    public function cancelRental(
        Rental $rental
    ): Rental {
        $this->refreshRentalStatus(
            $rental
        );

        if (
            !in_array(
                $rental->status,
                [
                    'active',
                    'overdue',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Esta renta ya no puede cancelarse.'
            );
        }

        $asset = Asset::find(
            (string) $rental->asset_id
        );

        $rental->update([
            'status' =>
                'cancelled',
        ]);

        if ($asset !== null) {
            $asset->update([
                'status' =>
                    'available',
            ]);
        }

        return $rental->fresh();
    }

    public function returnRental(
        Rental $rental,
        string $condition = 'good',
        ?string $notes = null
    ): Rental {
        $this->refreshRentalStatus(
            $rental
        );

        if (
            !in_array(
                $rental->status,
                [
                    'active',
                    'overdue',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Esta renta no puede registrarse como devuelta.'
            );
        }

        if (
            !in_array(
                $condition,
                self::RETURN_CONDITIONS,
                true
            )
        ) {
            throw new RuntimeException(
                'La condición del equipo no es válida.'
            );
        }

        $asset = Asset::find(
            (string) $rental->asset_id
        );

        if ($asset === null) {
            throw new RuntimeException(
                'No se encontró el equipo asociado a la renta.'
            );
        }

        AssetCondition::create([
            'rental_id' =>
                $rental->id,

            'asset_id' =>
                $asset->id,

            'inventory_item_id' =>
                $asset->inventory_item_id,

            'type' =>
                'return',

            'condition' =>
                $condition,

            'notes' =>
                $notes,

            'recorded_at' =>
                now(),
        ]);

        $rental->update([
            'status' =>
                'returned',

            'returned_at' =>
                now(),

            'notes' =>
                $notes ?? $rental->notes,
        ]);

        $newAssetStatus =
            $condition === 'good'
                ? 'available'
                : 'maintenance';

        $asset->update([
            'status' =>
                $newAssetStatus,
        ]);

        return $rental->fresh();
    }

    public function refreshRentalStatus(
        Rental $rental
    ): Rental {
        if (
            $rental->status === 'active' &&
            $rental->due_at !== null &&
            now()->greaterThan(
                $rental->due_at
            )
        ) {
            $rental->update([
                'status' =>
                    'overdue',
            ]);
        }

        return $rental->fresh();
    }

    public function refreshOverdueRentals(): void
    {
        $rentals = Rental::query()
            ->where(
                'status',
                'active'
            )
            ->get();

        foreach ($rentals as $rental) {
            $this->refreshRentalStatus(
                $rental
            );
        }
    }
}
