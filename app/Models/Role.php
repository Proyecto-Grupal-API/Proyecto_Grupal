<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Role extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'roles';

    protected $fillable = [
        'name',
        'display_name',
        'description',
    ];

    /**
     * Catálogo cerrado de roles válidos en la plataforma.
     *
     * Cualquier asignación de rol (App\Models\User::assignRole) debe
     * validarse contra este catálogo para evitar que se persistan
     * nombres de rol arbitrarios enviados desde el cliente.
     */
    public const ADMIN = 'admin';
    public const MAESTRO = 'maestro';
    public const ESTUDIANTE = 'estudiante';
    public const SERVICIO_CAFETERIA = 'servicio_cafeteria';
    public const CONSEJO_ESTUDIANTIL = 'consejo_estudiantil';
    public const STUDENT_MANAGER = 'student_manager';

    public const VALID_ROLES = [
        self::ADMIN,
        self::MAESTRO,
        self::ESTUDIANTE,
        self::SERVICIO_CAFETERIA,
        self::CONSEJO_ESTUDIANTIL,
        self::STUDENT_MANAGER,
    ];

    /**
     * Contextos admitidos por las asignaciones contextuales de roles.
     * Un contexto siempre debe tener tambien un identificador de alcance.
     */
    public const VALID_SCOPE_TYPES = [
        'business',
        'association',
        'service',
        'council',
    ];

    /**
     * Roles que, además de "admin", pueden gestionar el perfil y
     * ciclo de vida de estudiantes (alta, edición, importación).
     */
    public const STUDENT_MANAGEMENT_ROLES = [
        self::ADMIN,
        self::MAESTRO,
        self::STUDENT_MANAGER,
    ];
}
