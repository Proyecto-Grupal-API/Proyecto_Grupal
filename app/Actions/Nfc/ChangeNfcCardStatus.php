<?php

namespace App\Actions\Nfc;

use App\Enums\NfcCardStatus;
use App\Events\CredentialChanged;
use App\Models\NfcCard;
use App\Support\ExecutesMongoAtomically;
use Illuminate\Validation\ValidationException;

class ChangeNfcCardStatus
{
    use ExecutesMongoAtomically;

    public function execute(NfcCard $card, NfcCardStatus $target, string $reason, string $actorId, bool $lost = false): void
    {
        $previous = NfcCardStatus::tryFrom((string) $card->status);

        if (! $previous || ! $previous->canTransitionTo($target) || ($lost && $target !== NfcCardStatus::Blocked)) {
            throw ValidationException::withMessages(['status' => 'La transición de la tarjeta no está permitida.']);
        }

        $this->mongoTransaction(function () use ($card, $previous, $target, $reason, $actorId, $lost): void {
            $updated = NfcCard::query()
                ->whereKey($card->getKey())
                ->where('status', $previous->value)
                ->update([
                    'status' => $target->value,
                    'blocked_at' => $target === NfcCardStatus::Blocked ? now() : null,
                ]);

            if ($updated !== 1) {
                throw ValidationException::withMessages(['status' => 'El estado de la tarjeta cambió; vuelve a cargarla.']);
            }

            $eventType = $lost ? 'lost' : match ($target) {
                NfcCardStatus::Active => 'reactivated',
                NfcCardStatus::Blocked => 'blocked',
                NfcCardStatus::Suspended => 'suspended',
                NfcCardStatus::Replaced => throw new \LogicException('El reemplazo requiere otra operación.'),
            };

            $card->credentialEvents()->create([
                'performed_by' => $actorId,
                'event_type' => $eventType,
                'reason' => $reason,
                'previous_status' => $previous->value,
                'new_status' => $target->value,
            ]);

            CredentialChanged::dispatch(
                (string) $card->getKey(),
                'nfc',
                $lost ? 'lost' : 'status_changed',
                $target->value,
                $actorId,
            );
        });
    }
}
