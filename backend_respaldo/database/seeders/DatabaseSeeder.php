<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeder principal del programa de recompensas (Equipo 7).
     * Se ejecuta de forma idempotente con:
     * php artisan db:seed
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            BusinessSeeder::class,
            EarningRuleSeeder::class,
            CampaignSeeder::class,
            RewardSeeder::class,
            PointsAndRedemptionsSeeder::class,
        ]);
    }
}
