<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Database\Seeder;

/** Explicit bootstrap; does not change the default legacy/demo seeder. */
class AuthorizationFoundationSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $labels = [
            'business_owner' => 'Propietario de negocio', 'business_manager' => 'Gerente de negocio',
            'cashier' => 'Cajero', 'inventory_manager' => 'Responsable de inventario', 'buyer' => 'Comprador',
            'rewards_admin' => 'Administrador de recompensas', 'auditor' => 'Auditor',
            'organization_manager' => 'Gestor institucional de organizaciones',
            'career_coordinator' => 'Coordinador de programa académico', 'department_head' => 'Jefe de departamento',
        ];
        foreach ($labels as $key => $label) {
            Role::firstOrCreate(['name' => $key], ['display_name' => $label, 'description' => 'Catálogo foundation; no asignable mediante el RBAC legacy.']);
        }
        foreach (Permission::CATALOG as $key => [$label, $domain]) {
            Permission::firstOrCreate(['key' => $key], ['display_name' => $label, 'description' => $label, 'domain' => $domain, 'active' => true]);
        }
        foreach (RolePermission::MAPPINGS as $role => $permissions) {
            foreach ($permissions as $permission) {
                RolePermission::firstOrCreate(['role_key' => $role, 'permission_key' => $permission]);
            }
        }
    }
}
