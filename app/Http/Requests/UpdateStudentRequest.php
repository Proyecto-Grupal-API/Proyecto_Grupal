<?php

namespace App\Http\Requests;

use App\Enums\PreferredContactChannel;
use App\Models\AcademicProgram;
use App\Models\StudentProfile;
use App\Models\User;

class UpdateStudentRequest extends StoreStudentRequest
{
    public function authorize(): bool
    {
        $student = $this->route('student');
        return $student instanceof User && $student->studentProfile && ($this->user()?->can('update', $student->studentProfile) ?? false);
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'email' => ['required', 'email', 'max:255'],
        ]);
    }

    protected function validateBusinessRules(): void
    {
        validator([], [])->after(function ($validator): void {
            $student = $this->route('student');
            if (User::where('email', $this->input('email'))->where('_id', '!=', $student?->getKey())->exists()) {
                $validator->errors()->add('email', 'El correo institucional ya está registrado.');
            }
            if (StudentProfile::where('enrollment_number', $this->input('enrollment_number'))->where('_id', '!=', $student?->studentProfile?->getKey())->exists()) {
                $validator->errors()->add('enrollment_number', 'La matrícula ya está registrada.');
            }
            if (! AcademicProgram::where('_id', $this->input('academic_program_id'))->where('campus_id', $this->input('campus_id'))->exists()) {
                $validator->errors()->add('academic_program_id', 'La carrera no pertenece al campus seleccionado.');
            }
            if ($this->route('student')?->studentProfile?->academic_status?->value !== $this->input('academic_status') && ! $this->filled('status_reason')) {
                $validator->errors()->add('status_reason', 'Indica el motivo del cambio de estatus.');
            }
            if ($this->input('preferred_contact_channel') === PreferredContactChannel::PersonalEmail->value && ! $this->filled('personal_email')) {
                $validator->errors()->add('personal_email', 'Captura el correo personal seleccionado como canal preferido.');
            }
            if ($this->input('preferred_contact_channel') === PreferredContactChannel::Phone->value && ! $this->filled('phone')) {
                $validator->errors()->add('phone', 'Captura el teléfono seleccionado como canal preferido.');
            }
        })->validate();
    }
}
