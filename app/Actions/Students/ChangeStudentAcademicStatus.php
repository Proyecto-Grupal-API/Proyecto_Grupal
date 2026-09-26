<?php

namespace App\Actions\Students;

use App\Enums\StudentStatus;
use App\Events\StudentProfileChanged;
use App\Models\AcademicStatusHistory;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\ExecutesMongoAtomically;
use Illuminate\Validation\ValidationException;

class ChangeStudentAcademicStatus
{
    use ExecutesMongoAtomically;

    public function execute(StudentProfile $profile, string $status, string $reason, User $actor): StudentProfile
    {
        $next = StudentStatus::tryFrom($status);
        if (! $next) {
            throw ValidationException::withMessages(['status' => 'El estado académico no es válido.']);
        }

        return $this->mongoTransaction(function () use ($profile, $next, $reason, $actor): StudentProfile {
            $profile->refresh();
            $previous = $profile->academic_status?->value;
            if ($previous === $next->value) {
                return $profile;
            }

            $profile->academic_status = $next;
            $profile->save();
            AcademicStatusHistory::create([
                'student_profile_id' => (string) $profile->getKey(),
                'from_status' => $previous,
                'to_status' => $next->value,
                'reason' => $reason,
                'changed_by' => (string) $actor->getKey(),
                'changed_at' => now(),
            ]);
            StudentProfileChanged::dispatch((string) $profile->user_id, 'academic_status_changed', ['academic_status'], (string) $actor->getKey());

            return $profile->fresh();
        });
    }
}
