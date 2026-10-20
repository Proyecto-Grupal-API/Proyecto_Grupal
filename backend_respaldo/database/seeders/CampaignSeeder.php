<?php

namespace Database\Seeders;

use App\Models\PointCampaign;
use App\Models\User;
use Illuminate\Database\Seeder;

class CampaignSeeder extends Seeder
{
    public function run(): void
    {
        $professor = User::where('role', User::ROLE_PROFESSOR)->first();

        $campaigns = [
            [
                'name'         => 'Semana de la Innovación 2026',
                'description'  => 'Doble puntaje en compras de cafetería y souvenirs para toda la comunidad universitaria.',
                'type'         => 'multiplier',
                'sponsor_type' => 'platform',
                'multiplier'   => 2.0,
                'starts_at'    => now()->subDays(5),
                'ends_at'      => now()->addDays(20),
                'status'       => 'active',
                'scope'        => 'global',
                'scope_ids'    => [],
            ],
            [
                'name'         => 'Lectura Universitaria - Feria del Libro',
                'description'  => 'Triple puntaje en la adquisición de libros académicos y material de estudio.',
                'type'         => 'multiplier',
                'sponsor_type' => 'business',
                'multiplier'   => 3.0,
                'starts_at'    => now()->subDays(2),
                'ends_at'      => now()->addDays(12),
                'status'       => 'active',
                'scope'        => 'business',
                'scope_ids'    => ['biz-libreria'],
            ],
            [
                'name'         => 'Proyecto de Desarrollo de Software Excelente',
                'description'  => 'Bono de reconocimiento académico a proyectos destacados de la facultad.',
                'type'         => 'academic',
                'sponsor_type' => 'academic',
                'sponsor_id'   => $professor ? (string) $professor->getKey() : null,
                'multiplier'   => 1.5,
                'starts_at'    => now()->subDays(10),
                'ends_at'      => now()->addDays(30),
                'status'       => 'active',
                'scope'        => 'global',
                'scope_ids'    => [],
            ],
        ];

        foreach ($campaigns as $c) {
            PointCampaign::firstOrCreate(
                ['name' => $c['name']],
                $c
            );
        }
    }
}
