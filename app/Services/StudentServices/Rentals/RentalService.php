<?php

namespace App\Services\StudentServices\Rentals;

use App\Models\StudentServices\Rentals\Asset;
use App\Models\StudentServices\Rentals\AssetCondition;
use App\Models\StudentServices\Rentals\Rental;
use App\Services\PilotOperationAuthorization;
use App\Services\RecentTwoFactor;
use App\Services\StudentServices\Audit\ServiceAuditor;
use App\Services\StudentServices\Payments\Money;
use App\Services\StudentServices\Payments\PaymentException;
use App\Services\StudentServices\Payments\RentalDepositFinanceClient;
use App\Services\StudentServices\Payments\StudentPaymentGateway;
use App\Services\StudentServices\ServiceAuthority;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
        return $this->withAssetLock((string) $asset->id, fn () => $this->startRental(
            $asset->fresh() ?? throw new RuntimeException('El equipo ya no está disponible.'),
            trim($studentId), $dueDate, $notes,
        ));
    }

    private function startRental(Asset $asset, string $studentId, string $dueDate, ?string $notes): Rental
    {
        if ($studentId === '') {
            throw new RuntimeException(
                'El estudiante es obligatorio.'
            );
        }

        $dueAt = \App\Services\StudentServices\ServiceClock::parse(
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
            if ($existingRental->student_id === $studentId && $existingRental->deposit_status === 'pending') {
                return $this->recoverPendingDeposit($existingRental);
            }

            throw new RuntimeException(
                'Este equipo ya tiene una renta activa.'
            );
        }

        if ($asset->status !== 'available') {
            throw new RuntimeException('El equipo no se encuentra disponible.');
        }

        $deposit = $asset->depositCents();
        $ttl = config('integracion_servicios.finanzas_garantias.pilot_hold_ttl_seconds');
        $expiresAt = $deposit > 0 && app()->environment('testing') && is_int($ttl) && $ttl > 0
            ? now()->addSeconds($ttl)->startOfSecond()
            : null;

        $rental = null;
        $prepare = function () use ($asset, $studentId, $dueAt, $notes, $deposit, $expiresAt, &$rental) {
            if ($expiresAt !== null
                && Asset::whereKey((string) $asset->id)->where('status', 'available')->update(['status' => 'rented']) !== 1) {
                throw new RuntimeException('El equipo no se encuentra disponible.');
            }
            $rental = Rental::create([
                'asset_id' => $asset->id,
                'inventory_item_id' => $asset->inventory_item_id,
                'student_id' => $studentId,
                'requested_at' => now(),
                'due_at' => $dueAt,
                'returned_at' => null,
                'status' => 'active',
                'notes' => $notes,
                'deposit_cents' => $deposit,
                'deposit_status' => $deposit > 0 ? 'pending' : 'none',
                'deposit_hold_expires_at' => $expiresAt,
            ]);
            AssetCondition::create([
                'rental_id' => $rental->id,
                'asset_id' => $asset->id,
                'inventory_item_id' => $asset->inventory_item_id,
                'type' => 'checkout',
                'condition' => 'good',
                'notes' => 'Equipo asignado a la renta.',
                'recorded_at' => now(),
            ]);

            if ($expiresAt === null) {
                $asset->update(['status' => 'rented']);
            }

            return $rental;
        };

        try {
            $rental = $expiresAt === null ? $prepare() : $asset->getConnection()->transaction($prepare);
        } catch (Throwable $exception) {
            if ($expiresAt === null && $rental !== null) {
                $this->discardUnfundedRental($rental, $asset);
            }
            throw $exception;
        }

        if ($deposit <= 0) {
            return $rental->fresh();
        }
        if ($expiresAt !== null) {
            return $this->recoverPendingDeposit($rental);
        }

        try {
            $reference = $this->requestLegacyHold($rental);
        } catch (PaymentException $exception) {
            // The legacy gateway reports explicit rejections as PaymentException.
            $this->discardUnfundedRental($rental, $asset);
            throw $exception;
        }

        return $this->confirmHold($rental, $reference);
    }

    public function recoverDeposit(Rental $rental): Rental
    {
        return $this->withAssetLock((string) $rental->asset_id,
            fn () => $this->recoverPendingDeposit($rental->fresh() ?? throw new RuntimeException('La renta ya no está disponible.')));
    }

    private function recoverPendingDeposit(Rental $rental): Rental
    {
        if ($rental->deposit_status !== 'pending') {
            return $rental;
        }
        if ($rental->deposit_hold_expires_at === null) {
            return $this->confirmHold($rental, $this->requestLegacyHold($rental));
        }

        $expiresAt = $rental->deposit_hold_expires_at;
        $id = (string) $rental->id;
        $client = app(RentalDepositFinanceClient::class);
        $receipt = $expiresAt->isFuture()
            ? $client->hold($id, (string) $rental->student_id, $rental->deposit_cents, $expiresAt)
            : $client->lookup($id, (string) $rental->student_id, $rental->deposit_cents, $expiresAt);

        if (in_array($receipt['status'] ?? null, ['LIBERADA', 'EXPIRADA'], true)
            && Str::isUuid($receipt['closing_transaction_id'] ?? null)) {
            // Release the equipment only with positive evidence that Finance released the funds.
            $rental->getConnection()->transaction(function () use ($rental, $receipt) {
                Asset::whereKey((string) $rental->asset_id)->where('status', 'rented')->update(['status' => 'available']);
                $rental->update([
                    'status' => 'cancelled', 'deposit_status' => 'released',
                    'deposit_hold_reference' => $receipt['hold_id'],
                    'deposit_released_cents' => $rental->deposit_cents,
                    'deposit_settlement_reference' => $receipt['closing_transaction_id'],
                    'deposit_settled_at' => now(),
                ]);
            });

            return $rental->fresh();
        }
        if (($receipt['status'] ?? null) !== 'ACTIVA' || ! $expiresAt->isFuture()) {
            throw new PaymentException('Finanzas aún no confirma el estado final de la garantía. Conserva la misma renta y vuelve a consultar.');
        }

        return $this->confirmHold($rental, $receipt['hold_id']);
    }

    private function discardUnfundedRental(Rental $rental, Asset $asset): void
    {
        AssetCondition::where('rental_id', new ObjectId((string) $rental->id))->delete();
        $rental->delete();
        $asset->update(['status' => 'available']);
    }

    private function withAssetLock(string $assetId, callable $action): Rental
    {
        try {
            return Cache::lock('student-services:rental-asset:'.$assetId, 60)->block(5, $action);
        } catch (LockTimeoutException) {
            throw new RuntimeException('El equipo tiene otra operación en curso. Vuelve a intentarlo.');
        }
    }

    private function assertDepositIsResolved(Rental $rental): void
    {
        if ($rental->deposit_status === 'pending') {
            throw new PaymentException('La garantía está pendiente de confirmación. Recupera la retención de esta renta primero.');
        }
    }

    public function cancelRental(
        Rental $rental
    ): Rental {
        $rental = $this->refreshRentalStatus($rental);
        $this->assertDepositIsResolved($rental);

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
        ?int $damageChargeCents = null,
        ?Request $counterRequest = null,
    ): Rental {
        if ($rental->deposit_hold_expires_at !== null) {
            return $this->withAssetLock((string) $rental->asset_id, fn () => $this->closeReturn(
                $rental->fresh() ?? throw new RuntimeException('La renta ya no está disponible.'),
                $condition, $notes, $damageChargeCents, $counterRequest,
            ));
        }

        if ($counterRequest?->attributes->getBoolean('rental_damage_only')) {
            throw new RuntimeException('Tu permiso cubre cargos sobre garantías del piloto de Finanzas.');
        }

        return $this->closeReturn($rental, $condition, $notes, $damageChargeCents, $counterRequest);
    }

    private function closeReturn(Rental $rental, string $condition, ?string $notes, ?int $damageChargeCents, ?Request $counterRequest): Rental
    {
        $rental = $this->refreshRentalStatus($rental);
        $this->assertDepositIsResolved($rental);

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

        $charge = $this->damageChargeFor($rental, $condition, $damageChargeCents);
        $reason = 'Devolución: '.$condition;
        $pilot = $rental->deposit_hold_expires_at !== null;
        $operatorId = null;
        if ($pilot) {
            if (! app()->environment('testing') || config('financial_rental_deposit_pilot.enabled') !== true) {
                throw new PaymentException('La liquidación de garantías está disponible solo en el piloto de Finanzas.');
            }
            if ($rental->deposit_settlement_reason !== null) {
                if ($rental->deposit_settlement_reason !== $reason
                    || ($rental->deposit_status === 'held' && $rental->damage_charge_cents !== $charge)) {
                    throw new RuntimeException('Ya existe un cierre registrado. Reintenta con la condición y el importe originales.');
                }
                $charge = (int) $rental->damage_charge_cents;
            }
            $operatorId = $this->authorizePilotReturn($rental, $charge, $counterRequest);
        }

        $this->settleDeposit($rental, $charge, $reason, $pilot ? $condition : null, $operatorId);

        if ($pilot) {
            return $rental->getConnection()->transaction(fn () => $this->recordReturn($rental, $asset, $condition, $notes));
        }

        return $this->recordReturn($rental, $asset, $condition, $notes);
    }

    private function authorizePilotReturn(Rental $rental, int $charge, ?Request $request): string
    {
        $user = $request?->user('web');
        if (! $user || ! app(RecentTwoFactor::class)->hasActiveSession($request)) {
            throw new RuntimeException('La liquidación requiere una sesión activa en el mostrador.');
        }
        $operatorId = (string) $user->getAuthIdentifier();
        if ($operatorId === (string) $rental->student_id) {
            throw new RuntimeException('El titular no puede cerrar su propia garantía.');
        }
        if ($rental->deposit_settlement_operator_id !== null && $rental->deposit_settlement_operator_id !== $operatorId) {
            throw new RuntimeException('La liquidación pendiente pertenece a otro operador.');
        }
        if ($charge === 0) {
            if (! app(ServiceAuthority::class)->staff($user)) {
                throw new RuntimeException('La liberación sin cargo requiere la autorización del mostrador.');
            }

            return $operatorId;
        }

        $authorization = app(PilotOperationAuthorization::class);
        if ($authorization->allowsRentalDamage($request, (string) $rental->id, (string) $rental->deposit_hold_reference, $charge)) {
            return $operatorId;
        }
        if ($authorization->hasRentalDamageAuthority($request) && ! app(RecentTwoFactor::class)->isRecent($request)) {
            throw ValidationException::withMessages(['rental_two_factor' => 'Confirma tu segundo factor para ordenar el cargo por daños.']);
        }

        throw new RuntimeException('La liquidación requiere el permiso vigente de operador de servicios.');
    }

    private function recordReturn(Rental $rental, Asset $asset, string $condition, ?string $notes): Rental
    {
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

    private function requestLegacyHold(Rental $rental): string
    {
        return $this->payments->hold(
            (string) $rental->student_id, $rental->deposit_cents, 'rental_deposit',
            (string) $rental->id, 'rental:'.$rental->id.':deposit-hold',
        );
    }

    private function confirmHold(Rental $rental, string $reference): Rental
    {
        if (trim($reference) === '') {
            throw new PaymentException('Finanzas aún no confirma la referencia de la garantía.');
        }
        if (! $rental->update(['deposit_status' => 'held', 'deposit_hold_reference' => $reference])) {
            throw new PaymentException('La referencia sigue pendiente de guardar. Vuelve a consultar esta renta.');
        }

        return $rental->fresh();
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
        string $reason,
        ?string $pilotCondition = null,
        ?string $operatorId = null,
    ): void {
        $rental->refresh();
        if ($rental->deposit_status === 'held' && $rental->deposit_hold_expires_at !== null
            && ($pilotCondition === null || $operatorId === null)) {
            throw new PaymentException('La liquidación de una garantía de Finanzas requiere habilitar el flujo autorizado del mostrador.');
        }
        if ($rental->deposit_status === 'held' && $rental->deposit_settlement_reason === null) {
            Rental::whereKey($rental->getKey())->where('deposit_status', 'held')
                ->whereNull('deposit_settlement_reason')
                ->update([
                    'damage_charge_cents' => $rental->damage_charge_cents ?? $chargeCents,
                    'deposit_settlement_reason' => $reason,
                    ...($operatorId === null ? [] : ['deposit_settlement_operator_id' => $operatorId]),
                ]);
            $rental->refresh();
        }
        if ($rental->deposit_settlement_reason !== null && $rental->deposit_settlement_reason !== $reason) {
            throw new RuntimeException('Ya existe un cierre registrado con otra condición. Recupera la operación original.');
        }
        if ($rental->deposit_status !== 'held') {
            return;
        }

        $hold = (string) $rental->deposit_hold_reference;
        $charged = (int) $rental->damage_charge_cents;
        $released = (int) $rental->deposit_cents - $charged;
        if ($hold === '' || $charged < 0 || $released < 0) {
            throw new RuntimeException('Los datos de la garantía no corresponden a un cierre válido.');
        }
        $receipt = $pilotCondition === null
            ? $this->payments->settleDeposit($hold, $charged, $reason, 'rental:'.$rental->id.':deposit-settle')
            : app(RentalDepositFinanceClient::class)->settle((string) $rental->id, $hold,
                $charged === 0 ? 'good' : ($pilotCondition === 'lost' ? 'lost' : 'damaged'), $charged, (int) $rental->deposit_cents);
        $reference = $receipt['transaction_id'] ?? null;
        if (! is_string($reference) || trim($reference) === ''
            || ($receipt['hold_id'] ?? null) !== $hold
            || ($receipt['charged_cents'] ?? null) !== $charged || ($receipt['released_cents'] ?? null) !== $released) {
            throw new RuntimeException('Finanzas no confirmó un cierre que coincida con la garantía.');
        }

        $updated = Rental::whereKey($rental->getKey())->where('deposit_status', 'held')->update([
            'deposit_status' => $charged > 0 ? 'charged' : 'released',
            'deposit_settlement_reference' => $reference,
            'deposit_released_cents' => $released,
            'deposit_settled_at' => now(),
        ]);
        $rental->refresh();
        if ($updated === 0 && $rental->deposit_settlement_reference !== $reference) {
            throw new RuntimeException('La renta ya tiene otro cierre registrado.');
        }

        if ($updated && $charged > 0) {
            $this->auditor->record(
                'rental.deposit.charged',
                'rental',
                (string) $rental->id,
                ['deposit_status' => 'held', 'deposit_cents' => (int) $rental->deposit_cents],
                ['deposit_status' => 'charged', 'damage_charge_cents' => $charged,
                    'settlement_reference' => $reference],
                $reason,
                $operatorId,
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
        $rental = $rental->fresh() ?? throw new RuntimeException('La renta ya no está disponible.');
        $this->assertDepositIsResolved($rental);
        if (! in_array($rental->status, ['active', 'overdue'], true)) {
            throw new RuntimeException('Esta renta ya no admite entrega.');
        }
        if ($rental->picked_up_at === null) {
            if ($rental->deposit_hold_expires_at?->lte(now())) {
                throw new PaymentException('La retención venció antes de la entrega del equipo.');
            }
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
