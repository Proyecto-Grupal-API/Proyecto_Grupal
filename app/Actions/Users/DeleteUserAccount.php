<?php

namespace App\Actions\Users;

use App\Enums\NfcCardStatus;
use App\Events\CredentialChanged;
use App\Models\Device;
use App\Models\NfcCard;
use App\Models\QrToken;
use App\Models\User;
use App\Models\UserSession;
use App\Support\ExecutesMongoAtomically;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DeleteUserAccount
{
    use ExecutesMongoAtomically;

    public function execute(User $user): void
    {
        $this->mongoTransaction(function () use ($user): void {
            $userId = (string) $user->getKey();
            $now = now();

            // Claim the account once. Every subsequent write participates in
            // the same Mongo transaction, so a failure restores this claim.
            $claimed = User::query()
                ->whereKey($userId)
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => $now,
                    'password' => null,
                    'remember_token' => null,
                    'two_factor_secret' => null,
                    'two_factor_recovery_codes' => null,
                    'two_factor_confirmed_at' => null,
                ]);

            if ($claimed !== 1) {
                if (User::withTrashed()->whereKey($userId)->whereNotNull('deleted_at')->exists()) {
                    return;
                }

                throw new RuntimeException('La cuenta cambió durante la solicitud de baja.');
            }

            QrToken::where('user_id', $userId)
                ->whereNull('revoked_at')
                ->whereNull('consumed_at')
                ->where('expires_at', '>', $now)
                ->update(['revoked_at' => $now]);

            foreach (NfcCard::where('user_id', $userId)
                ->whereIn('status', [NfcCardStatus::Active->value, NfcCardStatus::Suspended->value])
                ->get() as $card) {
                $previous = (string) $card->status;
                $updated = NfcCard::whereKey($card->getKey())
                    ->where('status', $previous)
                    ->update(['status' => NfcCardStatus::Blocked->value, 'blocked_at' => $now]);

                if ($updated !== 1) {
                    throw new RuntimeException('Una credencial NFC cambió durante la baja de cuenta.');
                }

                $card->credentialEvents()->create([
                    'performed_by' => $userId,
                    'event_type' => 'blocked',
                    'reason' => 'Baja de cuenta del propietario',
                    'previous_status' => $previous,
                    'new_status' => NfcCardStatus::Blocked->value,
                ]);

                CredentialChanged::dispatch(
                    (string) $card->getKey(),
                    'nfc',
                    'status_changed',
                    NfcCardStatus::Blocked->value,
                    $userId,
                );
            }

            Device::where('user_id', $userId)->update(['is_trusted' => false, 'revoked_at' => $now]);
            UserSession::where('user_id', $userId)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'revoked_reason' => 'account_deleted']);

            DB::connection('mongodb')->table('password_reset_tokens')
                ->where('email', (string) $user->email)
                ->delete();
        });
    }
}
