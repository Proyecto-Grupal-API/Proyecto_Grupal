<?php

namespace Database\Seeders;

use App\Domains\Identity\Authorization\FinancialRoleCatalog as Catalog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Database\Seeder;

/** Explicit catalog-only operation. Never grants roles, memberships or reactivates permissions. */
final class FinancialAuthorizationCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Catalog::ROLES as $role => [$label, $scope]) {
            Role::firstOrCreate(['name' => $role], ['display_name' => $label, 'description' => 'Catálogo financiero v' . Catalog::VERSION]);
        }
        foreach (Catalog::PERMISSIONS as $key => [$label, $domain]) {
            Permission::firstOrCreate(['key' => $key], ['display_name' => $label, 'description' => $label, 'domain' => $domain, 'active' => true]);
        }
        foreach (Catalog::MAPPINGS as $role => $permissions) {
            foreach ($permissions as $permission) {
                RolePermission::firstOrCreate(['role_key' => $role, 'permission_key' => $permission]);
            }
        }
    }
}
