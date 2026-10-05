<?php

namespace App\Domains\Financial\Services;

use App\Domains\Financial\Enums\BonusMovementType;
use App\Domains\Financial\Enums\BonusStatus;
use App\Domains\Financial\Enums\BonusType;
use App\Domains\Financial\Enums\BonusRestrictionType;
use App\Domains\Financial\Models\Bonus;
use App\Domains\Financial\Models\BonusLedgerEntry;
use App\Domains\Financial\Models\BonusRestriction;
use App\Domains\Financial\Contracts\BonusAuthorizationProvider;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class BonusService
{
    public function __construct(
        private readonly BonusAuthorizationProvider $bonusAuthorization
    ) {
    }

    public function issue(
    string $beneficiaryType,
    string $beneficiaryId,
    string $issuerType,
    string $issuerId,
    BonusType $type,
    int $amountCents,
    CarbonInterface $validFrom,
    CarbonInterface $expiresAt,
    bool $combinable = false,
    bool $allowsPartialUse = false,
    ?string $externalReference = null
    ): Bonus {
    if ($amountCents <= 0) {
        throw new InvalidArgumentException(
            'El monto del bono debe ser mayor que cero.'
        );
    }

    if ($beneficiaryType === '' || $beneficiaryId === '') {
        throw new InvalidArgumentException(
            'El beneficiario del bono es obligatorio.'
        );
    }

    if (!$this->bonusAuthorization->canIssueBonus(
        $issuerType,
        $issuerId,
        $type
    )) {
        throw new InvalidArgumentException(
            'El emisor no está autorizado para asignar este tipo de bono.'
        );
    }

    if ($issuerType === '' || $issuerId === '') {
        throw new InvalidArgumentException(
            'El emisor del bono es obligatorio.'
        );
    }

    if ($expiresAt->lessThanOrEqualTo($validFrom)) {
        throw new InvalidArgumentException(
            'La fecha de vencimiento debe ser posterior al inicio de vigencia.'
        );
    }

    return DB::connection('sqlsrv')->transaction(
    function () use (
        $beneficiaryType,
        $beneficiaryId,
        $issuerType,
        $issuerId,
        $type,
        $amountCents,
        $validFrom,
        $expiresAt,
        $combinable,
        $allowsPartialUse,
        $externalReference
    ): Bonus {
        $status = $validFrom->isFuture()
            ? BonusStatus::PENDIENTE
            : BonusStatus::ACTIVO;

        $bonus = Bonus::create([
            'public_id' => (string) Str::uuid(),
            'beneficiary_type' => $beneficiaryType,
            'beneficiary_id' => $beneficiaryId,
            'issuer_type' => $issuerType,
            'issuer_id' => $issuerId,
            'type' => $type,
            'original_amount_cents' => $amountCents,
            'remaining_amount_cents' => $amountCents,
            'currency' => 'MXN',
            'status' => $status,
            'valid_from' => $validFrom,
            'expires_at' => $expiresAt,
            'combinable' => $combinable,
            'allows_partial_use' => $allowsPartialUse,
            'external_reference' => $externalReference,
        ]);

        BonusLedgerEntry::create([
            'public_id' => (string) Str::uuid(),
            'bonus_id' => $bonus->public_id,
            'movement_type' => BonusMovementType::EMISION,
            'amount_cents' => $amountCents,
            'remaining_after_cents' => $amountCents,
            'reference_type' => 'BONUS_ISSUANCE',
            'reference_id' => $bonus->public_id,
            'actor_id' => $issuerId,
            'reason' => 'Emisión inicial del bono.',
        ]);

        return $bonus->fresh();
    }
);
}
public function consume(
    string $bonusPublicId,
    int $amountCents,
    ?string $businessId = null,
    ?string $categoryId = null
): Bonus
{
    if ($amountCents <= 0) {
        throw new InvalidArgumentException(
            'El monto a consumir debe ser mayor que cero.'
        );
    }

    $bonus = $this->expireIfNeeded(
        $bonusPublicId
    );

    if ($bonus->status === BonusStatus::EXPIRADO) {
        throw new InvalidArgumentException(
            'El bono ha expirado.'
        );
    }

    return DB::connection('sqlsrv')->transaction(
        function () use (
            $bonusPublicId,
            $amountCents,
            $businessId,
            $categoryId
        ): Bonus {
            $bonus = Bonus::where(
                'public_id',
                $bonusPublicId
            )
                ->lockForUpdate()
                ->first();

            if (!$bonus) {
                throw new InvalidArgumentException(
                    'El bono solicitado no existe.'
                );
            }

            if ($bonus->status === BonusStatus::CANCELADO) {
                throw new InvalidArgumentException(
                    'No se puede consumir un bono cancelado.'
                );
            }

            if ($bonus->status === BonusStatus::AGOTADO) {
                throw new InvalidArgumentException(
                    'No se puede consumir un bono agotado.'
                );
            }

           $temporalStatus = $this->resolveTemporalStatus($bonus);

           if ($temporalStatus === BonusStatus::PENDIENTE) {
               throw new InvalidArgumentException(
                   'El bono todavía no ha iniciado su vigencia.'
               );
           }

           if ($temporalStatus === BonusStatus::EXPIRADO) {
              throw new InvalidArgumentException(
                  'El bono ha expirado.'
               );
           }
      
           if (
               $bonus->status === BonusStatus::PENDIENTE
               && $temporalStatus === BonusStatus::ACTIVO
           ) {
               $bonus->status = BonusStatus::ACTIVO;
           }

           if ($amountCents > $bonus->remaining_amount_cents) {
               throw new InvalidArgumentException(
                   'El monto solicitado supera el saldo disponible del bono.'
               );
            }

            if (
                !$bonus->allows_partial_use
                && $amountCents < $bonus->remaining_amount_cents
            ) {
                throw new InvalidArgumentException(
                    'El bono no permite consumos parciales.'
                );
            }
           
             $this->validateRestrictions(
                 $bonus,
                 $businessId,
                 $categoryId
             );

          
            $newRemainingCents =
                $bonus->remaining_amount_cents - $amountCents;

            $bonus->remaining_amount_cents = $newRemainingCents;

            if ($newRemainingCents === 0) {
                $bonus->status = BonusStatus::AGOTADO;
            }

            $bonus->save();

            BonusLedgerEntry::create([
                'public_id' => (string) Str::uuid(),
                'bonus_id' => $bonus->public_id,
                'movement_type' => BonusMovementType::CONSUMO,
                'amount_cents' => -$amountCents,
                'remaining_after_cents' => $newRemainingCents,
                'reference_type' => 'BONUS_CONSUMPTION',
                'reference_id' => null,
                'actor_id' => null,
                'reason' => 'Consumo de saldo del bono.',
            ]);

            return $bonus->fresh();

        }
    );
}
public function consumeMultiple(
    array $consumptions,
    ?string $businessId = null,
    ?string $categoryId = null
): array
{
   if (count($consumptions) < 2) {
    throw new InvalidArgumentException(
        'La combinación requiere al menos dos bonos.'
    );
}

$bonusIds = [];

foreach ($consumptions as $consumption) {
    if (
        !isset($consumption['bonus_id'])
        || !isset($consumption['amount_cents'])
    ) {
        throw new InvalidArgumentException(
            'Cada consumo debe indicar bono y monto.'
        );
    }

    if ($consumption['amount_cents'] <= 0) {
        throw new InvalidArgumentException(
            'El monto a consumir debe ser mayor que cero.'
        );
    }

    $bonusIds[] = $consumption['bonus_id'];
}

if (count($bonusIds) !== count(array_unique($bonusIds))) {
    throw new InvalidArgumentException(
        'Un bono no puede repetirse en la misma combinación.'
    );
}

foreach ($bonusIds as $bonusPublicId) {
    $this->expireIfNeeded(
        $bonusPublicId
    );
}

    return DB::connection('sqlsrv')->transaction(
        function () use (
            $consumptions,
            $businessId,
            $categoryId,
            $bonusIds
        ): array {
            
            $sortedBonusIds = $bonusIds;
            sort($sortedBonusIds);

            $bonuses = [];

            foreach ($sortedBonusIds as $bonusPublicId) {
                $bonus = Bonus::where(
                    'public_id',
                    $bonusPublicId
                )
                    ->lockForUpdate()
                    ->first();

                if (!$bonus) {
                    throw new InvalidArgumentException(
                        'Uno de los bonos solicitados no existe.'
                    );
                }

                $bonuses[$bonus->public_id] = $bonus;
            }

            $firstBonus = $bonuses[
                $consumptions[0]['bonus_id']
            ];

            foreach ($bonuses as $bonus) {
                if (
                    $bonus->beneficiary_type
                        !== $firstBonus->beneficiary_type
                    || $bonus->beneficiary_id
                        !== $firstBonus->beneficiary_id
                ) {
                   throw new InvalidArgumentException(
                       'Todos los bonos deben pertenecer al mismo beneficiario.'
                    );
                }
             }         

            foreach ($consumptions as $consumption) {
    $bonus = $bonuses[
        $consumption['bonus_id']
    ];

    if (!$bonus->combinable) {
        throw new InvalidArgumentException(
            'Todos los bonos deben permitir combinación.'
        );
    }

    if ($bonus->status === BonusStatus::CANCELADO) {
        throw new InvalidArgumentException(
            'No se puede consumir un bono cancelado.'
        );
    }

    if ($bonus->status === BonusStatus::AGOTADO) {
        throw new InvalidArgumentException(
            'No se puede consumir un bono agotado.'
        );
    }

    $temporalStatus =
        $this->resolveTemporalStatus($bonus);

    if ($temporalStatus === BonusStatus::PENDIENTE) {
        throw new InvalidArgumentException(
            'El bono todavía no ha iniciado su vigencia.'
        );
    }

    if ($temporalStatus === BonusStatus::EXPIRADO) {
        throw new InvalidArgumentException(
            'El bono ha expirado.'
        );
    }

    $this->validateRestrictions(
        $bonus,
        $businessId,
        $categoryId
    );

    $amountCents =
        $consumption['amount_cents'];

    if (
        $amountCents
        > $bonus->remaining_amount_cents
    ) {
        throw new InvalidArgumentException(
            'El monto solicitado supera el saldo disponible del bono.'
        );
    }

    if (
        !$bonus->allows_partial_use
        && $amountCents
            < $bonus->remaining_amount_cents
    ) {
        throw new InvalidArgumentException(
            'El bono no permite consumos parciales.'
        );
    }
}

/*
 * Todos los bonos ya fueron validados.
 * A partir de aquí se aplican los cambios.
 */
foreach ($consumptions as $consumption) {
    $bonus = $bonuses[
        $consumption['bonus_id']
    ];

    $amountCents =
        $consumption['amount_cents'];

    if ($bonus->status === BonusStatus::PENDIENTE) {
        $bonus->status = BonusStatus::ACTIVO;
    }

    $newRemainingCents =
        $bonus->remaining_amount_cents
        - $amountCents;

    $bonus->remaining_amount_cents =
        $newRemainingCents;

    if ($newRemainingCents === 0) {
        $bonus->status =
            BonusStatus::AGOTADO;
    }

    $bonus->save();

    BonusLedgerEntry::create([
        'public_id' => (string) Str::uuid(),
        'bonus_id' => $bonus->public_id,
        'movement_type' =>
            BonusMovementType::CONSUMO,
        'amount_cents' => -$amountCents,
        'remaining_after_cents' =>
            $newRemainingCents,
        'reference_type' =>
            'BONUS_COMBINED_CONSUMPTION',
        'reference_id' => null,
        'actor_id' => null,
        'reason' =>
            'Consumo combinado de bonos.',
    ]);
}
            return Bonus::whereIn(
                'public_id',
                $bonusIds
            )->get()->all();
        }
    );
}
public function cancel(
    string $bonusPublicId,
    string $actorId,
    string $reason
): Bonus
{
    if (trim($actorId) === '') {
        throw new InvalidArgumentException(
            'El actor de la cancelación es obligatorio.'
        );
    }

    if (trim($reason) === '') {
        throw new InvalidArgumentException(
            'El motivo de la cancelación es obligatorio.'
        );
    }

    $bonus = $this->expireIfNeeded(
        $bonusPublicId
    );

    if ($bonus->status === BonusStatus::EXPIRADO) {
        throw new InvalidArgumentException(
            'No se puede cancelar un bono expirado.'
        );
    }

    return DB::connection('sqlsrv')->transaction(
        function () use (
            $bonusPublicId,
            $actorId,
            $reason
        ): Bonus {
            $bonus = Bonus::where(
                'public_id',
                $bonusPublicId
            )
                ->lockForUpdate()
                ->first();

            if (!$bonus) {
                throw new InvalidArgumentException(
                    'El bono solicitado no existe.'
                );
            }

            if ($bonus->status === BonusStatus::CANCELADO) {
                throw new InvalidArgumentException(
                    'El bono ya se encuentra cancelado.'
                );
            }

            if ($bonus->status === BonusStatus::AGOTADO) {
                throw new InvalidArgumentException(
                    'No se puede cancelar un bono agotado.'
                );
            }

            if ($bonus->status === BonusStatus::EXPIRADO) {
                throw new InvalidArgumentException(
                    'No se puede cancelar un bono expirado.'
                );
            }

            $remainingCents =
                $bonus->remaining_amount_cents;

            $bonus->status = BonusStatus::CANCELADO;
            $bonus->remaining_amount_cents = 0;
            $bonus->save();

            if ($remainingCents > 0) {
                BonusLedgerEntry::create([
                    'public_id' => (string) Str::uuid(),
                    'bonus_id' => $bonus->public_id,
                    'movement_type' => BonusMovementType::CANCELACION,
                    'amount_cents' => -$remainingCents,
                    'remaining_after_cents' => 0,
                    'reference_type' => 'BONUS_CANCELLATION',
                    'reference_id' => $bonus->public_id,
                    'actor_id' => $actorId,
                    'reason' => $reason,
                ]);
            }

            return $bonus->fresh();
        }
    );
}
public function expireIfNeeded(
    string $bonusPublicId
): Bonus
{
    return DB::connection('sqlsrv')->transaction(
        function () use (
            $bonusPublicId
        ): Bonus {
            $bonus = Bonus::where(
                'public_id',
                $bonusPublicId
            )
                ->lockForUpdate()
                ->first();

            if (!$bonus) {
                throw new InvalidArgumentException(
                    'El bono solicitado no existe.'
                );
            }

            if (
                $bonus->status === BonusStatus::CANCELADO
                || $bonus->status === BonusStatus::AGOTADO
             ) {
                return $bonus;
             }

           if ($this->resolveTemporalStatus($bonus) !== BonusStatus::EXPIRADO) {
              return $bonus;
           }

           if ($bonus->status === BonusStatus::EXPIRADO) {
              return $bonus;
           }

           $remainingCents = $bonus->remaining_amount_cents;

           $bonus->status = BonusStatus::EXPIRADO;
           $bonus->remaining_amount_cents = 0;
           $bonus->save();

           if ($remainingCents > 0) {
               BonusLedgerEntry::create([
                   'public_id' => (string) Str::uuid(),
                   'bonus_id' => $bonus->public_id,
                   'movement_type' => BonusMovementType::EXPIRACION,
                   'amount_cents' => -$remainingCents,
                   'remaining_after_cents' => 0,
                   'reference_type' => 'BONUS_EXPIRATION',
                   'reference_id' => $bonus->public_id,
                   'actor_id' => null,
                   'reason' => 'Expiración automática del bono.',
               ]);
           }

return $bonus->fresh();
        }
    );
}
private function validateRestrictions(
    Bonus $bonus,
    ?string $businessId,
    ?string $categoryId
): void
{
    $businessRestrictions = $bonus->restrictions()
        ->where(
            'restriction_type',
            BonusRestrictionType::NEGOCIO->value
        )
        ->get();

    if ($businessRestrictions->isNotEmpty()) {
        $businessAllowed = $businessRestrictions->contains(
            function (BonusRestriction $restriction) use ($businessId): bool {
                return $restriction->target_id === $businessId;
            }
        );

        if (!$businessAllowed) {
            throw new InvalidArgumentException(
                'El bono no puede utilizarse en este negocio.'
            );
        }
    }

    $categoryRestrictions = $bonus->restrictions()
        ->where(
            'restriction_type',
            BonusRestrictionType::CATEGORIA->value
        )
        ->get();

    if ($categoryRestrictions->isNotEmpty()) {
        $categoryAllowed = $categoryRestrictions->contains(
            function (BonusRestriction $restriction) use ($categoryId): bool {
                return $restriction->target_id === $categoryId;
            }
        );

        if (!$categoryAllowed) {
            throw new InvalidArgumentException(
                'El bono no puede utilizarse en esta categoría.'
            );
        }
    }
}
  private function resolveTemporalStatus(
    Bonus $bonus
): BonusStatus
{
    $now = now();

    if ($now->lt($bonus->valid_from)) {
        return BonusStatus::PENDIENTE;
    }

    if ($now->gte($bonus->expires_at)) {
        return BonusStatus::EXPIRADO;
    }

    return BonusStatus::ACTIVO;
}
public function addRestriction(
    string $bonusPublicId,
    BonusRestrictionType $restrictionType,
    string $targetId
): BonusRestriction
{
    if (trim($targetId) === '') {
        throw new InvalidArgumentException(
            'El objetivo de la restricción es obligatorio.'
        );
    }

    $bonus = Bonus::where(
        'public_id',
        $bonusPublicId
    )->first();

    if (!$bonus) {
        throw new InvalidArgumentException(
            'El bono solicitado no existe.'
        );
    }

    $restrictionExists = BonusRestriction::where(
        'bonus_id',
        $bonus->public_id
    )
    ->where(
        'restriction_type',
        $restrictionType->value
    )
        ->where(
            'target_id',
            $targetId
        )
        ->exists();

    if ($restrictionExists) {
        throw new InvalidArgumentException(
            'La restricción ya existe para este bono.'
        );
    }

    return BonusRestriction::create([
        'public_id' => (string) Str::uuid(),
        'bonus_id' => $bonus->public_id,
        'restriction_type' => $restrictionType,
        'target_id' => $targetId,
    ]);
}
}