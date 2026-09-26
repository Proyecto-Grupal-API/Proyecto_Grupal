<?php

namespace App\Actions\Students;

use App\Enums\StudentStatus;
use App\Events\StudentProfileChanged;
use App\Models\AcademicStatusHistory;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\ExecutesMongoAtomically;
use App\Support\StudentIdentityInput;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class UpsertStudentProfile
{
    use ExecutesMongoAtomically;

    public function execute(array $data, ?User $student = null, ?User $actor = null): User
    {
        $data = StudentIdentityInput::normalize($data);
        $isNewStudent = ! ($student?->exists ?? false);
        $operation = $isNewStudent ? 'created' : 'updated';
        $student ??= new User;
        $oldPhotoPath = $student->studentProfile?->photo_path;
        $student->fill(Arr::only($data, ['name', 'email']));
        if ($isNewStudent) {
            $student->password = null;
            $student->account_activation_pending = true;
        }
        $photo = ($data['photo'] ?? null) instanceof UploadedFile ? $data['photo'] : null;
        $newPhotoPath = $photo?->store('student-photos', 'public');
        if ($photo && ! $newPhotoPath) {
            throw new RuntimeException('No se pudo guardar la fotografía del estudiante.');
        }
        $persist = function () use ($student, $data, $newPhotoPath, $operation, $actor, $isNewStudent): array {
            $student->save();

            $profile = $student->studentProfile ?? new StudentProfile(['user_id' => (string) $student->getKey()]);
            $isNewProfile = ! $profile->exists;
            $previousStatus = $profile->academic_status?->value;
            $profile->fill(Arr::only($data, [
                'enrollment_number', 'campus_id', 'academic_program_id', 'current_semester',
                'group_name', 'personal_email', 'phone',
                'preferred_contact_channel', 'locale',
            ]));
            if ($isNewProfile) {
                $profile->academic_status = $data['academic_status'];
            }
            if ($newPhotoPath) {
                $profile->photo_path = $newPhotoPath;
            }
            $changedFields = array_values(array_unique(array_merge(
                array_keys($student->getDirty()), array_keys($profile->getDirty())
            )));
            $nextStatus = StudentStatus::from($data['academic_status']);
            if ($isNewProfile) {
                $this->mongoTransaction(function () use ($profile, $previousStatus, $nextStatus, $data, $operation, $actor): void {
                    $profile->save();
                    AcademicStatusHistory::create([
                        'student_profile_id' => (string) $profile->getKey(),
                        'from_status' => $previousStatus,
                        'to_status' => $nextStatus->value,
                        'reason' => $data['status_reason'] ?? ($operation === 'created' ? 'Alta inicial' : 'Alta de perfil académico'),
                        'changed_by' => $actor?->getKey(),
                        'changed_at' => now(),
                    ]);
                });
            } else {
                $profile->save();
            }

            if (! $isNewProfile && $previousStatus !== $nextStatus->value) {
                app(ChangeStudentAcademicStatus::class)->execute(
                    $profile,
                    $nextStatus->value,
                    $data['status_reason'] ?? 'Actualización académica',
                    $actor ?? $student,
                );
            }
            if ($isNewStudent) {
                $student->assignRole(Role::ESTUDIANTE);
            }

            return $changedFields;
        };

        try {
            $this->mongoTransaction(function () use ($persist, $student, $operation, $actor): void {
                $changedFields = $persist();
                StudentProfileChanged::dispatch(
                    (string) $student->getKey(),
                    $operation,
                    $changedFields,
                    $actor?->getKey() ? (string) $actor->getKey() : null,
                );
            });
        } catch (Throwable $failure) {
            if ($newPhotoPath) {
                $this->deletePhotoSafely($newPhotoPath, 'compensación de operación fallida');
            }

            throw $failure;
        }

        $savedStudent = $student->fresh();
        if ($newPhotoPath && $oldPhotoPath && $oldPhotoPath !== $newPhotoPath) {
            $this->deletePhotoSafely($oldPhotoPath, 'reemplazo confirmado');
        }

        return $savedStudent;
    }

    private function deletePhotoSafely(string $path, string $reason): void
    {
        if (! str_starts_with($path, 'student-photos/') || str_contains($path, '..') || str_contains($path, '\\')) {
            $this->logPhotoCleanupFailure($reason, $path, 'Ruta fuera del directorio de fotografías.');

            return;
        }

        try {
            if (! Storage::disk('public')->delete($path)) {
                $this->logPhotoCleanupFailure($reason, $path, 'Storage no confirmó la eliminación.');
            }
        } catch (Throwable $failure) {
            $this->logPhotoCleanupFailure($reason, $path, $failure->getMessage());
        }
    }

    private function logPhotoCleanupFailure(string $reason, string $path, string $error): void
    {
        try {
            Log::warning('No se pudo eliminar una fotografía de estudiante.', [
                'reason' => $reason,
                'path' => $path,
                'error' => $error,
            ]);
        } catch (Throwable) {
            // Un error secundario de logging nunca reemplaza el error MongoDB original.
        }
    }
}
