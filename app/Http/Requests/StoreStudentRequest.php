<?php

namespace App\Http\Requests;

use App\Enums\PreferredContactChannel;
use App\Enums\StudentStatus;
use App\Models\AcademicProgram;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\StudentIdentityInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(StudentIdentityInput::normalize($this->only(['email', 'personal_email', 'enrollment_number'])));
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', StudentProfile::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'enrollment_number' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9-]+$/'],
            'campus_id' => ['required', 'string'],
            'academic_program_id' => ['required', 'string'],
            'current_semester' => ['required', 'integer', 'between:1,20'],
            'group_name' => ['nullable', 'string', 'max:30'],
            'academic_status' => ['required', Rule::enum(StudentStatus::class)],
            'status_reason' => ['nullable', 'string', 'max:300'],
            'personal_email' => ['nullable', 'email', 'max:255', 'different:email'],
            'phone' => ['nullable', 'string', 'max:25', 'regex:/^[0-9+() .-]+$/'],
            'preferred_contact_channel' => ['required', Rule::enum(PreferredContactChannel::class)],
            'locale' => ['required', Rule::in(['es-MX', 'en-US'])],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    protected function passedValidation(): void
    {
        $this->validateBusinessRules();
    }

    protected function validateBusinessRules(): void
    {
        validator([], [])->after(function ($validator): void {
            if (User::where('email', $this->input('email'))->exists()) {
                $validator->errors()->add('email', 'El correo institucional ya está registrado.');
            }
            if (StudentProfile::where('enrollment_number', $this->input('enrollment_number'))->exists()) {
                $validator->errors()->add('enrollment_number', 'La matrícula ya está registrada.');
            }
            if (! AcademicProgram::where('_id', $this->input('academic_program_id'))->where('campus_id', $this->input('campus_id'))->exists()) {
                $validator->errors()->add('academic_program_id', 'La carrera no pertenece al campus seleccionado.');
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
