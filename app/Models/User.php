<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

class User extends Authenticatable
{
    protected $connection = 'mongodb';

    protected $collection = 'users';

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'account_activation_pending',
        'roles',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    protected $appends = [
        'two_factor_enabled',
    ];

    public function getTwoFactorEnabledAttribute(): bool
    {
        return ! is_null($this->two_factor_confirmed_at);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'account_activation_pending' => 'boolean',
        ];
    }

    protected function roles(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->normalizeRoles($value),
            set: fn ($value) => new BSONArray($this->normalizeRoles($value)),
        );
    }

    private function normalizeRoles(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $value = json_decode($value, true) ?: [];
        }

        if ($value instanceof BSONArray) {
            $value = $value->getArrayCopy();
        }

        $roles = $value instanceof \Traversable ? iterator_to_array($value) : (array) $value;

        return array_values(array_map(function (mixed $role): array {
            if ($role instanceof BSONDocument) {
                return $role->getArrayCopy();
            }

            return is_object($role) ? get_object_vars($role) : (array) $role;
        }, $roles));
    }

    public function studentProfile()
    {
        return $this->hasOne(StudentProfile::class, 'user_id');
    }

    /**
     * Safe identity contract for internal Campus Digital integrations.
     * It intentionally excludes credentials, recovery data, tokens, contact
     * details, and other private profile fields.
     */
    public function displayIdentity(): array
    {
        $this->loadMissing([
            'studentProfile.campus',
            'studentProfile.academicProgram',
        ]);

        $profile = $this->studentProfile;

        return [
            'user_id' => (string) $this->getKey(),
            'name' => $this->name,
            'student' => $profile ? [
                'enrollment_number' => $profile->enrollment_number,
                'campus' => $profile->campus ? [
                    'code' => $profile->campus->code,
                    'name' => $profile->campus->name,
                ] : null,
                'academic_program' => $profile->academicProgram ? [
                    'code' => $profile->academicProgram->code,
                    'name' => $profile->academicProgram->name,
                ] : null,
                'academic_status' => $profile->academic_status?->value,
            ] : null,
        ];
    }

    public function devices()
    {
        return $this->hasMany(Device::class, 'user_id');
    }

    public function userSessions()
    {
        return $this->hasMany(UserSession::class, 'user_id');
    }

    public function securityEvents()
    {
        return $this->hasMany(SecurityEvent::class, 'user_id');
    }

    public function consents()
    {
        return $this->hasMany(Consent::class, 'user_id');
    }

    public function communicationPreference()
    {
        return $this->hasOne(CommunicationPreference::class, 'user_id');
    }

    public function qrTokens()
    {
        return $this->hasMany(QrToken::class, 'user_id');
    }
    /**
     * Tarjetas NFC pertenecientes al usuario.
     */
    public function nfcCards()
    {
        return $this->hasMany(NfcCard::class, 'user_id');
    }

    /**
     * Tarjetas NFC registradas por el usuario.
     */
    public function registeredNfcCards()
    {
        return $this->hasMany(NfcCard::class, 'registered_by');
    }

    /**
     * Eventos del historial de credenciales realizados por el usuario.
     */
    public function credentialEvents()
    {
        return $this->hasMany(CredentialEvent::class, 'performed_by');
    }

    public function assignRole(string $roleName, ?string $scopeType = null, ?string $scopeId = null): void
    {
        // Defensa en profundidad: nunca persistir un rol que no exista en el
        // catálogo oficial, sin importar qué controlador llame a este método.
        if (! in_array($roleName, Role::VALID_ROLES, true)) {
            throw new \InvalidArgumentException("El rol '{$roleName}' no es un rol válido.");
        }

        $scopeId = $scopeId === null ? null : (string) $scopeId;

        if (($scopeType === null) !== ($scopeId === null)) {
            throw new \InvalidArgumentException('Una asignación de rol debe ser global o incluir tipo e identificador de contexto.');
        }

        if ($scopeType !== null && ! in_array($scopeType, Role::VALID_SCOPE_TYPES, true)) {
            throw new \InvalidArgumentException("El contexto '{$scopeType}' no es válido.");
        }

        if ($scopeId !== null && $scopeId === '') {
            throw new \InvalidArgumentException('El identificador de contexto no puede estar vacío.');
        }

        $roles = $this->roles ?? [];

        foreach ($roles as $role) {
            if (
                ($role['name'] ?? null) === $roleName &&
                ($role['scope_type'] ?? null) === $scopeType &&
                ($role['scope_id'] ?? null) === $scopeId
            ) {
                return;
            }
        }

        $roles[] = [
            'name' => $roleName,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'assigned_at' => now()->toDateTimeString(),
        ];

        $this->roles = $roles;
        $this->save();
    }

    public function hasRole(string $roleName, ?string $scopeType = null, ?string $scopeId = null): bool
    {
        $scopeId = $scopeId === null ? null : (string) $scopeId;

        if (($scopeType === null) !== ($scopeId === null)) {
            return false;
        }

        if ($scopeType !== null && ! in_array($scopeType, Role::VALID_SCOPE_TYPES, true)) {
            return false;
        }

        $roles = $this->roles ?? [];

        foreach ($roles as $role) {
            if (($role['name'] ?? null) !== $roleName) {
                continue;
            }

            $storedScopeType = $role['scope_type'] ?? null;
            $storedScopeId = ($role['scope_id'] ?? null) === null
                ? null
                : (string) $role['scope_id'];

            if ($scopeType === null && $storedScopeType === null && $storedScopeId === null) {
                return true;
            }

            if (
                $scopeType !== null &&
                $storedScopeType === $scopeType &&
                $storedScopeId === $scopeId
            ) {
                return true;
            }
        }

        return false;
    }
}
