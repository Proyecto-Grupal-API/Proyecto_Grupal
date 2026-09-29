<?php

namespace App\Actions\Students;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\TemporaryPasswordGenerator;
use App\Support\ExecutesMongoAtomically;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ReissueTemporaryPassword
{
    use ExecutesMongoAtomically;

    public function __construct(private TemporaryPasswordGenerator $generator) {}

    public function execute(User $student, User $actor): StudentWithTemporaryPassword
    {
        if ($student->trashed() || ! $student->studentProfile()->exists()) {
            abort(404);
        }

        $temporaryPassword = $this->generator->generate();
        $this->mongoTransaction(function () use ($student, $actor, $temporaryPassword): void {
            $updated = User::query()
                ->whereKey($student->getKey())
                ->whereNull('deleted_at')
                ->where(function ($query): void {
                    $query->where('must_change_password', true)
                        ->orWhere(function ($legacy): void {
                            $legacy->where('account_activation_pending', true)->whereNull('password');
                        });
                })
                ->update([
                    'password' => Hash::make($temporaryPassword),
                    'account_activation_pending' => false,
                    'must_change_password' => true,
                    'remember_token' => Str::random(60),
                ]);
            if ($updated !== 1) {
                throw new ConflictHttpException('Esta cuenta no tiene una credencial inicial pendiente.');
            }

            SecurityEvent::log([
                'user_id' => (string) $student->getKey(),
                'type' => 'temporary_credential_reissued',
                'severity' => 'warning',
                'metadata' => ['issued_by' => (string) $actor->getKey()],
            ]);
        });

        return new StudentWithTemporaryPassword($student->fresh(), $temporaryPassword);
    }
}
