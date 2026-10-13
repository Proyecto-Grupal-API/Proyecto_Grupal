<?php

namespace Database\Seeders;

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
        if (! User::query()->where('email', 'test@example.com')->exists()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }

        /*
         * Servicios al Estudiante (Equipo 5). Todos los seeders son
         * idempotentes: se pueden correr varias veces sin duplicar datos.
         */
        $this->call([
            FacilitiesSeeder::class,
            LockersSeeder::class,
            RentalAssetSeeder::class,
            RestSpacesSeeder::class,
            CalendarsSeeder::class,
        ]);
    }
}
