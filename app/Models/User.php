<?php

namespace App\Models;

use App\Services\EffectiveRoleAssignments;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use MongoDB\Laravel\Auth\User as Authenticatable;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

class User extends Authenticatable
{
    protected $connection = 'mongodb';

    protected $collection = 'users';

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

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
        'must_change_password',
        'roles',
    ];

    protected $appends = [
        'two_factor_enabled',
        'effective_roles',
    ];

    public function getTwoFactorEnabledAttribute(): bool
    {
        return ! is_null($this->two_factor_confirmed_at);
    }

    public function requiresTwoFactorAuthentication(): bool
    {
        $required = config('security.two_factor_required_roles', []);

        if (! is_array($required) || array_diff($required, Role::VALID_ROLES)) {
            throw new \LogicException('The required two-factor roles must belong to the canonical role catalog.');
        }

        foreach ($this->effectiveRoles() as $assignment) {
            if (in_array($assignment['name'] ?? null, $required, true)) {
                return true;
            }
        }

        return false;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'account_activation_pending' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    public function getMustChangePasswordAttribute(mixed $value): bool
    {
        // Historical Mongo documents do not have this field.
        return (bool) $value;
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
        app(EffectiveRoleAssignments::class)->assign($this, $roleName, $scopeType, $scopeId);
    }

    public function revokeRole(string $roleName, ?string $scopeType = null, ?string $scopeId = null): bool
    {
        return app(EffectiveRoleAssignments::class)->revoke($this, $roleName, $scopeType, $scopeId);
    }

    public function hasRole(string $roleName, ?string $scopeType = null, ?string $scopeId = null): bool
    {
        return app(EffectiveRoleAssignments::class)->has($this, $roleName, $scopeType, $scopeId);
    }

    public function effectiveRoles(): array
    {
        return app(EffectiveRoleAssignments::class)->display($this);
    }

    public function getEffectiveRolesAttribute(): array
    {
        return $this->effectiveRoles();
    }
}
