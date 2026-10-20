<?php

namespace Database\Seeders;

use App\Models\RewardProgramMembership;
use Illuminate\Database\Seeder;

class BusinessSeeder extends Seeder
{
    public function run(): void
    {
        $businesses = [
            [
                'business_id'        => 'biz-cafeteria-central',
                'business_name'      => 'Cafetería Central Campus',
                'category'           => 'Alimentos y Bebidas',
                'contact_email'      => 'cafeteria@campus.edu',
                'compensation_model' => 'commission',
                'status'             => 'active',
            ],
            [
                'business_id'        => 'biz-libreria',
                'business_name'      => 'Librería Universitaria',
                'category'           => 'Académico y Libros',
                'contact_email'      => 'libreria@campus.edu',
                'compensation_model' => 'fixed',
                'status'             => 'active',
            ],
            [
                'business_id'        => 'biz-souvenirs',
                'business_name'      => 'Tienda de Souvenirs Campus',
                'category'           => 'Tienda Oficial',
                'contact_email'      => 'souvenirs@campus.edu',
                'compensation_model' => 'hybrid',
                'status'             => 'active',
            ],
            [
                'business_id'        => 'biz-papeleria',
                'business_name'      => 'Centro de Copiado y Papelería',
                'category'           => 'Servicios Estudiantiles',
                'contact_email'      => 'papeleria@campus.edu',
                'compensation_model' => 'fixed',
                'status'             => 'active',
            ],
            [
                'business_id'        => 'biz-innovacion-hub',
                'business_name'      => 'Hub de Emprendimiento e Innovación',
                'category'           => 'Tecnología e Incubación',
                'contact_email'      => 'hub@campus.edu',
                'compensation_model' => 'fixed',
                'status'             => 'active',
            ],
        ];

        foreach ($businesses as $b) {
            RewardProgramMembership::firstOrCreate(
                ['business_id' => $b['business_id']],
                array_merge($b, [
                    'joined_at'         => now()->subMonths(3),
                    'accepted_terms_at' => now()->subMonths(3),
                ])
            );
        }
    }
}
