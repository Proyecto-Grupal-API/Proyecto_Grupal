<?php

namespace Database\Seeders;

use App\Models\EarningRule;
use Illuminate\Database\Seeder;

class EarningRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            [
                'business_id'              => 'biz-cafeteria-central',
                'name'                     => 'Acumulación Cafetería: 1 pt por cada $10 gastados',
                'type'                     => 'amount_spent',
                'points_per_unit'          => 1,
                'unit_amount'              => 10.0,
                'fixed_points'             => null,
                'multiplier'               => 1.0,
                'max_points_per_operation' => 150,
                'daily_cap'                => 300,
                'status'                   => 'active',
                'active_from'              => now()->subMonths(2),
            ],
            [
                'business_id'              => 'biz-libreria',
                'name'                     => 'Acumulación Librería: 2 pts por cada $15 gastados',
                'type'                     => 'amount_spent',
                'points_per_unit'          => 2,
                'unit_amount'              => 15.0,
                'fixed_points'             => null,
                'multiplier'               => 1.2,
                'max_points_per_operation' => 400,
                'daily_cap'                => 800,
                'status'                   => 'active',
                'active_from'              => now()->subMonths(2),
            ],
            [
                'business_id'              => 'biz-souvenirs',
                'name'                     => 'Acumulación Souvenirs: 1 pt por cada $5',
                'type'                     => 'amount_spent',
                'points_per_unit'          => 1,
                'unit_amount'              => 5.0,
                'fixed_points'             => null,
                'multiplier'               => 1.0,
                'max_points_per_operation' => 500,
                'daily_cap'                => 1000,
                'status'                   => 'active',
                'active_from'              => now()->subMonths(2),
            ],
            [
                'business_id'              => 'biz-innovacion-hub',
                'name'                     => 'Bono Fijo por Participación en Taller Hub',
                'type'                     => 'fixed',
                'points_per_unit'          => 0,
                'unit_amount'              => 0,
                'fixed_points'             => 75,
                'multiplier'               => 1.0,
                'max_points_per_operation' => 75,
                'daily_cap'                => 150,
                'status'                   => 'active',
                'active_from'              => now()->subMonths(1),
            ],
        ];

        foreach ($rules as $r) {
            EarningRule::firstOrCreate(
                ['business_id' => $r['business_id'], 'name' => $r['name']],
                $r
            );
        }
    }
}
