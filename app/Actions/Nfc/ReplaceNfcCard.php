<?php

namespace App\Actions\Nfc;

use App\Enums\NfcCardStatus;
use App\Events\CredentialChanged;
use App\Models\NfcCard;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\ExecutesMongoAtomically;
use App\Support\NfcUid;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReplaceNfcCard
{
    use ExecutesMongoAtomically;

    public function execute(NfcCard $old, string $uid, string $reason, string $actorId): NfcCard
    {
        $uid = NfcUid::normalize($uid);
        $previous = NfcCardStatus::tryFrom((string) $old->status);

        if (! in_array($previous, [NfcCardStatus::Active, NfcCardStatus::Blocked, NfcCardStatus::Suspended], true)
            || $old->replaced_by_card_id !== null) {
            throw ValidationException::withMessages(['status' => 'Esta tarjeta ya no puede reemplazarse.']);
        }

        if ($uid === NfcUid::normalize((string) $old->uid)) {
            throw ValidationException::withMessages(['uid' => 'Esta tarjeta NFC ya está registrada.']);
        }

        try {
            return $this->mongoTransaction(function () use ($old, $uid, $reason, $actorId, $previous): NfcCard {
                if (! User::whereKey((string) $old->user_id)->exists()) {
                    throw ValidationException::withMessages(['status' => 'El propietario de la tarjeta ya no existe.']);
                }

                if (! StudentProfile::where('user_id', (string) $old->user_id)->exists()) {
                    throw ValidationException::withMessages(['status' => 'La tarjeta no pertenece a un estudiante válido.']);
                }

                if (NfcCard::where('uid', 'regex', NfcUid::legacyMatch($uid))->exists()) {
                    throw ValidationException::withMessages(['uid' => 'Esta tarjeta NFC ya está registrada.']);
                }

                $now = now();
                $new = NfcCard::create([
                    'user_id' => (string) $old->user_id,
                    'uid' => $uid,
                    'registered_by' => $actorId,
                    'status' => $previous->value,
                    'registered_at' => $now,
                    'blocked_at' => $previous === NfcCardStatus::Blocked ? $now : null,
                    'replacement_of_card_id' => (string) $old->getKey(),
                ]);

                $updated = NfcCard::query()
                    ->whereKey($old->getKey())
                    ->where('user_id', (string) $old->user_id)
                    ->where('status', $previous->value)
                    ->whereNull('replaced_by_card_id')
                    ->update([
                        'status' => NfcCardStatus::Replaced->value,
                        'replaced_at' => $now,
                        'blocked_at' => null,
                        'replaced_by_card_id' => (string) $new->getKey(),
                    ]);

                if ($updated !== 1) {
                    throw ValidationException::withMessages(['status' => 'El estado de la tarjeta cambió; vuelve a cargarla.']);
                }

                $old->credentialEvents()->create([
                    'performed_by' => $actorId,
                    'event_type' => 'replaced',
                    'reason' => $reason,
                    'previous_status' => $previous->value,
                    'new_status' => NfcCardStatus::Replaced->value,
                ]);

                $new->credentialEvents()->create([
                    'performed_by' => $actorId,
                    'event_type' => 'registered',
                    'reason' => $reason,
                    'previous_status' => null,
                    'new_status' => $previous->value,
                ]);

                CredentialChanged::dispatch((string) $old->getKey(), 'nfc', 'status_changed', NfcCardStatus::Replaced->value, $actorId);
                CredentialChanged::dispatch((string) $new->getKey(), 'nfc', 'registered', $previous->value, $actorId);

                return $new;
            });
        } catch (Throwable $failure) {
            if ($this->isDuplicateFailure($failure, 'uid_1')) {
                throw ValidationException::withMessages(['uid' => 'Esta tarjeta NFC ya está registrada.']);
            }
            if ($this->isDuplicateFailure($failure, 'nfc_replacement_of_unique')) {
                throw ValidationException::withMessages(['status' => 'Esta tarjeta ya fue reemplazada; vuelve a cargarla.']);
            }

            throw $failure;
        }
    }

    private function isDuplicateFailure(Throwable $failure, string $index): bool
    {
        do {
            if ((int) $failure->getCode() === 11000 && str_contains($failure->getMessage(), $index)) {
                return true;
            }
            $failure = $failure->getPrevious();
        } while ($failure);

        return false;
    }
}
