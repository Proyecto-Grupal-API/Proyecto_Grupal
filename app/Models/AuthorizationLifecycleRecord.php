<?php

namespace App\Models;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\Model;

/** Structural persistence only; these records do not authorize application access. */
abstract class AuthorizationLifecycleRecord extends Model
{
    protected $connection = 'mongodb';
    protected $attributes = ['status' => 'pending', 'is_current' => true, 'revision' => 1];
    protected $casts = [
        'is_current' => 'boolean', 'revision' => 'integer', 'generation' => 'integer',
        'starts_at' => 'datetime', 'ends_at' => 'datetime', 'assigned_at' => 'datetime',
        'joined_at' => 'datetime', 'suspended_at' => 'datetime', 'revoked_at' => 'datetime',
        'transitions' => \MongoDB\Laravel\Eloquent\Casts\AsBsonArray::class,
    ];

    public const STATUSES = ['pending', 'active', 'suspended', 'revoked'];
    private const TRANSITIONS = [
        'pending' => ['active', 'revoked'],
        'active' => ['suspended', 'revoked'],
        'suspended' => ['active', 'revoked'],
        'revoked' => [],
    ];

    abstract protected function identityFields(): array;
    abstract protected function validateContext(): void;

    protected static function booted(): void
    {
        static::saving(function (self $record): void {
            if ($record->exists) {
                throw ValidationException::withMessages(['status' => 'Use una transición condicional; el registro no admite edición directa.']);
            }
            foreach (['user_id', 'assigned_by', 'revoked_by', 'scope_id', 'business_id', 'campus_id'] as $field) {
                if ($record->getAttribute($field) !== null) {
                    $record->setAttribute($field, self::normalizeIdentifier($record->getAttribute($field), $field));
                }
            }
            foreach (['starts_at', 'ends_at'] as $field) {
                if ($record->getAttribute($field) === null) {
                    $record->setAttribute($field, null);
                }
            }
            Validator::make($record->getAttributes(), [
                'user_id' => ['required', 'string', 'max:100'],
                'status' => ['required', Rule::in(self::STATUSES)],
                'is_current' => ['required', 'boolean'],
                'revision' => ['required', 'integer', 'min:1'],
                'reason' => ['nullable', 'string', 'max:1000'],
            ])->validate();
            $record->validateUserReference('user_id');
            $record->validateUserReference('assigned_by');
            $record->validateUserReference('revoked_by');
            $record->validateContext();
            if (! in_array($record->status, ['pending', 'active'], true) || ! $record->is_current || $record->revision !== 1) {
                throw ValidationException::withMessages(['status' => 'Una nueva generación debe comenzar pending o active, current y revision 1.']);
            }
            if ($record->revoked_at !== null || $record->revoked_by !== null || $record->suspended_at !== null) {
                throw ValidationException::withMessages(['status' => 'Una generación nueva no tiene revocación ni suspensión previa.']);
            }
            if ($record->starts_at && $record->ends_at && $record->starts_at->gte($record->ends_at)) {
                throw ValidationException::withMessages(['ends_at' => 'El fin debe ser posterior al inicio.']);
            }
            $record->generation = ((int) $record->identityQuery()->max('generation')) + 1;
        });
        static::deleting(function (): void {
            throw ValidationException::withMessages(['status' => 'El historial se conserva; use revocación.']);
        });
    }

    public static function normalizeIdentifier(mixed $value, string $field): string
    {
        if (! is_string($value) && ! $value instanceof ObjectId) {
            throw ValidationException::withMessages([$field => 'Identificador inválido.']);
        }
        $value = trim((string) $value);
        if ($value === '' || strlen($value) > 100 || preg_match('/[\x00-\x20\x7f]/', $value)) {
            throw ValidationException::withMessages([$field => 'Identificador inválido.']);
        }

        return $value;
    }

    protected function validateUserReference(string $field): void
    {
        if ($this->getAttribute($field) !== null) {
            $user = User::whereKey($this->getAttribute($field))->first();
            if (! $user) {
                throw ValidationException::withMessages([$field => 'El usuario no existe o está eliminado.']);
            }
            $this->setAttribute($field, (string) $user->getKey());
        }
    }

    protected function identityQuery()
    {
        $query = static::query();
        foreach ($this->identityFields() as $field) {
            $query->where($field, $this->getAttribute($field));
        }

        return $query;
    }

    public function transitionTo(string $status, mixed $actorId = null, ?string $reason = null): void
    {
        if (! $this->exists || $this->isDirty() || ! $this->is_current
            || ! in_array($status, self::TRANSITIONS[$this->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'Transición inválida; una revocación requiere una nueva generación.']);
        }
        $actorId = $actorId === null ? null : self::normalizeIdentifier($actorId, 'actor_id');
        if ($actorId !== null) {
            $actor = User::whereKey($actorId)->first();
            if (! $actor) {
                throw ValidationException::withMessages(['actor_id' => 'El actor no existe o está eliminado.']);
            }
            $actorId = (string) $actor->getKey();
        }
        Validator::make(['reason' => $reason], ['reason' => ['nullable', 'string', 'max:1000']])->validate();
        $changes = ['status' => $status, 'revision' => $this->revision + 1, 'reason' => $reason,
            'transitions' => [...($this->transitions ?? []), [
                'from' => $this->status, 'to' => $status, 'actor_id' => $actorId,
                'reason' => $reason, 'at' => now()->toIso8601String(), 'revision' => $this->revision + 1,
            ]],
        ];
        if ($status === 'suspended') {
            $changes['suspended_at'] = now();
        }
        if ($status === 'revoked') {
            $changes += ['is_current' => false, 'revoked_at' => now(), 'revoked_by' => $actorId];
        }
        if (static::whereKey($this->getKey())->where('revision', $this->revision)
            ->where('status', $this->status)->where('is_current', true)->update($changes) !== 1) {
            throw ValidationException::withMessages(['status' => 'El estado cambió concurrentemente; vuelva a consultar.']);
        }
        $this->refresh();
    }
}
