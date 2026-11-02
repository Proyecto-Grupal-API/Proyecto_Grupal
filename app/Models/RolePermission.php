<?php

namespace App\Models;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use MongoDB\Laravel\Eloquent\Model;

class RolePermission extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'role_permissions';

    protected $fillable = ['role_key', 'permission_key'];

    // Stable catalog keys avoid coupling mappings to environment-specific ObjectIds.
    public const MAPPINGS = [
        'business_owner' => ['business.manage', 'business.members.manage', 'business.operate', 'business.sales.operate', 'business.inventory.operate'],
        'business_manager' => ['business.operate', 'business.sales.operate', 'business.inventory.operate'],
        'cashier' => ['business.sales.operate'],
        'inventory_manager' => ['business.inventory.operate'],
        'organization_manager' => ['organizations.institutional.manage'],
        'career_coordinator' => ['academic.program.coordinate'],
        'department_head' => ['academic.department.manage'],
    ];

    // Versioned financial definitions; no automatic persistence or assignments.
    public const FINANCIAL_MAPPINGS = [
        'bonus_beneficio_issuer' => ['bonuses.beneficio.issue'],
        'bonus_categoria_issuer' => ['bonuses.categoria.issue'],
        'bonus_negocio_issuer' => ['bonuses.negocio.issue'],
        'bonus_negocio_business_issuer' => ['bonuses.negocio.issue'],
        'bonus_beca_issuer' => ['bonuses.beca.issue'],
        'bonus_beca_program_issuer' => ['bonuses.beca.issue'],
        'bonus_campania_issuer' => ['bonuses.campania.issue'],
    ];

    public const REWARDS_MAPPINGS = [];

    public static function approvedPermissionsForRole(mixed $role): array
    {
        if (! is_string($role)) {
            return [];
        }

        return array_values(array_unique([
            ...(self::MAPPINGS[$role] ?? []),
            ...(self::FINANCIAL_MAPPINGS[$role] ?? []),
            ...(self::REWARDS_MAPPINGS[$role] ?? []),
        ]));
    }

    protected static function booted(): void
    {
        static::saving(function (self $mapping): void {
            Validator::make($mapping->getAttributes(), [
                'role_key' => ['required', Rule::in(Role::AUTHORIZATION_ROLES)],
                'permission_key' => ['required', Rule::in(self::approvedPermissionsForRole($mapping->role_key))],
            ])->validate();

            if (! Role::where('name', $mapping->role_key)->exists()
                || ! Permission::where('key', $mapping->permission_key)->exists()) {
                throw ValidationException::withMessages(['permission_key' => 'El rol y la capacidad deben existir en el catálogo.']);
            }
            if ($mapping->exists && $mapping->isDirty(['role_key', 'permission_key'])) {
                throw ValidationException::withMessages(['role_key' => 'El mapeo es inmutable.']);
            }
        });
    }
}
