<?php

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;

/** Cuentas y datos de demostración por equipo (E1–E7). Cada seeder es autosuficiente y no depende del orden. */
class DemoTeamsSeeder extends Seeder
{
    public const TEAMS = [DemoE1Seeder::class, DemoE2Seeder::class, DemoE3Seeder::class, DemoE4Seeder::class,
        DemoE5Seeder::class, DemoE6Seeder::class, DemoE7Seeder::class];

    public function run(): void
    {
        $this->call(self::TEAMS);
    }
}
