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
        'origin', 'source_key', 'source_fingerprint',
    ];

    protected function identityFields(): array
    {
        return ['user_id', 'role_key', 'scope_type', 'scope_id'];
    }

    protected function validateContext(): void
    {
        if (array_key_exists($this->role_key, Role::PILOT_ROLE_SCOPES)) {
            self::assertPilotGrantEnvironment();
        }
        // Wallet identifiers are trusted E2 public UUIDs, never business identifiers.
        if ($this->scope_type === 'wallet' && (! in_array($this->role_key,
            ['financial_refund_reviewer', 'financial_refund_executor'], true)
            || ! is_string($this->scope_id) || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $this->scope_id))) {
            throw ValidationException::withMessages(['scope_id' => 'Se requiere la wallet UUID canónica acreditada por Finanzas.']);
        }
        $this->scope_type ??= null;
        $this->scope_id ??= null;
        if ($this->origin === 'legacy_backfill') {
            Validator::make($this->getAttributes(), [
                'role_key' => ['required', Rule::in(Role::VALID_ROLES)],
                'scope_type' => ['nullable', Rule::in(Role::VALID_SCOPE_TYPES)],
                'source_key' => ['required', 'regex:/^[a-f0-9]{64}$/'],
                'source_fingerprint' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            ])->validate();
            // An absent historical timestamp is unknown, not the time of migration.
        } else {
            $this->assigned_at ??= now();
            if ($this->source_key !== null || $this->source_fingerprint !== null) {
                throw ValidationException::withMessages(['origin' => 'La procedencia legacy requiere un origen explícito.']);
            }
        }
        Validator::make($this->getAttributes(), [
            'role_key' => ['required', Rule::in(Role::AUTHORIZATION_ROLES)],
            'scope_type' => ['nullable', Rule::in(Role::FOUNDATION_SCOPE_TYPES)],
        ])->validate();
        if (! Role::where('name', $this->role_key)->exists()) {
            throw ValidationException::withMessages(['role_key' => 'El rol debe existir en el catálogo.']);
        }
        if (($this->scope_type === null) !== ($this->scope_id === null)) {
            throw ValidationException::withMessages(['scope_id' => 'El contexto requiere tipo e identificador.']);
        }
        if (array_key_exists($this->role_key, Role::AUTHORIZATION_ROLE_SCOPES)
            && $this->scope_type !== Role::AUTHORIZATION_ROLE_SCOPES[$this->role_key]) {
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

    public function transitionTo(string $status, mixed $actorId = null, ?string $reason = null): void
    {
        if (array_key_exists($this->role_key, Role::PILOT_ROLE_SCOPES)) {
            self::assertPilotGrantEnvironment();
        }
        parent::transitionTo($status, $actorId, $reason);
    }

    /** No production writer for these pilot authorities, including direct model creation. */
    public static function assertPilotGrantEnvironment(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'mongodb'
            || config('database.connections.mongodb.database') !== 'campus_virtual_testing'
            || \Illuminate\Support\Facades\DB::connection('mongodb')->getDatabaseName() !== 'campus_virtual_testing') {
            throw new \Illuminate\Auth\Access\AuthorizationException('Pilot grant authority has not been institutionally approved.');
        }
    }
}
