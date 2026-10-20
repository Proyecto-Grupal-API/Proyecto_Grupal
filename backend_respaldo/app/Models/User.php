<?php

namespace App\Models;

use App\Support\Permissions;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use MongoDB\Laravel\Auth\User as Authenticatable;

/**
 * Usuario de la plataforma (colección `users` en MongoDB).
 *
 * `role` es la fuente de verdad para la autorización en el servidor; los permisos
 * efectivos se derivan de config/permissions.php (ver App\Support\Permissions).
 * El frontend solo recibe esa lista para mostrar/ocultar opciones: la API valida
 * cada operación por su cuenta.
 *
 * El `_id` (string) del usuario es el `student_id` usado por points_accounts,
 * points_ledger, redemptions, etc.
 */
class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    public const ROLE_STUDENT = 'student';
    public const ROLE_PROFESSOR = 'professor';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_PLATFORM_ADMIN = 'platform_admin';
    public const ROLE_AUDITOR = 'auditor';
    public const ROLE_SYSTEM = 'system';

    public const ROLES = [
        self::ROLE_STUDENT,
        self::ROLE_PROFESSOR,
        self::ROLE_ADMIN,
        self::ROLE_PLATFORM_ADMIN,
        self::ROLE_AUDITOR,
        self::ROLE_SYSTEM,
    ];

    protected $connection = 'mongodb';
    protected $collection = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
        'student_code',
        'department',
        'business_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'email_verified_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return ($this->status ?? 'active') === 'active';
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function permissions(): array
    {
        return Permissions::forRole((string) $this->role);
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /** Representación pública mínima (sin datos sensibles). */
    public function toPublicArray(): array
    {
        return [
            'id' => (string) $this->getKey(),
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->status ?? 'active',
            'student_code' => $this->student_code,
            'department' => $this->department,
            'business_id' => $this->business_id,
        ];
    }
}
