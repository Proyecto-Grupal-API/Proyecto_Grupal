<?php

namespace App\Services;

use App\Models\StudentProfile;

class StudentStatusService
{
    /**
     * Internal Team 1 contract: consumers pass the stable user identifier,
     * never a StudentProfile loaded from a Team 1 collection.
     */
    public function forUserId(string $userId, bool $includeHistory = false): array
    {
        $profile = StudentProfile::where('user_id', $userId)->firstOrFail();

        return $this->build($profile, $includeHistory);
    }

    private function build(StudentProfile $profile, bool $includeHistory = false): array
    {
        $profile->loadMissing(['user', 'campus', 'academicProgram']);
        $latest = $profile->statusHistory()->first();
        $status = $profile->academic_status;
        $data = [
            'student_id' => (string) $profile->user_id,
            'user_id' => (string) $profile->user_id,
            'name' => $profile->user?->name,
            'enrollment' => $profile->enrollment_number,
            'program' => $profile->academicProgram?->name,
            'semester' => $profile->current_semester,
            'campus' => $profile->campus?->name,
            'status' => $status?->value,
            'status_label' => $status?->label(),
            'effective_from' => $latest?->changed_at?->toISOString(),
            // The functional specification does not define benefit rules or a
            // structured restriction model. Expose the recorded status reason
            // without converting it into an eligibility decision.
            'status_reason' => $latest?->reason,
            'restrictions' => null,
            'benefits_eligible' => null,
        ];
        if ($includeHistory) {
            $data['history'] = $profile->statusHistory()->get()->map(fn ($item) => [
                'from_status' => $item->from_status?->value,
                'status' => $item->to_status?->value,
                'reason' => $item->reason,
                'actor_id' => $item->changed_by ? (string) $item->changed_by : null,
                'effective_from' => $item->changed_at?->toISOString(),
                'recorded_at' => $item->created_at?->toISOString(),
            ])->all();
        }
        return $data;
    }
}
