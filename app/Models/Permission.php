<?php

namespace App\Models;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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

    protected static function booted(): void
    {
        static::saving(function (self $permission): void {
            Validator::make($permission->getAttributes(), [
                'key' => ['required', Rule::in(array_keys(self::CATALOG))],
                'display_name' => ['required', 'string', 'max:150'],
                'description' => ['nullable', 'string', 'max:1000'],
                'domain' => ['required', Rule::in(['business', 'institutional'])],
                'active' => ['required', 'boolean'],
            ])->validate();

            if ($permission->exists && $permission->isDirty('key')) {
                throw \Illuminate\Validation\ValidationException::withMessages(['key' => 'La clave es inmutable.']);
            }
            if ($permission->domain !== self::CATALOG[$permission->key][1]) {
                throw \Illuminate\Validation\ValidationException::withMessages(['domain' => 'Dominio incompatible con la capacidad.']);
            }
        });
    }
}
