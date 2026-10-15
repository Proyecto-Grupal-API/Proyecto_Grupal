<?php
namespace Database\Seeders;
use App\Models\User;
use App\Models\BusinessMembership;
use App\Models\RoleAssignment;
use Illuminate\Database\Seeder;

/** Explicit local E1 fixtures, never an Inventories authorization fallback. */
class InventoryIdentityDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment(['local', 'testing'])) { throw new \RuntimeException('Los permisos demo sólo pueden prepararse localmente.'); }
        $user=User::where('email', 'identidad@campus.test')->firstOrFail();
        $business=(string) config('team4.business_id');
        if (!BusinessMembership::where('user_id', (string) $user->getKey())->where('business_id', $business)->exists()) {
            BusinessMembership::create(['user_id' => (string) $user->getKey(), 'business_id' => $business,
                'status' => 'active', 'assigned_by' => (string) $user->getKey(), 'reason' => 'Fixture local explícito de la integración E4.']);
        }
        if (!RoleAssignment::where('user_id', (string) $user->getKey())->where('role_key', 'business_owner')->where('scope_type', 'business')->where('scope_id', $business)->exists()) {
            RoleAssignment::create(['user_id' => (string) $user->getKey(), 'role_key' => 'business_owner',
                'scope_type' => 'business', 'scope_id' => $business, 'status' => 'active',
                'assigned_by' => (string) $user->getKey(), 'reason' => 'Fixture local explícito de la integración E4.']);
        }
        $this->command?->info('Identidad Demo tiene una asignación explícita de propietario para el inventario de Souvenirs.');
    }
}
