<?php

namespace App\Models;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoleAssignment extends AuthorizationLifecycleRecord
{
    protected $collection = 'role_assignments';
    protected $fillable = [
        'user_id', 'role_key', 'scope_type', 'scope_id', 'campus_id', 'status',
        'starts_at', 'ends_at', 'assigned_at', 'assigned_by', 'reason',
    ];

    protected function identityFields(): array
    {
        return ['user_id', 'role_key', 'scope_type', 'scope_id'];
    }

    protected function validateContext(): void
    {
        $this->scope_type ??= null;
        $this->scope_id ??= null;
        $this->assigned_at ??= now();
        Validator::make($this->getAttributes(), [
            'role_key' => ['required', Rule::in(Role::FOUNDATION_ROLES)],
            'scope_type' => ['nullable', Rule::in(Role::FOUNDATION_SCOPE_TYPES)],
        ])->validate();
        if (! Role::where('name', $this->role_key)->exists()) {
            throw ValidationException::withMessages(['role_key' => 'El rol debe existir en el catálogo.']);
        }
        if (($this->scope_type === null) !== ($this->scope_id === null)) {
            throw ValidationException::withMessages(['scope_id' => 'El contexto requiere tipo e identificador.']);
        }
        if (array_key_exists($this->role_key, Role::FOUNDATION_ROLE_SCOPES)
            && $this->scope_type !== Role::FOUNDATION_ROLE_SCOPES[$this->role_key]) {
            throw ValidationException::withMessages(['scope_type' => 'Contexto incompatible con el rol.']);
        }
        if ($this->scope_type === 'department') {
            throw ValidationException::withMessages(['scope_type' => 'Las asignaciones de departamento esperan el catálogo institucional.']);
        }
        if ($this->scope_type === 'campus') {
            $campus = Campus::whereKey($this->scope_id)->first();
            if (! $campus) {
                throw ValidationException::withMessages(['scope_id' => 'Campus inexistente.']);
            }
            $this->scope_id = (string) $campus->getKey();
        }
        if ($this->scope_type === 'academic_program') {
            $program = AcademicProgram::whereKey($this->scope_id)->first();
            $campus = $program ? Campus::whereKey($program->campus_id)->first() : null;
            $requestedCampus = $this->campus_id === null ? null : Campus::whereKey($this->campus_id)->first();
            if (! $program || ! $campus
                || ($this->campus_id !== null && (! $requestedCampus || (string) $requestedCampus->getKey() !== (string) $campus->getKey()))) {
                throw ValidationException::withMessages(['scope_id' => 'Programa o campus incompatible.']);
            }
            $this->scope_id = (string) $program->getKey();
            $this->campus_id = (string) $campus->getKey();
        } elseif ($this->campus_id !== null) {
            throw ValidationException::withMessages(['campus_id' => 'Sólo el programa académico utiliza campus_id adicional.']);
        }
    }
}
