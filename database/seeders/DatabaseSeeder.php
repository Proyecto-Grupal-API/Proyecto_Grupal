<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            StudentCatalogSeeder::class,
        ]);

        if (! self::allowsDemoBootstrap(app()->environment())) {
            return;
        }

        // Las cuentas con credenciales conocidas son sólo para desarrollo/pruebas.
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call([
            StudentServicesSeeder::class,
            IdentityDemoSeeder::class,
        ]);

        $user->assignRole(Role::ADMIN);
    }

    public static function allowsDemoBootstrap(string $environment): bool
    {
        return in_array($environment, ['local', 'testing'], true);
    }
}
