<?php

namespace App\Models;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use MongoDB\Laravel\Eloquent\Model;

class Permission extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'permissions';

    protected $fillable = ['key', 'display_name', 'description', 'domain', 'active'];

    protected $attributes = ['active' => true];

    protected $casts = ['active' => 'boolean'];

    public const CATALOG = [
        'business.manage' => ['Administrar negocio', 'business'],
        'business.members.manage' => ['Administrar integrantes del negocio', 'business'],
        'business.operate' => ['Operar negocio', 'business'],
        'business.sales.operate' => ['Operar ventas', 'business'],
        'business.inventory.operate' => ['Operar inventario', 'business'],
        'organizations.institutional.manage' => ['Gestión institucional de organizaciones', 'institutional'],
        'academic.program.coordinate' => ['Coordinar programa académico', 'institutional'],
        'academic.department.manage' => ['Gestionar departamento académico', 'institutional'],
    ];

    public const DOMAINS = ['business', 'institutional', 'financial', 'rewards', 'student_services'];

    // Definitions only, deliberately excluded from the existing foundation seeder.
    // A definition is neither a role mapping nor a grant to a user.
    public const TECHNICAL_CATALOG = [
        'refunds.review' => ['Revisar devoluciones de una wallet', 'financial'],
        'refunds.execute' => ['Ejecutar devoluciones de una wallet', 'financial'],
        'student_services.rentals.damage.charge' => ['Ordenar cargo por daños de renta', 'student_services'],
        'bonuses.beca.issue' => ['Emitir bono BECA', 'financial'],
        'bonuses.campania.issue' => ['Emitir bono CAMPANIA', 'financial'],
        'bonuses.beneficio.issue' => ['Emitir bono BENEFICIO', 'financial'],
        'bonuses.categoria.issue' => ['Emitir bono CATEGORIA', 'financial'],
        'bonuses.negocio.issue' => ['Emitir bono NEGOCIO', 'financial'],
        'rewards.program.manage' => ['Gestionar programas de recompensas', 'rewards'],
        'rewards.catalog.manage' => ['Gestionar catálogo de recompensas', 'rewards'],
        'rewards.config.approve' => ['Aprobar configuración de recompensas', 'rewards'],
        'rewards.redemption.deliver' => ['Entregar canje de recompensas', 'rewards'],
        'audit.view' => ['Consultar auditoría de Recompensas E7', 'rewards'],
        'analytics.view' => ['Consultar analítica de Recompensas E7', 'rewards'],
    ];

    public static function definitions(): array
    {
        return [...self::CATALOG, ...self::TECHNICAL_CATALOG];
    }

    protected static function booted(): void
    {
        static::saving(function (self $permission): void {
            Validator::make($permission->getAttributes(), [
                'key' => ['required', Rule::in(array_keys(self::definitions()))],
                'display_name' => ['required', 'string', 'max:150'],
                'description' => ['nullable', 'string', 'max:1000'],
                'domain' => ['required', Rule::in(self::DOMAINS)],
                'active' => ['required', 'boolean'],
            ])->validate();

            if ($permission->exists && $permission->isDirty('key')) {
                throw ValidationException::withMessages(['key' => 'La clave es inmutable.']);
            }
            if ($permission->domain !== self::definitions()[$permission->key][1]) {
                throw ValidationException::withMessages(['domain' => 'Dominio incompatible con la capacidad.']);
            }
        });
    }
}
