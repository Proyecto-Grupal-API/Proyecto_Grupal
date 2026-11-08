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

    // Foundation catalog only: legacy assignment, UI and 2FA retain VALID_ROLES.
    public const NEW_ROLES = [
        'business_owner', 'business_manager', 'cashier', 'inventory_manager', 'buyer',
        'rewards_admin', 'auditor', 'organization_manager', 'career_coordinator', 'department_head',
    ];

    public const FOUNDATION_ROLES = [...self::VALID_ROLES, ...self::NEW_ROLES];

    // Domain catalogs are explicit: sharing a scope never implies sharing authority.
    public const BUSINESS_ROLES = ['business_owner', 'business_manager', 'cashier', 'inventory_manager', 'buyer'];

    // Technical financial policy; designation of institutional grant authorities is still pending.
    public const FINANCIAL_ROLE_SCOPES = [
        'bonus_beneficio_issuer' => null, 'bonus_categoria_issuer' => null,
        'bonus_negocio_issuer' => null, 'bonus_negocio_business_issuer' => 'business',
        'bonus_beca_issuer' => null, 'bonus_beca_program_issuer' => 'academic_program',
        'bonus_campania_issuer' => null,
    ];

    public const FINANCIAL_ROLES = [
        'bonus_beneficio_issuer', 'bonus_categoria_issuer', 'bonus_negocio_issuer',
        'bonus_negocio_business_issuer', 'bonus_beca_issuer', 'bonus_beca_program_issuer', 'bonus_campania_issuer',
    ];

    // Pilot definitions only: no seeder, legacy writer or productive grant authority.
    public const PILOT_ROLE_SCOPES = [
        'financial_refund_reviewer' => 'wallet',
        'financial_refund_executor' => 'wallet',
        'operador_servicios' => null,
    ];

    public const AUTHORIZATION_ROLES = [...self::FOUNDATION_ROLES, ...self::FINANCIAL_ROLES,
        'financial_refund_reviewer', 'financial_refund_executor', 'operador_servicios'];

    public const REWARDS_ROLE_SCOPES = [];

    public const FOUNDATION_SCOPE_TYPES = [...self::VALID_SCOPE_TYPES, 'campus', 'academic_program', 'department', 'wallet'];

    public const FOUNDATION_ROLE_SCOPES = [
        'business_owner' => 'business', 'business_manager' => 'business', 'cashier' => 'business',
        'inventory_manager' => 'business', 'buyer' => 'business',
        'rewards_admin' => null, 'auditor' => null,
        'organization_manager' => 'campus', 'career_coordinator' => 'academic_program',
        'department_head' => 'department',
    ];

    public const AUTHORIZATION_ROLE_SCOPES = [...self::FOUNDATION_ROLE_SCOPES, ...self::FINANCIAL_ROLE_SCOPES, ...self::PILOT_ROLE_SCOPES];

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
