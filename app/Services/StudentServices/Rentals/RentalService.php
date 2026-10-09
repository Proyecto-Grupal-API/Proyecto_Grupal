<?php

namespace App\Services\StudentServices\Rentals;

use App\Models\StudentServices\Rentals\Asset;
use App\Models\StudentServices\Rentals\AssetCondition;
use App\Models\StudentServices\Rentals\Rental;
use App\Services\StudentServices\Audit\ServiceAuditor;
use App\Services\StudentServices\Payments\Money;
use App\Services\StudentServices\Payments\StudentPaymentGateway;
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

    public function __construct(
        private StudentPaymentGateway $payments,
        private ServiceAuditor $auditor
    ) {}

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
                'asset_id' => $asset->id,

                'inventory_item_id' => $asset->inventory_item_id,

                'student_id' => $studentId,

                'requested_at' => now(),

                'due_at' => $dueAt,

                'returned_at' => null,

                'status' => 'active',

                'notes' => $notes,

                'deposit_cents' => $asset->depositCents(),

                'deposit_status' => 'none',
            ]);

            AssetCondition::create([
                'rental_id' => $rental->id,

                'asset_id' => $asset->id,

                'inventory_item_id' => $asset->inventory_item_id,

                'type' => 'checkout',

                'condition' => 'good',

                'notes' => 'Equipo entregado al estudiante.',

                'recorded_at' => now(),
            ]);

            $asset->update([
                'status' => 'rented',
            ]);

            /*
             * El depósito se retiene al final: si algo anterior falla no
             * queda dinero retenido de una renta que se va a borrar.
             */
            $this->holdDeposit(
                $rental,
                $asset
            );

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
            ! in_array(
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

        if ($rental->picked_up_at !== null) {
            throw new RuntimeException(
                'El equipo ya se entregó; la devolución se registra en el mostrador.'
            );
        }

        $asset = Asset::find(
            (string) $rental->asset_id
        );

        $this->settleDeposit(
            $rental,
            0,
            'Renta cancelada'
        );

        $rental->update([
            'status' => 'cancelled',
        ]);

        if ($asset !== null) {
            $asset->update([
                'status' => 'available',
            ]);
        }

        return $rental->fresh();
    }

    /**
     * @param  int|null  $damageChargeCents  Cargo por daños que se toma del
     *                                       depósito (solo con daño o
     *                                       mantenimiento; una pérdida cobra
     *                                       el depósito completo).
     */
    public function returnRental(
        Rental $rental,
        string $condition = 'good',
        ?string $notes = null,
        ?int $damageChargeCents = null
    ): Rental {
        $this->refreshRentalStatus(
            $rental
        );

        if (
            ! in_array(
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
            ! in_array(
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

        $this->settleDeposit(
            $rental,
            $this->damageChargeFor(
                $rental,
                $condition,
                $damageChargeCents
            ),
            'Devolución: '.$condition
        );

        AssetCondition::create([
            'rental_id' => $rental->id,

            'asset_id' => $asset->id,

            'inventory_item_id' => $asset->inventory_item_id,

            'type' => 'return',

            'condition' => $condition,

            'notes' => $notes,

            'recorded_at' => now(),
        ]);

        $rental->update([
            'status' => 'returned',

            'returned_at' => now(),

            'notes' => $notes ?? $rental->notes,
        ]);

        $newAssetStatus =
            $condition === 'good'
                ? 'available'
                : 'maintenance';

        $asset->update([
            'status' => $newAssetStatus,
        ]);

        return $rental->fresh();
    }

    /**
     * Retiene el depósito del equipo al entregarlo (5.7). Sin depósito
     * configurado no se retiene nada.
     */
    private function holdDeposit(
        Rental $rental,
        Asset $asset
    ): void {
        $deposit = $asset->depositCents();

        if ($deposit <= 0) {
            return;
        }

        $reference = $this->payments->hold(
            (string) $rental->student_id,
            $deposit,
            'rental_deposit',
            (string) $rental->id,
            'rental:'.$rental->id.':deposit-hold'
        );

        $rental->update([
            'deposit_status' => 'held',
            'deposit_hold_reference' => $reference,
        ]);
    }

    /**
     * Cuánto del depósito se cobra según cómo regresó el equipo.
     */
    private function damageChargeFor(
        Rental $rental,
        string $condition,
        ?int $damageChargeCents
    ): int {
        $deposit = (int) ($rental->deposit_cents ?? 0);
        $charge = max(0, (int) $damageChargeCents);

        /*
         * Un intento anterior ya cerró el depósito (por ejemplo, falló algo
         * después de cobrar). Se permite reintentar la devolución sin volver
         * a mover dinero.
         */
        if (in_array($rental->deposit_status, ['charged', 'released'], true)) {
            return 0;
        }

        if ($condition === 'lost') {
            return $deposit;
        }

        if ($charge === 0) {
            return 0;
        }

        if ($condition === 'good') {
            throw new RuntimeException(
                'Un equipo devuelto en buen estado no genera cargo por daños.'
            );
        }

        if ($rental->deposit_status !== 'held') {
            throw new RuntimeException(
                'Esta renta no tiene depósito retenido; el cargo por daños se cobrará cuando esté la API de cobros del Equipo 2.'
            );
        }

        if ($charge > $deposit) {
            throw new RuntimeException(
                'El cargo por daños no puede superar el depósito de $'.Money::toPesos($deposit).'.'
            );
        }

        return $charge;
    }

    /**
     * Cierra el depósito: cobra el cargo (si hay) y libera el resto.
     * Las llaves de idempotencia evitan cobrar dos veces si se reintenta.
     */
    private function settleDeposit(
        Rental $rental,
        int $chargeCents,
        string $reason
    ): void {
        if ($rental->deposit_status !== 'held') {
            return;
        }

        /*
         * El cargo se guarda antes de mover dinero: si un reintento llega
         * con otro importe, se respeta el primero (la llave de idempotencia
         * del cobro es una sola por renta).
         */
        if ($rental->damage_charge_cents !== null) {
            $chargeCents = (int) $rental->damage_charge_cents;
        } else {
            $rental->update(['damage_charge_cents' => $chargeCents]);
        }

        $hold = (string) $rental->deposit_hold_reference;

        if ($chargeCents > 0) {
            $this->payments->capture(
                $hold,
                $chargeCents,
                $reason,
                'rental:'.$rental->id.':deposit-capture'
            );
        }

        if ($chargeCents < (int) $rental->deposit_cents) {
            $this->payments->release(
                $hold,
                $reason,
                'rental:'.$rental->id.':deposit-release'
            );
        }

        $rental->update([
            'deposit_status' => $chargeCents > 0 ? 'charged' : 'released',
            'damage_charge_cents' => $chargeCents,
            'deposit_settled_at' => now(),
        ]);

        if ($chargeCents > 0) {
            $this->auditor->record(
                'rental.deposit.charged',
                'rental',
                (string) $rental->id,
                ['deposit_status' => 'held', 'deposit_cents' => (int) $rental->deposit_cents],
                ['deposit_status' => 'charged', 'damage_charge_cents' => $chargeCents],
                $reason
            );
        }
    }

    /**
     * Entrega física del equipo validada en el mostrador (5.11). Después de
     * esto el alumno ya no puede cancelar la renta.
     */
    public function markPickedUp(
        Rental $rental
    ): Rental {
        if ($rental->picked_up_at === null) {
            $rental->update([
                'picked_up_at' => now(),
            ]);
        }

        return $rental->fresh() ?? $rental;
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
                'status' => 'overdue',
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
